"""S3-R002 synthetic pre-Controller trust seed and post-write broker derivation.

This is NOT a root launcher, signature, independent historical provenance, or
host attestation. Only a trusted harness may create/own the state and descriptors.
The caller supplies an EXTERNALLY PINNED test vector. Never learn expected digests
from Controller IPC, evidence sha256, or the source file alone.
"""
from dataclasses import dataclass
import hashlib
import os
import stat
import fcntl
from hctb_contract import canonical, valid_id, valid_sha
from root_evidence_reader import FixtureTrustAnchor, read_source
from unprivileged_controller import EvidenceDirectoryAnchor as ControllerAnchor
from root_evidence_verifier import TrustedReference
from evidence_broker import BrokerBinding, DirectoryIdentity, _evidence_check
from strict_json import strict_json_loads

MAX_MANIFEST = 4096
MANIFEST_NAME = 'trust-seed.json'

class Reject(RuntimeError): pass

@dataclass(frozen=True)
class ExternalVector:
    """Values pinned outside the synthetic run BEFORE fixture creation."""
    revision_sha256: str
    source_identity: str
    expected_source_sha256: str

@dataclass(frozen=True)
class ProvisioningInputs:
    run_id: str
    request_id: str
    operation: str
    controller_uid: int
    controller_gid: int
    verifier_uid: int
    verifier_gid: int

@dataclass(frozen=True)
class SealedSeed:
    """Root harness holds these private values, not Controller or IPC."""
    digest: str
    manifest_identity: tuple
    manifest_dir_identity: tuple
    fixture_anchor: FixtureTrustAnchor
    controller_anchor: ControllerAnchor
    reference: TrustedReference
    inputs: ProvisioningInputs
    vector: ExternalVector


def _directory(fd, uid, gid):
    try: s = os.fstat(fd)
    except OSError as e: raise Reject('directory descriptor unavailable') from e
    if (not stat.S_ISDIR(s.st_mode) or stat.S_IMODE(s.st_mode) != 0o700 or
        s.st_uid != uid or s.st_gid != gid):
        raise Reject('directory metadata')
    return (s.st_dev,s.st_ino,s.st_uid,s.st_gid,stat.S_IMODE(s.st_mode))


def _validate_inputs(inputs, vector):
    if not isinstance(inputs,ProvisioningInputs) or not isinstance(vector,ExternalVector):
        raise Reject('untrusted seed inputs')
    if not all(valid_id(x) for x in (inputs.run_id,inputs.request_id,inputs.operation,vector.source_identity)):
        raise Reject('invalid seed identity')
    if inputs.operation not in ('collect_journal','collect_recovery','collect_archive','collect_provenance'):
        raise Reject('unsupported source operation')
    if not valid_sha(vector.revision_sha256) or not valid_sha(vector.expected_source_sha256):
        raise Reject('invalid external vector')
    ids=(inputs.controller_uid,inputs.controller_gid,inputs.verifier_uid,inputs.verifier_gid)
    if any(type(x) is not int or x <= 0 for x in ids): raise Reject('invalid uid or gid')
    if inputs.verifier_uid==inputs.controller_uid: raise Reject('shared verifier/controller uid')


def _manifest_payload(inputs,vector,root_info,out_info):
    return {'schema':'educore.g2.pa001.synthetic-trust-seed.v1',
            'run_id':inputs.run_id,'request_id':inputs.request_id,'operation':inputs.operation,
            'revision_sha256':vector.revision_sha256,'source_identity':vector.source_identity,
            'expected_source_sha256':vector.expected_source_sha256,
            'controller_uid':inputs.controller_uid,'controller_gid':inputs.controller_gid,
            'verifier_uid':inputs.verifier_uid,'verifier_gid':inputs.verifier_gid,
            'root_directory':list(root_info),'controller_directory':list(out_info),
            'acceptance_authority':False,'phase_b_authority':False}


def _file_identity(s):
    return (s.st_dev,s.st_ino,s.st_uid,s.st_gid,stat.S_IMODE(s.st_mode),s.st_nlink,s.st_size,s.st_mtime_ns,s.st_ctime_ns)


def _load_pinned_manifest(dir_fd, expected_digest, expected_dir, expected_file):
    if not valid_sha(expected_digest): raise Reject('missing independent manifest pin')
    if _directory(dir_fd,expected_dir[2],expected_dir[3]) != expected_dir:
        raise Reject('manifest directory substituted')
    try: fd=os.open(MANIFEST_NAME,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC,dir_fd=dir_fd)
    except OSError as e: raise Reject('manifest unavailable') from e
    try:
        before=os.fstat(fd)
        if (_file_identity(before)!=expected_file or not stat.S_ISREG(before.st_mode) or
            stat.S_IMODE(before.st_mode)!=0o600 or before.st_nlink!=1 or not 0<before.st_size<=MAX_MANIFEST):
            raise Reject('manifest identity mismatch')
        flags=fcntl.fcntl(fd,fcntl.F_GETFL)
        if flags & os.O_ACCMODE != os.O_RDONLY or flags & getattr(os,'O_PATH',0):
            raise Reject('manifest not readable')
        parts=[];count=0
        while True:
            try: chunk=os.read(fd,min(4096,MAX_MANIFEST-count+1))
            except InterruptedError: continue
            if not chunk:break
            count+=len(chunk)
            if count>MAX_MANIFEST:raise Reject('manifest oversized')
            parts.append(chunk)
        if count!=before.st_size or _file_identity(os.fstat(fd)) != expected_file:
            raise Reject('manifest changed')
        raw=b''.join(parts)
        if hashlib.sha256(raw).hexdigest()!=expected_digest:raise Reject('manifest seal mismatch')
        try: document=strict_json_loads(raw)
        except ValueError as e:raise Reject('invalid manifest') from e
        if canonical(document)!=raw:raise Reject('manifest noncanonical')
        if _directory(dir_fd,expected_dir[2],expected_dir[3])!=expected_dir:
            raise Reject('manifest directory changed')
        return document
    finally:os.close(fd)


def seal_pre_controller(manifest_dir_fd, fixture_dir_fd, controller_dir_fd,
                        inputs,vector,*,provisioner_uid=None,provisioner_gid=None):
    """Synthetic root-harness API; only runs under separately asserted provisioner identity.

    No attempts to escalate UID. Caller owns FDs. Manifest creation is O_EXCL and
    fsynced; a failure leaves ambiguous output in place (no unsafe replay).
    """
    _validate_inputs(inputs,vector)
    if provisioner_uid is None or provisioner_gid is None:raise Reject('untrusted provisioner identity')
    if os.geteuid()!=provisioner_uid or os.getegid()!=provisioner_gid:
        raise Reject('provisioner identity mismatch')
    root=_directory(fixture_dir_fd,provisioner_uid,provisioner_gid)
    out=_directory(controller_dir_fd,inputs.controller_uid,inputs.controller_gid)
    mdir=_directory(manifest_dir_fd,provisioner_uid,provisioner_gid)
    if len({(root[0],root[1]),(out[0],out[1]),(mdir[0],mdir[1])})!=3:
        raise Reject('colliding trust directories')
    # Verify the actual private synthetic fixture against the independently pinned
    # external test vector before trusting this run. Never learn the pin from file.
    try:
        actual=read_source(fixture_dir_fd,inputs.operation,provisioner_uid,
                           trusted_root=FixtureTrustAnchor(*root[:4]))
    except (OSError,RuntimeError,ValueError) as exc:
        raise Reject('fixture validation failed') from exc
    if hashlib.sha256(actual).hexdigest()!=vector.expected_source_sha256:
        raise Reject('external fixture digest mismatch')
    payload=_manifest_payload(inputs,vector,root,out)
    raw=canonical(payload)
    if not 0<len(raw)<=MAX_MANIFEST:raise Reject('manifest oversized')
    fd=None
    try:
        fd=os.open(MANIFEST_NAME,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,0o600,dir_fd=manifest_dir_fd)
        s=os.fstat(fd)
        if (not stat.S_ISREG(s.st_mode) or s.st_uid!=provisioner_uid or
            s.st_gid!=provisioner_gid or stat.S_IMODE(s.st_mode)!=0o600 or s.st_nlink!=1):
            raise Reject('manifest create metadata mismatch')
        done=0
        while done<len(raw):
            try:n=os.write(fd,raw[done:])
            except InterruptedError:continue
            if n<=0:raise Reject('manifest write failed')
            done+=n
        os.fsync(fd)
        identity=_file_identity(os.fstat(fd))
    except OSError as e:raise Reject('manifest create failed') from e
    finally:
        if fd is not None:os.close(fd)
    os.fsync(manifest_dir_fd)
    digest=hashlib.sha256(raw).hexdigest()
    _load_pinned_manifest(manifest_dir_fd,digest,mdir,identity)
    return SealedSeed(digest,identity,mdir,
        FixtureTrustAnchor(*root[:4]), ControllerAnchor(*out[:4]),
        TrustedReference(inputs.request_id,inputs.operation,vector.source_identity,vector.expected_source_sha256),
        inputs,vector)


def verify_pre_controller(manifest_dir_fd, fixture_dir_fd, controller_dir_fd, seed):
    """Verify pinned manifest before launching Controller, from harness-held seed."""
    if not isinstance(seed,SealedSeed):raise Reject('missing sealed seed')
    document=_load_pinned_manifest(manifest_dir_fd,seed.digest,seed.manifest_dir_identity,seed.manifest_identity)
    if _directory(fixture_dir_fd,seed.fixture_anchor.uid,seed.fixture_anchor.gid)!=(
        seed.fixture_anchor.device,seed.fixture_anchor.inode,seed.fixture_anchor.uid,seed.fixture_anchor.gid,0o700):
        raise Reject('fixture anchor changed')
    if _directory(controller_dir_fd,seed.controller_anchor.uid,seed.controller_anchor.gid)!=(
        seed.controller_anchor.device,seed.controller_anchor.inode,seed.controller_anchor.uid,seed.controller_anchor.gid,0o700):
        raise Reject('controller anchor changed')
    if document!=_manifest_payload(seed.inputs,seed.vector,list(document['root_directory']),list(document['controller_directory'])):
        raise Reject('manifest fields inconsistent')
    if tuple(document['root_directory'])!=(seed.fixture_anchor.device,seed.fixture_anchor.inode,seed.fixture_anchor.uid,seed.fixture_anchor.gid,0o700):
        raise Reject('manifest fixture not pinned')
    if tuple(document['controller_directory'])!=(seed.controller_anchor.device,seed.controller_anchor.inode,seed.controller_anchor.uid,seed.controller_anchor.gid,0o700):
        raise Reject('manifest evidence not pinned')
    return seed


def finalize_broker_binding(manifest_dir_fd, fixture_dir_fd, controller_dir_fd,seed):
    """AFTER Controller has persisted evidence: pin inode without trusting its digest.

    This only derives metadata for broker. Source digest remains the prior vector.
    No passing file bytes to Controller or Verifier and no authority promotion.
    """
    verify_pre_controller(manifest_dir_fd,fixture_dir_fd,controller_dir_fd,seed)
    name='evidence-'+seed.inputs.request_id+'.json'
    try:fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC,dir_fd=controller_dir_fd)
    except OSError as e:raise Reject('evidence not ready') from e
    try:
        st=os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or st.st_uid!=seed.inputs.controller_uid or
            st.st_gid!=seed.inputs.controller_gid or stat.S_IMODE(st.st_mode)!=0o600 or
            st.st_nlink!=1 or not 0<st.st_size<=4096):raise Reject('unsafe evidence')
        before=_file_identity(st)
        # Verify content against pre-sealed expected reference, NOT evidence.sha256.
        data=[];size=0
        while True:
            chunk=os.read(fd,min(4096,4097-size))
            if not chunk:break
            size+=len(chunk)
            if size>4096:raise Reject('oversized evidence')
            data.append(chunk)
        if size!=st.st_size or _file_identity(os.fstat(fd))!=before:
            raise Reject('evidence changed during binding')
        from root_evidence_verifier import _parse_and_verify
        try:_parse_and_verify(b''.join(data),seed.reference)
        except (ValueError,RuntimeError,KeyError) as e:raise Reject('evidence does not match pinned reference') from e
        verify_pre_controller(manifest_dir_fd,fixture_dir_fd,controller_dir_fd,seed)
        return BrokerBinding(seed.inputs.run_id,seed.inputs.request_id,seed.inputs.operation,
                seed.vector.source_identity,DirectoryIdentity(seed.controller_anchor.device,
                seed.controller_anchor.inode,seed.controller_anchor.uid,seed.controller_anchor.gid),
                st.st_uid,st.st_gid,st.st_dev,st.st_ino,seed.inputs.verifier_uid,seed.inputs.verifier_gid)
    finally:os.close(fd)

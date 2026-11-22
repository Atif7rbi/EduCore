"""HCTB-002A S3-R004: isolated, fail-closed durable transfer intent journal.

This is synthetic evidence-only code. No host path, root launcher, recovery
of interrupted transfers, acceptance or Phase B authority. Journal dir FD and
identity are independently provisioned by the harness, never Controller IPC.
"""
from dataclasses import dataclass
from contextlib import contextmanager
import errno
import fcntl
import hashlib
import os
import stat
import threading
import json
from hctb_contract import canonical, valid_id
from evidence_broker import _check_binding, _peer, Reject as BrokerReject

class Reject(RuntimeError):
    pass

_TRANSACTION_LOCK = threading.RLock()


@contextmanager
def _trusted_transaction_boundary(journal_dir_fd):
    """Serialize the full trusted transaction on the pinned journal FD.

    The process-local lock protects the synthetic authority object; flock on
    the validated directory descriptor extends the exclusion boundary to
    cooperating processes sharing the same trusted journal.  Failure to take
    either lock is a refusal, never a retryable alternate send path.
    """
    with _TRANSACTION_LOCK:
        try:
            fcntl.flock(journal_dir_fd, fcntl.LOCK_EX)
        except OSError as exc:
            raise Reject('trusted transaction lock unavailable') from exc
        try:
            yield
        finally:
            try:
                fcntl.flock(journal_dir_fd, fcntl.LOCK_UN)
            except OSError:
                # The transaction is already terminal; do not conceal a
                # transfer result by trying an alternate path.
                pass

@dataclass(frozen=True)
class JournalAnchor:
    device: int
    inode: int
    uid: int
    gid: int
    mode: int = 0o700

@dataclass(frozen=True)
class DurableIntent:
    name: str
    bytes: bytes
    digest: str
    sequence: int
    operation_identity: str


def _check_directory(fd, anchor):
    if type(fd) is not int or not isinstance(anchor, JournalAnchor):
        raise Reject('trusted journal descriptor and anchor required')
    if anchor.mode != 0o700 or os.geteuid() != anchor.uid or os.getegid() != anchor.gid:
        raise Reject('journal owner does not match process identity')
    try:
        st = os.fstat(fd)
        flags = fcntl.fcntl(fd, fcntl.F_GETFL)
    except OSError as exc:
        raise Reject('journal descriptor unavailable') from exc
    actual = (st.st_dev, st.st_ino, st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode))
    expected = (anchor.device, anchor.inode, anchor.uid, anchor.gid, anchor.mode)
    if (not stat.S_ISDIR(st.st_mode) or actual != expected or
        flags & getattr(os,'O_PATH',0) or (flags & os.O_ACCMODE) != os.O_RDONLY):
        raise Reject('journal directory identity or descriptor mismatch')
    return st


def _key(binding):
    _check_binding(binding)
    # Exact run/request key, not caller-controlled filename.
    if not valid_id(binding.run_id) or not valid_id(binding.request_id):
        raise Reject('invalid operation identity')
    return 'transfer-' + hashlib.sha256(canonical([binding.run_id, binding.request_id])).hexdigest() + '.intent'


def _read_pinned_intent(journal_dir_fd, anchor, name):
    """Read one journal INTENT only through the pinned directory descriptor."""
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC,
                     dir_fd=journal_dir_fd)
    except OSError as exc:
        raise Reject('journal intent unavailable') from exc
    try:
        st = os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or st.st_uid != anchor.uid or st.st_gid != anchor.gid or
            stat.S_IMODE(st.st_mode) != 0o600 or st.st_nlink != 1 or not 0 < st.st_size <= 1024):
            raise Reject('unsafe journal intent metadata')
        chunks=[]; remaining=st.st_size
        while remaining:
            try: chunk=os.read(fd, min(1024, remaining))
            except InterruptedError: continue
            if not chunk: raise Reject('truncated journal intent')
            chunks.append(chunk); remaining -= len(chunk)
        raw=b''.join(chunks)
        after=os.fstat(fd)
        if (after.st_dev,after.st_ino,after.st_uid,after.st_gid,stat.S_IMODE(after.st_mode),after.st_nlink,after.st_size) != (st.st_dev,st.st_ino,st.st_uid,st.st_gid,stat.S_IMODE(st.st_mode),st.st_nlink,st.st_size):
            raise Reject('journal intent changed during read')
        item=json.loads(raw.decode('utf-8'))
        if canonical(item) != raw:
            raise Reject('noncanonical journal intent')
        return raw, item
    except (UnicodeDecodeError, ValueError, TypeError) as exc:
        raise Reject('invalid journal intent') from exc
    finally:
        os.close(fd)


def _reconcile_journal_taa(journal_dir_fd, anchor, taa, read_capability):
    """Fail closed unless pinned journal files exactly equal the TAA chain."""
    _check_directory(journal_dir_fd, anchor)
    head = taa.recover(read_capability)
    snapshot = taa.snapshot()
    intents = snapshot.get('intents') if isinstance(snapshot, dict) else None
    if not isinstance(intents, dict):
        raise Reject('trusted authority snapshot unavailable')
    expected = {}
    for operation, raw in intents.items():
        if not isinstance(raw, bytes): raise Reject('authority intent type mismatch')
        try:
            item=json.loads(raw.decode('utf-8'))
            if canonical(item) != raw or item.get('operation_identity') != operation:
                raise Reject('authority intent identity mismatch')
            name='transfer-' + hashlib.sha256(canonical([item['run_id'], item['request_id']])).hexdigest() + '.intent'
        except (KeyError, UnicodeDecodeError, ValueError, TypeError) as exc:
            raise Reject('invalid authority intent') from exc
        if name in expected: raise Reject('duplicate authority journal identity')
        expected[name] = raw
    try:
        names = set(os.listdir(journal_dir_fd))
    except OSError as exc:
        raise Reject('journal enumeration unavailable') from exc
    if names != set(expected):
        raise Reject('journal and authority divergence')
    for name, raw in expected.items():
        observed, item = _read_pinned_intent(journal_dir_fd, anchor, name)
        if observed != raw or item.get('operation_identity') not in intents:
            raise Reject('journal authority substitution')
    return head


def claim_transfer(journal_dir_fd, anchor, binding, *, sequence, operation_identity, after_file_sync=None):
    """Create permanent one-shot INTENT and fsync file + directory BEFORE send.

    A crash or uncertain fsync always requires independent manual reconciliation;
    pre-existing intent is never reused, unlinked, completed or auto-retried.
    The accepted Broker sender itself enforces this journal; no bypass exists.
    """
    name = _key(binding)
    _check_directory(journal_dir_fd, anchor)
    if type(sequence) is not int or sequence < 1 or operation_identity != binding.request_id:
        raise Reject('sequence-bearing operation identity required')
    content = canonical({
        'schema': 'educore.hctb002a.transfer-intent.v1',
        'run_id': binding.run_id, 'request_id': binding.request_id,
        'operation': binding.operation,
        'source_identity': binding.source_identity,
        'evidence_device': binding.evidence_device,
        'evidence_inode': binding.evidence_inode,
        'sequence': sequence, 'operation_identity': operation_identity,
        'acceptance_authority': False, 'phase_b_authority': False,
        'status': 'INTENT_NO_RETRY',
    })
    if len(content) > 1024:
        raise Reject('journal entry too large')
    try:
        fd = os.open(name, os.O_CREAT | os.O_EXCL | os.O_WRONLY |
                     os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=journal_dir_fd)
    except FileExistsError as exc:
        raise Reject('durable replay refused') from exc
    except OSError as exc:
        raise Reject('journal intent creation failed') from exc
    try:
        st = os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or st.st_uid != anchor.uid or
            st.st_gid != anchor.gid or stat.S_IMODE(st.st_mode) != 0o600 or st.st_nlink != 1):
            raise Reject('unsafe journal entry')
        pos = 0
        while pos < len(content):
            try:
                n = os.write(fd, content[pos:])
            except InterruptedError:
                continue
            if n <= 0:
                raise Reject('short journal write')
            pos += n
        os.fsync(fd)
        if after_file_sync is not None:
            after_file_sync()
        os.fsync(journal_dir_fd)
        _check_directory(journal_dir_fd, anchor)
        # CAS provenance is exclusively the descriptor-relative readback, not
        # the in-memory buffer that was passed to os.write().
        rfd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=journal_dir_fd)
        try:
            rst=os.fstat(rfd)
            metadata=(rst.st_dev,rst.st_ino,rst.st_uid,rst.st_gid,stat.S_IMODE(rst.st_mode),rst.st_nlink,rst.st_size)
            if (not stat.S_ISREG(rst.st_mode) or rst.st_uid!=anchor.uid or rst.st_gid!=anchor.gid or
                stat.S_IMODE(rst.st_mode)!=0o600 or rst.st_nlink!=1 or rst.st_size!=len(content)):
                raise Reject('persisted intent metadata mismatch')
            chunks=[]
            remaining=rst.st_size
            while remaining:
                try: chunk=os.read(rfd,min(1024,remaining))
                except InterruptedError: continue
                if not chunk: raise Reject('persisted intent short read')
                chunks.append(chunk); remaining-=len(chunk)
            actual=b''.join(chunks)
            after=os.fstat(rfd)
            after_metadata=(after.st_dev,after.st_ino,after.st_uid,after.st_gid,stat.S_IMODE(after.st_mode),after.st_nlink,after.st_size)
            if after_metadata != metadata or actual != content:
                raise Reject('persisted intent verification failed')
            item=json.loads(actual.decode('utf-8'))
            if (canonical(item) != actual or item.get('operation_identity') != operation_identity or
                item.get('sequence') != sequence):
                raise Reject('persisted intent identity mismatch')
        except (UnicodeDecodeError, ValueError, TypeError) as exc:
            raise Reject('persisted intent canonicality failure') from exc
        finally: os.close(rfd)
        digest=hashlib.sha256(actual).hexdigest()
        return DurableIntent(name,actual,digest,sequence,operation_identity)
    except OSError as exc:
        raise Reject('journal intent not durably established') from exc
    finally:
        os.close(fd)


def trusted_writer_commit_and_authorize_transfer(conn, binding, *, journal_dir_fd, journal_anchor, taa, update_capability, read_capability):
    """No-sendmsg trusted transaction. Caller must be harness-owned."""
    with _trusted_transaction_boundary(journal_dir_fd):
      _check_directory(journal_dir_fd,journal_anchor)
      pid,uid,gid=_peer(conn)
      if pid <= 0 or (uid,gid)!=(binding.verifier_uid,binding.verifier_gid):
        raise Reject('unauthorized verifier peer')
      try:
        head=_reconcile_journal_taa(journal_dir_fd, journal_anchor, taa, read_capability)
        sequence=head.sequence+1
        intent=claim_transfer(journal_dir_fd,journal_anchor,binding,sequence=sequence,operation_identity=binding.request_id)
        taa.persist_intent(update_capability,binding.request_id,intent.bytes)
        anchor_module=__import__('journal_anchor')
        successor=anchor_module.next_head(head,json.loads(intent.bytes.decode())).head_digest
        receipt=taa.compare_and_advance(update_capability,head,binding.request_id,intent.digest,successor)
        payload=anchor_module.SyntheticTrustedAnchorAuthority.verify_receipt(receipt,taa._key)
        verified_head=taa.get_head(read_capability)
        if (payload.get('operation_identity') != binding.request_id or
            payload.get('intent_digest') != intent.digest or
            payload.get('expected_sequence') != head.sequence or
            payload.get('expected_head_digest') != head.head_digest or
            payload.get('resulting_sequence') != intent.sequence or
            payload.get('resulting_head_digest') != verified_head.head_digest or
            verified_head.sequence != intent.sequence):
            raise Reject('verified receipt transaction mismatch')
        from evidence_broker import _register_verified_transaction, issue_harness_authorization
        proof = _register_verified_transaction(binding, journal_anchor, intent, head, receipt,
                                               taa=taa, read_capability=read_capability)
        return issue_harness_authorization(proof, conn)
      except Exception as exc:
        raise Reject(str(exc)) from exc

def send_pinned_evidence_durable(conn, evidence_dir_fd, binding, *, authorization):
    """Broker-only FD transfer entrypoint with fail-closed durable replay protection.

    Does not permit a second path through the legacy non-durable sender.
    Harness integration must enforce exclusive use of this entrypoint.
    """
    try:
        _check_binding(binding)
        pid, uid, gid = _peer(conn)
        if pid <= 0 or (uid, gid) != (binding.verifier_uid, binding.verifier_gid):
            raise Reject('unauthorized verifier peer')
    except BrokerReject as exc:
        raise Reject('invalid authenticated broker request') from exc
    from evidence_broker import send_pinned_evidence
    return send_pinned_evidence(conn, evidence_dir_fd, binding, authorization=authorization)

"""HCTB-002A S2-R3 isolated verifier; no Root access, trust minting or authority issuance.

TrustedReference and EvidenceDirectoryAnchor must be provisioned independently by the
future S3 harness. Constructing them from an IPC response is *not* trusted provisioning.
The verifier validates one immutable, typed evidence record by descriptor-relative read.
"""
from dataclasses import dataclass
import hashlib
import json
import os
import stat
import fcntl
from strict_json import strict_json_loads, DuplicateJSONKey
from hctb_contract import (SCHEMA, OPERATIONS, canonical, valid_id, valid_sha,
                           validate_evidence_only_response)

MAX_EVIDENCE_SIZE = 4096

class Reject(RuntimeError):
    pass

@dataclass(frozen=True)
class EvidenceDirectoryAnchor:
    device: int
    inode: int
    uid: int
    gid: int
    mode: int = 0o700

@dataclass(frozen=True)
class TrustedReference:
    """Provisioned out-of-band; includes independently expected source digest."""
    request_id: str
    operation: str
    source_identity: str
    expected_sha256: str
    outcome: str = 'PASS'


def verify_dir_fd(dir_fd, anchor):
    if type(dir_fd) is not int or not isinstance(anchor, EvidenceDirectoryAnchor) or anchor.mode != 0o700:
        raise Reject('untrusted evidence directory')
    try:
        st = os.fstat(dir_fd)
    except OSError as exc:
        raise Reject('unavailable evidence directory') from exc
    actual = (st.st_dev, st.st_ino, st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode))
    trusted = (anchor.device, anchor.inode, anchor.uid, anchor.gid, anchor.mode)
    if not stat.S_ISDIR(st.st_mode) or actual != trusted:
        raise Reject('evidence directory trust mismatch')
    return st


def _unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise Reject('duplicate json key')
        value[key] = item
    return value


def _parse_and_verify(data, reference):
    try:
        result = json.loads(data.decode('utf-8'), object_pairs_hook=_unique_object,
                            parse_constant=lambda _: (_ for _ in ()).throw(Reject('json constant')))
    except DuplicateJSONKey as exc:
        raise Reject('duplicate json key') from exc
    except (UnicodeError, ValueError, TypeError) as exc:
        raise Reject('invalid evidence json') from exc
    expected_request = {'schema': SCHEMA, 'request_id': reference.request_id, 'operation': reference.operation}
    try:
        validate_evidence_only_response(expected_request, result)
    except (ValueError, TypeError, KeyError) as exc:
        raise Reject('invalid evidence contract') from exc
    # Reject alternate JSON representation, even if its parsed object is equivalent.
    if canonical(result) != data:
        raise Reject('noncanonical evidence')
    if result['outcome'] != reference.outcome:
        raise Reject('unexpected outcome')
    e = result['evidence']
    if e['source_identity'] != reference.source_identity:
        raise Reject('source identity mismatch')
    if e['sha256'] != reference.expected_sha256:
        raise Reject('independent digest mismatch')
    if reference.outcome == 'PASS' and e['verification_status'] != 'VERIFIED':
        raise Reject('verification status mismatch')
    return result


def verify_evidence(dir_fd, anchor, reference, *, read_fn=os.read):
    """Validate one evidence record; nothing is written or promoted to acceptance."""
    if not isinstance(reference, TrustedReference):
        raise Reject('missing independent reference')
    if (not valid_id(reference.request_id) or reference.operation not in OPERATIONS or
        not valid_id(reference.source_identity) or not valid_sha(reference.expected_sha256) or
        reference.outcome != 'PASS'):
        # Non-PASS collection results are not interpreted as successful verification.
        raise Reject('invalid trusted reference')
    verify_dir_fd(dir_fd, anchor)
    filename = 'evidence-' + reference.request_id + '.json'
    try:
        fd = os.open(filename, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=dir_fd)
    except OSError as exc:
        raise Reject('evidence missing or unsafe') from exc
    try:
        try:
            before = os.fstat(fd)
        except OSError as exc:
            raise Reject('evidence fstat failed') from exc
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != anchor.uid or
            before.st_gid != anchor.gid or stat.S_IMODE(before.st_mode) != 0o600 or
            before.st_nlink != 1 or before.st_size > MAX_EVIDENCE_SIZE):
            raise Reject('unsafe evidence metadata')
        identity = (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns,
                    before.st_uid, before.st_gid, before.st_mode, before.st_nlink)
        chunks = []
        total = 0
        while True:
            try:
                piece = read_fn(fd, min(8192, MAX_EVIDENCE_SIZE - total + 1))
            except InterruptedError:
                continue
            except OSError as exc:
                raise Reject('evidence read failed') from exc
            if not isinstance(piece, bytes):
                raise Reject('invalid read')
            if not piece:
                break
            total += len(piece)
            if total > MAX_EVIDENCE_SIZE:
                raise Reject('oversize evidence')
            chunks.append(piece)
        after = os.fstat(fd)
        actual = (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns,
                  after.st_uid, after.st_gid, after.st_mode, after.st_nlink)
        if total != before.st_size or actual != identity:
            raise Reject('modified evidence')
        verify_dir_fd(dir_fd, anchor)
        result = _parse_and_verify(b''.join(chunks), reference)
        verify_dir_fd(dir_fd, anchor)
        return result
    finally:
        os.close(fd)


def verify_received_file_fd(fd, binding, reference, *, read_fn=os.read):
    """Consume exactly one broker-received evidence FD, including on failure.

    No directory lookup is performed. The binding and reference MUST be provided
    independently by a trusted S3 provisioner; this API cannot mint provenance.
    The caller must not reuse or close the consumed FD afterward.
    """
    from evidence_broker import BrokerBinding, _check_binding, _evidence_check
    if type(fd) is not int or fd < 0:
        raise Reject('missing evidence file descriptor')
    try:
        if not isinstance(binding, BrokerBinding) or not isinstance(reference, TrustedReference):
            raise Reject('missing trusted binding or reference')
        try:
            _check_binding(binding)
        except Exception as exc:
            raise Reject('invalid broker binding') from exc
        if (reference.request_id != binding.request_id or
            reference.operation != binding.operation or
            reference.source_identity != binding.source_identity or
            reference.outcome != 'PASS' or
            not valid_sha(reference.expected_sha256)):
            raise Reject('broker/reference identity mismatch')
        try:
            before = _evidence_check(fd, binding)
        except (OSError, RuntimeError) as exc:
            raise Reject('unsafe evidence descriptor') from exc
        # On Linux, O_PATH must not be mistaken for O_RDONLY.
        try:
            flags = fcntl.fcntl(fd, fcntl.F_GETFL)
            if (flags & os.O_ACCMODE) != os.O_RDONLY or flags & getattr(os, 'O_PATH', 0):
                raise Reject('non-readable or writable evidence descriptor')
            os.lseek(fd, 0, os.SEEK_SET)
        except OSError as exc:
            raise Reject('invalid evidence descriptor offset') from exc
        identity = (before.st_dev, before.st_ino, before.st_uid,
                    before.st_gid, before.st_mode, before.st_nlink,
                    before.st_size, before.st_mtime_ns, before.st_ctime_ns)
        chunks = []
        total = 0
        while True:
            try:
                part = read_fn(fd, min(8192, MAX_EVIDENCE_SIZE - total + 1))
            except InterruptedError:
                continue
            except OSError as exc:
                raise Reject('evidence read failed') from exc
            if not isinstance(part, bytes):
                raise Reject('invalid evidence read')
            if not part:
                break
            total += len(part)
            if total > MAX_EVIDENCE_SIZE:
                raise Reject('evidence oversize')
            chunks.append(part)
        try:
            after = _evidence_check(fd, binding)
        except (OSError, RuntimeError) as exc:
            raise Reject('unsafe evidence descriptor after read') from exc
        later = (after.st_dev, after.st_ino, after.st_uid,
                 after.st_gid, after.st_mode, after.st_nlink,
                 after.st_size, after.st_mtime_ns, after.st_ctime_ns)
        if total != before.st_size or identity != later:
            raise Reject('modified evidence')
        return _parse_and_verify(b''.join(chunks), reference)
    finally:
        try:
            os.close(fd)
        except OSError as exc:
            # A pre-closed caller FD is rejected above. Never mask that rejection.
            if exc.errno != 9:
                raise

"""HCTB-002A S3-R004: isolated, fail-closed durable transfer intent journal.

This is synthetic evidence-only code. No host path, root launcher, recovery
of interrupted transfers, acceptance or Phase B authority. Journal dir FD and
identity are independently provisioned by the harness, never Controller IPC.
"""
from dataclasses import dataclass
import errno
import fcntl
import hashlib
import os
import stat
from hctb_contract import canonical, valid_id
from evidence_broker import _check_binding, _peer, Reject as BrokerReject

class Reject(RuntimeError):
    pass

@dataclass(frozen=True)
class JournalAnchor:
    device: int
    inode: int
    uid: int
    gid: int
    mode: int = 0o700


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


def claim_transfer(journal_dir_fd, anchor, binding, *, after_file_sync=None):
    """Create permanent one-shot INTENT and fsync file + directory BEFORE send.

    A crash or uncertain fsync always requires independent manual reconciliation;
    pre-existing intent is never reused, unlinked, completed or auto-retried.
    The accepted Broker sender itself enforces this journal; no bypass exists.
    """
    name = _key(binding)
    _check_directory(journal_dir_fd, anchor)
    content = canonical({
        'schema': 'educore.hctb002a.transfer-intent.v1',
        'run_id': binding.run_id, 'request_id': binding.request_id,
        'operation': binding.operation,
        'source_identity': binding.source_identity,
        'evidence_device': binding.evidence_device,
        'evidence_inode': binding.evidence_inode,
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
        return hashlib.sha256(content).hexdigest()
    except OSError as exc:
        raise Reject('journal intent not durably established') from exc
    finally:
        os.close(fd)


def send_pinned_evidence_durable(conn, evidence_dir_fd, binding, *, journal_dir_fd, journal_anchor):
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
    # Compatibility wrapper delegates to the same mandatory enforcement in Broker.
    # It cannot claim twice or select a non-durable path.
    from evidence_broker import send_pinned_evidence
    return send_pinned_evidence(conn, evidence_dir_fd, binding,
                                journal_dir_fd=journal_dir_fd, journal_anchor=journal_anchor)

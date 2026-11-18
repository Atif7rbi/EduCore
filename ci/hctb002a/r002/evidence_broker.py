"""HCTB-002A S3-R001 isolated evidence FD broker prototype.

NOT a privileged launcher, trust provisioner, verifier API, or host authorization.
Binding must arrive independently from a future S3 provisioner, never IPC.
"""
from dataclasses import dataclass
import array
import fcntl
import os
import socket
import stat

from hctb_contract import OPERATIONS, valid_id

MAX_EVIDENCE = 4096
MAX_BINDING = 256

class Reject(RuntimeError):
    pass

@dataclass(frozen=True)
class DirectoryIdentity:
    device: int
    inode: int
    uid: int
    gid: int
    mode: int = 0o700

@dataclass(frozen=True)
class BrokerBinding:
    run_id: str
    request_id: str
    operation: str
    source_identity: str
    directory: DirectoryIdentity
    evidence_uid: int
    evidence_gid: int
    # Pinned out-of-band by S3; these are NOT discovered from controller's message.
    evidence_device: int
    evidence_inode: int
    verifier_uid: int
    verifier_gid: int


def _check_binding(binding):
    if not isinstance(binding, BrokerBinding) or not isinstance(binding.directory, DirectoryIdentity):
        raise Reject('missing trusted binding')
    if not all(valid_id(x) for x in (binding.run_id, binding.request_id, binding.source_identity)):
        raise Reject('invalid trusted binding')
    if binding.operation not in OPERATIONS or binding.directory.mode != 0o700:
        raise Reject('invalid operation or directory mode')
    for value in (binding.evidence_uid, binding.evidence_gid, binding.evidence_device,
                  binding.evidence_inode, binding.verifier_uid, binding.verifier_gid):
        if type(value) is not int or value < 0:
            raise Reject('invalid trusted identity')
    return b'EDUCORE-BROKER-FD-V1:' + binding.run_id.encode() + b':' + binding.request_id.encode() + b':' + binding.operation.encode()


def _directory_check(dir_fd, binding):
    if type(dir_fd) is not int:
        raise Reject('directory descriptor required')
    try:
        st = os.fstat(dir_fd)
    except OSError as exc:
        raise Reject('directory inaccessible') from exc
    expected = binding.directory
    if not stat.S_ISDIR(st.st_mode) or (
        st.st_dev, st.st_ino, st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode)
    ) != (expected.device, expected.inode, expected.uid, expected.gid, expected.mode):
        raise Reject('untrusted directory')


def _evidence_check(fd, binding):
    try:
        st = os.fstat(fd)
        flags = fcntl.fcntl(fd, fcntl.F_GETFL)
    except OSError as exc:
        raise Reject('invalid evidence descriptor') from exc
    if (not stat.S_ISREG(st.st_mode) or (flags & os.O_ACCMODE) != os.O_RDONLY or
        bool(flags & getattr(os, 'O_PATH', 0)) or st.st_uid != binding.evidence_uid or
        st.st_gid != binding.evidence_gid or stat.S_IMODE(st.st_mode) != 0o600 or
        st.st_nlink != 1 or not 0 < st.st_size <= MAX_EVIDENCE or
        (st.st_dev, st.st_ino) != (binding.evidence_device, binding.evidence_inode)):
        raise Reject('evidence descriptor mismatch')
    return st


def _peer(conn):
    import struct
    try:
        result = conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize('3i'))
        return struct.unpack('3i', result)
    except (OSError, ValueError) as exc:
        raise Reject('peer credentials unavailable') from exc


def send_pinned_evidence(conn, evidence_directory_fd, binding, *, used_ids):
    """One-shot send; `used_ids` is a harness-owned mutable set, not client input.

    The caller must launch/own a dedicated SOCK_SEQPACKET endpoint and close it.
    A successful transfer consumes the run/request pair; failure also consumes it.
    """
    payload = _check_binding(binding)
    if len(payload) > MAX_BINDING:
        raise Reject('binding too large')
    if conn.type & socket.SOCK_SEQPACKET != socket.SOCK_SEQPACKET:
        raise Reject('wrong socket type')
    pid, uid, gid = _peer(conn)
    if pid <= 0 or (uid, gid) != (binding.verifier_uid, binding.verifier_gid):
        raise Reject('unauthorized verifier peer')
    key = (binding.run_id, binding.request_id)
    if key in used_ids:
        raise Reject('repeated transfer')
    used_ids.add(key)  # fail closed: no retry after ambiguous transfer
    _directory_check(evidence_directory_fd, binding)
    filename = 'evidence-' + binding.request_id + '.json'
    fd = None
    try:
        try:
            fd = os.open(filename, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                         dir_fd=evidence_directory_fd)
        except OSError as exc:
            raise Reject('unsafe or missing evidence file') from exc
        _evidence_check(fd, binding)
        _directory_check(evidence_directory_fd, binding)
        fds = array.array('i', [fd])
        try:
            count = conn.sendmsg([payload], [(socket.SOL_SOCKET, socket.SCM_RIGHTS, fds)])
        except OSError as exc:
            raise Reject('descriptor transfer failure') from exc
        if count != len(payload):
            raise Reject('partial descriptor transfer')
        conn.shutdown(socket.SHUT_WR)
    finally:
        if fd is not None:
            os.close(fd)


def receive_pinned_evidence(conn, binding, *, broker_uid=0):
    """Receive exact one FD; recipient owns returned FD and must close it.

    This API is intentionally source-only. Caller must authenticate a separately
    provisioned binding and pass the broker's expected kernel UID, normally 0.
    """
    payload = _check_binding(binding)
    if len(payload) > MAX_BINDING:
        raise Reject('binding too large')
    if conn.type & socket.SOCK_SEQPACKET != socket.SOCK_SEQPACKET:
        raise Reject('wrong socket type')
    pid, uid, _ = _peer(conn)
    if pid <= 0 or uid != broker_uid:
        raise Reject('unauthorized broker peer')
    fd = None
    all_fds = []
    try:
        item_size = array.array('i').itemsize
        body, ancillary, flags, _ = conn.recvmsg(MAX_BINDING + 1,
                          socket.CMSG_SPACE(item_size * 4), socket.MSG_CMSG_CLOEXEC)
        for level, typ, data in ancillary:
            if level == socket.SOL_SOCKET and typ == socket.SCM_RIGHTS:
                if len(data) % item_size != 0:
                    raise Reject('invalid ancillary alignment')
                numbers = array.array('i')
                numbers.frombytes(data)
                all_fds.extend(numbers)
        if flags & (socket.MSG_CTRUNC | socket.MSG_TRUNC):
            raise Reject('truncated packet or ancillary')
        if len(ancillary) != 1 or ancillary[0][0:2] != (socket.SOL_SOCKET, socket.SCM_RIGHTS):
            raise Reject('unexpected ancillary')
        if len(all_fds) != 1 or body != payload:
            raise Reject('descriptor count or binding mismatch')
        fd = all_fds[0]
        _evidence_check(fd, binding)
        if not fcntl.fcntl(fd, fcntl.F_GETFD) & fcntl.FD_CLOEXEC:
            raise Reject('missing close-on-exec')
        # Server must half-close after exactly one reply; no second packet.
        tail = conn.recv(1)
        if tail:
            raise Reject('unexpected second packet')
        all_fds.remove(fd)
        return fd
    except (OSError, TimeoutError) as exc:
        raise Reject('receive failure') from exc
    finally:
        for leftover in all_fds:
            try: os.close(leftover)
            except OSError: pass

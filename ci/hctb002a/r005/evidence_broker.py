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
import threading

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

class TrustedTransferAuthorization:
    def __init__(self, binding, journal_anchor, intent, predecessor, receipt):
        self.binding=binding; self.journal_anchor=journal_anchor; self.intent=intent
        self.predecessor=predecessor; self.receipt=receipt

_HARNESS_ISSUANCE_TOKEN = object()
_WRITER_PROOF_TOKEN = object()


class _VerifiedTransactionProof:
    """One-use, module-registered result of a verified trusted transaction.

    This is deliberately a Track-A synthetic boundary.  Its private Python
    identity is not represented as cross-process or OS isolation.
    """
    __slots__ = ('binding', 'journal_anchor', 'intent', 'predecessor', 'receipt')
    def __init__(self, binding, journal_anchor, intent, predecessor, receipt):
        self.binding = binding; self.journal_anchor = journal_anchor
        self.intent = intent; self.predecessor = predecessor; self.receipt = receipt


class _VerifiedTransactionRegistry:
    def __init__(self): self._lock = threading.Lock(); self._pending = {}
    def register(self, token, binding, journal_anchor, intent, predecessor, receipt):
        if token is not _WRITER_PROOF_TOKEN:
            raise Reject('untrusted transaction proof registration')
        proof = _VerifiedTransactionProof(binding, journal_anchor, intent, predecessor, receipt)
        with self._lock: self._pending[id(proof)] = proof
        return proof
    def consume(self, proof):
        with self._lock:
            if not isinstance(proof, _VerifiedTransactionProof) or self._pending.pop(id(proof), None) is not proof:
                raise Reject('unverified or consumed transaction proof')
            return proof


_VERIFIED_TRANSACTIONS = _VerifiedTransactionRegistry()

class TrustedAuthorizationRegistry:
    """Harness-owned Track-A issuance/one-shot registry; never IPC input."""
    def __init__(self): self._lock=threading.Lock(); self._issued={}
    def issue(self, issuance_token, binding, journal_anchor, intent, predecessor, receipt, conn):
        # This is the last synthetic issuance boundary before the Broker.  It
        # accepts only the transaction object produced by the trusted writer;
        # caller-selected registries and caller supplied values never enter
        # send_pinned_evidence().
        if issuance_token is not _HARNESS_ISSUANCE_TOKEN:
            raise Reject('unauthorized authorization issuance')
        payload = receipt.get('payload') if isinstance(receipt, dict) else None
        if (not isinstance(payload, dict) or
            payload.get('operation_identity') != binding.request_id or
            payload.get('intent_digest') != intent.digest or
            payload.get('expected_sequence') != predecessor.sequence or
            payload.get('expected_head_digest') != predecessor.head_digest or
            payload.get('resulting_sequence') != intent.sequence or
            type(payload.get('resulting_head_digest')) is not str):
            raise Reject('receipt does not bind trusted transaction')
        auth=TrustedTransferAuthorization(binding,journal_anchor,intent,predecessor,receipt)
        with self._lock: self._issued[id(auth)]=auth
        return auth
    def consume(self,auth,binding,peer):
        with self._lock:
            if not isinstance(auth,TrustedTransferAuthorization) or self._issued.pop(id(auth),None) is not auth:
                raise Reject('unissued or consumed authorization')
            if auth.binding != binding or auth.intent.operation_identity != binding.request_id or auth.intent.sequence != auth.predecessor.sequence+1:
                raise Reject('authorization binding mismatch')
            if peer[1:] != (binding.verifier_uid,binding.verifier_gid): raise Reject('authorization peer mismatch')
            return auth

_HARNESS_REGISTRY = TrustedAuthorizationRegistry()

def harness_authorization_registry():
    """Synthetic harness-only issuance authority; send callers cannot choose it."""
    return _HARNESS_REGISTRY


def _register_verified_transaction(binding, journal_anchor, intent, predecessor, receipt, *, taa, read_capability):
    """Register only a transaction independently proven complete by its TAA.

    This check deliberately repeats completion verification instead of trusting
    the calling function or private naming.  In the synthetic same-process
    model a hostile component able to extract TAA internals is outside the
    model; this is not claimed as OS/process isolation.
    """
    try:
        receipt_payload = taa.__class__.verify_receipt(receipt, taa._key)
        current = taa.get_head(read_capability)
        snapshot = taa.snapshot()
        persisted = snapshot['intents'].get(binding.request_id)
    except Exception as exc:
        raise Reject('unverified transaction completion') from exc
    if (persisted != intent.bytes or
        receipt_payload.get('operation_identity') != binding.request_id or
        receipt_payload.get('intent_digest') != intent.digest or
        receipt_payload.get('expected_sequence') != predecessor.sequence or
        receipt_payload.get('expected_head_digest') != predecessor.head_digest or
        receipt_payload.get('resulting_sequence') != intent.sequence or
        receipt_payload.get('resulting_head_digest') != current.head_digest or
        current.sequence != intent.sequence):
        raise Reject('transaction completion binding mismatch')
    return _VERIFIED_TRANSACTIONS.register(_WRITER_PROOF_TOKEN, binding, journal_anchor,
                                           intent, predecessor, receipt)


def issue_harness_authorization(proof, conn):
    """Issue only from one verified, writer-owned transaction proof.

    Direct helper calls with a receipt or caller-built object are rejected;
    the proof is consumed before an authorization is inserted into the
    module-owned registry.  Python object identity remains a synthetic
    Track-A boundary, not an operating-system authorization primitive.
    """
    verified = _VERIFIED_TRANSACTIONS.consume(proof)
    return _HARNESS_REGISTRY.issue(_HARNESS_ISSUANCE_TOKEN, verified.binding,
                                   verified.journal_anchor, verified.intent,
                                   verified.predecessor, verified.receipt, conn)


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


def send_pinned_evidence(conn, evidence_directory_fd, binding, *, authorization=None, **legacy):
    """Mandatory durable one-shot send; no legacy in-memory-only transfer route.

    A separately trusted harness MUST supply the journal FD and its pinned anchor.
    Every authorized transfer is consumed on disk before opening/sending evidence.
    Passing only the legacy `used_ids` parameter cannot bypass the journal.
    """
    payload = _check_binding(binding)
    if len(payload) > MAX_BINDING:
        raise Reject('binding too large')
    if conn.type & socket.SOCK_SEQPACKET != socket.SOCK_SEQPACKET:
        raise Reject('wrong socket type')
    pid, uid, gid = _peer(conn)
    if pid <= 0 or (uid, gid) != (binding.verifier_uid, binding.verifier_gid):
        raise Reject('unauthorized verifier peer')
    if legacy:
        raise Reject('legacy sender arguments rejected')
    # The registry is deliberately not a parameter.  Supplying a registry lets
    # an ordinary sender decide what constitutes issuance, which defeats the
    # harness-only authority boundary.  Only the module-private harness
    # registry can consume an authorization.
    _HARNESS_REGISTRY.consume(authorization,binding,(pid,uid,gid))
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

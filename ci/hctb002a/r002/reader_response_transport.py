"""Synthetic one-request/one-response Reader socket adapter, without Root authority.

A trusted harness owns sockets, root_fd, fixture trust anchor, and expected UID.
There is no listener, privilege escalation, trust enrollment or host-path lookup.
"""
import socket
import struct
from hctb_contract import canonical, validate_evidence_only_response
from root_evidence_reader import handle, MAX_MESSAGE

class Reject(RuntimeError):
    pass


def serve_once(conn, controller_uid, root_fd, reader_uid, *, trusted_root):
    """One accepted synthetic connection, one typed response. Caller closes socket.

    Reader.handle validates SO_PEERCRED, framing, operation, and source before
    returning a typed record. On any failure no response is emitted.
    """
    if trusted_root is None:
        raise Reject('missing trusted fixture anchor')
    response = handle(conn, controller_uid, root_fd, reader_uid, trusted_root=trusted_root)
    # Handle already validates; independently recheck before writing bytes.
    request = {k: response[k] for k in ('schema', 'request_id', 'operation')}
    validate_evidence_only_response(request, response)
    body = canonical(response)
    if not 0 < len(body) <= MAX_MESSAGE:
        raise Reject('response size')
    frame = struct.pack('!I', len(body)) + body
    try:
        conn.sendall(frame)
        conn.shutdown(socket.SHUT_WR)
    except (OSError, TimeoutError, socket.timeout) as exc:
        raise Reject('response send failed') from exc
    return response

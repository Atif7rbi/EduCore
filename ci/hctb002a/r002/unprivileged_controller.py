"""Synthetic S2-R2 Controller; never opens Root evidence or grants authority.

The socket response framing is a PROPOSAL for the future Reader send lifecycle,
not a claim of Reader interoperability. Trust anchors are supplied by an
independent future provisioner, never derived from IPC or user-selected paths.
"""
from dataclasses import dataclass
import json
import os
import socket
import stat
import struct
import time

from strict_json import strict_json_loads, DuplicateJSONKey
from hctb_contract import (SCHEMA, OPERATIONS, canonical, valid_id,
                           validate_evidence_only_response)

MAX_MESSAGE = 4096
TOTAL_DEADLINE = 2.0

class Reject(RuntimeError):
    pass

@dataclass(frozen=True)
class EvidenceDirectoryAnchor:
    device: int
    inode: int
    uid: int
    gid: int
    mode: int = 0o700


def request_for(request_id, operation):
    if not valid_id(request_id) or operation not in OPERATIONS:
        raise Reject('invalid request')
    return {'schema': SCHEMA, 'request_id': request_id, 'operation': operation}


def _remaining(deadline):
    value = deadline - time.monotonic()
    if value <= 0:
        raise Reject('deadline')
    return value


def _recv_exact(conn, count, deadline):
    chunks = []
    while count:
        conn.settimeout(_remaining(deadline))
        try:
            piece = conn.recv(count)
        except InterruptedError:
            continue
        except (TimeoutError, socket.timeout) as exc:
            raise Reject('deadline') from exc
        if not piece:
            raise Reject('truncated frame')
        chunks.append(piece)
        count -= len(piece)
    return b''.join(chunks)


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise Reject('duplicate json key')
        result[key] = value
    return result


def receive_response(conn, request, deadline):
    size = struct.unpack('!I', _recv_exact(conn, 4, deadline))[0]
    if not 0 < size <= MAX_MESSAGE:
        raise Reject('invalid frame size')
    body = _recv_exact(conn, size, deadline)
    conn.settimeout(_remaining(deadline))
    try:
        more = conn.recv(1)
    except InterruptedError:
        # Retrying is necessary; fall through to the bounded loop below.
        more = None
    except (TimeoutError, socket.timeout) as exc:
        raise Reject('deadline') from exc
    while more is None:
        conn.settimeout(_remaining(deadline))
        try:
            more = conn.recv(1)
        except InterruptedError:
            continue
        except (TimeoutError, socket.timeout) as exc:
            raise Reject('deadline') from exc
    if more:
        raise Reject('trailing response bytes')
    try:
        value = strict_json_loads(body)
    except DuplicateJSONKey as exc:
        raise Reject('duplicate json key') from exc
    except (ValueError, UnicodeError) as exc:
        raise Reject('invalid response json') from exc
    return validate_evidence_only_response(request, value)


def exchange(conn, request):
    """One connection, one request; caller owns and closes the socket."""
    if not isinstance(request, dict) or set(request) != {'schema', 'request_id', 'operation'}:
        raise Reject('invalid request fields')
    expected = request_for(request['request_id'], request['operation'])
    if request != expected:
        raise Reject('invalid request schema')
    body = canonical(expected)
    if not 0 < len(body) <= MAX_MESSAGE:
        raise Reject('request oversize')
    deadline = time.monotonic() + TOTAL_DEADLINE
    frame = struct.pack('!I', len(body)) + body
    # sendall with a monotonically decreasing timeout; a single sendall uses
    # an OS-level socket timeout. This source-only contract is tested locally.
    conn.settimeout(_remaining(deadline))
    try:
        conn.sendall(frame)
        conn.shutdown(socket.SHUT_WR)
    except (OSError, TimeoutError) as exc:
        raise Reject('request send failed') from exc
    return receive_response(conn, expected, deadline)


def verify_evidence_directory(dir_fd, anchor):
    if type(dir_fd) is not int or not isinstance(anchor, EvidenceDirectoryAnchor):
        raise Reject('untrusted evidence directory')
    try:
        meta = os.fstat(dir_fd)
    except OSError as exc:
        raise Reject('evidence directory unavailable') from exc
    current = (meta.st_dev, meta.st_ino, meta.st_uid,
               meta.st_gid, stat.S_IMODE(meta.st_mode))
    approved = (anchor.device, anchor.inode, anchor.uid, anchor.gid, anchor.mode)
    if not stat.S_ISDIR(meta.st_mode) or anchor.mode != 0o700 or current != approved:
        raise Reject('evidence directory mismatch')
    # Ownership is checked against the effective identity, not caller-supplied UID.
    if meta.st_uid != os.geteuid():
        raise Reject('evidence directory owner mismatch')
    return meta


def persist_evidence(dir_fd, anchor, request, response):
    """Write only typed evidence into a verified directory, without overwrite.

    Pre-existing evidence and unsafe destination paths always fail closed.
    A file that fails durable completion remains in place for inspection rather
    than being deleted unsafely after an ambiguous write.
    """
    if not isinstance(request, dict) or set(request) != {"schema", "request_id", "operation"}:
        raise Reject("invalid request fields")
    expected = request_for(request["request_id"], request["operation"])
    if request != expected:
        raise Reject("invalid request schema")
    result = validate_evidence_only_response(expected, response)
    verify_evidence_directory(dir_fd, anchor)
    name = 'evidence-' + request['request_id'] + '.json'
    if not valid_id(request['request_id']):
        raise Reject('invalid evidence identity')
    content = canonical(result)
    if len(content) > MAX_MESSAGE:
        raise Reject('evidence oversize')
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW
    try:
        fd = os.open(name, flags, 0o600, dir_fd=dir_fd)
    except OSError as exc:
        raise Reject('evidence create refused') from exc
    try:
        meta = os.fstat(fd)
        if not stat.S_ISREG(meta.st_mode) or meta.st_uid != anchor.uid or meta.st_gid != anchor.gid or stat.S_IMODE(meta.st_mode) != 0o600 or meta.st_nlink != 1:
            raise Reject('evidence file metadata')
        offset = 0
        while offset < len(content):
            try:
                size = os.write(fd, content[offset:])
            except InterruptedError:
                continue
            if size <= 0:
                raise Reject('evidence short write')
            offset += size
        os.fsync(fd)
        verify_evidence_directory(dir_fd, anchor)
    finally:
        os.close(fd)
    os.fsync(dir_fd)
    return name

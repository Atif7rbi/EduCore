"""Synthetic Root Evidence Reader source; fixture-only and evidence-only."""
import json,os,stat,socket,struct,time
from dataclasses import dataclass
from hctb_contract import validate_request, validate_evidence_only_response, SCHEMA, OPERATIONS
from strict_json import strict_json_loads
MAX_MESSAGE=4096
MAX_SOURCE_SIZE=65536
SOURCES={"collect_journal":"journal","collect_recovery":"recovery","collect_archive":"archive","collect_provenance":"provenance"}
SUPPORTED_OPERATIONS=frozenset(SOURCES)
UNSUPPORTED_OPERATIONS=OPERATIONS-SUPPORTED_OPERATIONS
if SUPPORTED_OPERATIONS|UNSUPPORTED_OPERATIONS != OPERATIONS: raise RuntimeError("operation mapping mismatch")
class Reject(RuntimeError):pass

@dataclass(frozen=True)
class FixtureTrustAnchor:
    # Must originate in independent, trusted fixture provisioning, NOT a request.
    device: int
    inode: int
    uid: int
    gid: int
    mode: int = 0o700


def verify_root_fd(root_fd, anchor):
    if type(root_fd) is not int or not isinstance(anchor, FixtureTrustAnchor):
        raise Reject("untrusted root descriptor")
    try:
        st = os.fstat(root_fd)
    except OSError as exc:
        raise Reject("root descriptor unavailable") from exc
    if (not stat.S_ISDIR(st.st_mode) or
        (st.st_dev, st.st_ino, st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode)) !=
        (anchor.device, anchor.inode, anchor.uid, anchor.gid, anchor.mode)):
        raise Reject("root descriptor trust mismatch")
    return st


def read_source(root_fd,name,uid,mode=0o600,read_fn=os.read,*,trusted_root=None):
 if name in UNSUPPORTED_OPERATIONS:raise Reject("operation unsupported")
 if name not in SUPPORTED_OPERATIONS or "/" in name or ".." in name:raise Reject("allowlist")
 verify_root_fd(root_fd, trusted_root)
 if uid != trusted_root.uid:raise Reject("file owner mismatch")
 try: fd=os.open(SOURCES[name],os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=root_fd)
 except OSError as e:raise Reject("open") from e
 try:
  st=os.fstat(fd)
  if not stat.S_ISREG(st.st_mode) or st.st_uid!=trusted_root.uid or st.st_gid!=trusted_root.gid or stat.S_IMODE(st.st_mode)!=mode or st.st_nlink!=1 or st.st_size>MAX_SOURCE_SIZE:raise Reject("metadata")
  before=(st.st_dev,st.st_ino,st.st_size,st.st_mtime_ns,st.st_ctime_ns)
  chunks=[]; total=0
  while True:
   try:b=read_fn(fd,min(8192,MAX_SOURCE_SIZE-total+1))
   except InterruptedError:continue
   if not b:break
   total+=len(b)
   if total>MAX_SOURCE_SIZE:raise Reject("oversize")
   chunks.append(b)
  after=os.fstat(fd)
  if total!=before[2] or (after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns)!=before:raise Reject("source changed")
  verify_root_fd(root_fd, trusted_root)
  return b"".join(chunks)
 finally:os.close(fd)
def peer_uid(conn):
 raw=conn.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,struct.calcsize("3i"))
 return struct.unpack("3i",raw)[1]
# Single request per stream. Caller sends uint32 big-endian length, then JSON,
# then shutdown(SHUT_WR). No request is processed until EOF is confirmed.
IPC_TOTAL_DEADLINE_SECONDS=2.0

def _recv_exact(conn, size, deadline):
 chunks=[]
 while size:
  remaining=deadline-time.monotonic()
  if remaining<=0:raise Reject("deadline")
  conn.settimeout(remaining)
  try:
   chunk=conn.recv(size)
  except InterruptedError:
   continue
  except (TimeoutError,socket.timeout) as exc:
   raise Reject("deadline") from exc
  if not chunk:raise Reject("truncated frame")
  chunks.append(chunk)
  size-=len(chunk)
 return b"".join(chunks)

def recv_frame(conn,deadline):
 header=_recv_exact(conn,4,deadline)
 size=struct.unpack("!I",header)[0]
 if not 0<size<=MAX_MESSAGE:raise Reject("frame size")
 payload=_recv_exact(conn,size,deadline)
 # One-request-per-connection requires the sender to half-close. Any extra
 # bytes, even the prefix of another frame, are forbidden.
 while True:
  remaining=deadline-time.monotonic()
  if remaining<=0:raise Reject("deadline")
  conn.settimeout(remaining)
  try: trailing=conn.recv(1)
  except InterruptedError:continue
  except (TimeoutError,socket.timeout) as exc:raise Reject("deadline") from exc
  if trailing:raise Reject("trailing data")
  return payload

def handle(conn,controller_uid,root_fd,reader_uid,*,trusted_root=None):
 # Kernel peer identity must be checked *before* reading client-supplied bytes.
 if peer_uid(conn)!=controller_uid:raise Reject("peer")
 deadline=time.monotonic()+IPC_TOTAL_DEADLINE_SECONDS
 raw=recv_frame(conn,deadline)
 try:q=strict_json_loads(raw)
 except (UnicodeError,ValueError) as e:raise Reject("json") from e
 q=validate_request(controller_uid,controller_uid,q); data=read_source(root_fd,q["operation"],reader_uid,trusted_root=trusted_root)
 r={"schema":SCHEMA,"request_id":q["request_id"],"operation":q["operation"],"outcome":"PASS","evidence":{"source_identity":"synthetic."+q["operation"],"sha256":__import__("hashlib").sha256(data).hexdigest(),"verification_status":"VERIFIED"},"reasons":[],"acceptance_authority":False,"phase_b_authority":False}
 return validate_evidence_only_response(q,r)

"""Synthetic IPC contract only: no host collector or socket server."""
import hashlib,json,re
SCHEMA="educore.g2.pa001.host-collector-ipc.v1"
CONTROLLER="educore-uat-reconcile"; IPC_DIRECTORY="root:educore-uat-reconcile 0750"; SOCKET_MODE="0660"
OPERATIONS=frozenset({"collect_identity","collect_journal","collect_recovery","collect_archive","collect_retained_metadata","collect_apparmor","collect_runtime","collect_provenance","collect_phase_b_absence","collect_lock_evidence"})
REASONS=frozenset({"SOURCE_UNAVAILABLE","PERMISSION_DENIED","DIGEST_MISMATCH","PROVENANCE_UNVERIFIABLE","CONTRADICTORY_EVIDENCE","UNSAFE_METADATA","PHASE_B_ARTIFACT_PRESENT"})
ID=re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$"); SHA=re.compile(r"^[0-9a-f]{64}$")
class Reject(ValueError):pass
def canonical(x):return json.dumps(x,sort_keys=True,separators=(",",":"),ensure_ascii=True).encode()
def digest(x):return hashlib.sha256(canonical(x)).hexdigest()
def valid_id(x):return isinstance(x,str) and bool(ID.fullmatch(x)) and not any(ord(c)<32 for c in x)
def valid_sha(x):return isinstance(x,str) and bool(SHA.fullmatch(x))
def validate_request(peer_uid,controller_uid,r):
 if peer_uid!=controller_uid:raise Reject("unauthorized peer")
 if not isinstance(r,dict) or set(r)!={"schema","request_id","operation"}:raise Reject("fixed request schema")
 if r.get("schema")!=SCHEMA or not valid_id(r.get("request_id")) or r.get("operation") not in OPERATIONS:raise Reject("invalid request")
 return r
def validate_evidence_only_response(req,r):
 if not isinstance(r,dict) or set(r)!={"schema","request_id","operation","outcome","evidence","reasons","acceptance_authority","phase_b_authority"}:raise Reject("fixed response schema")
 if r.get("schema")!=SCHEMA or r.get("request_id")!=req["request_id"] or r.get("operation")!=req["operation"]:raise Reject("request response mismatch")
 if r.get("outcome") not in {"PASS","BLOCKED","NOT_TESTED"} or r.get("acceptance_authority") is not False or r.get("phase_b_authority") is not False:raise Reject("authority or outcome")
 e=r.get("evidence")
 if not isinstance(e,dict) or set(e)!={"source_identity","sha256","verification_status"} or not valid_id(e.get("source_identity")) or not valid_sha(e.get("sha256")) or e.get("verification_status") not in {"VERIFIED","REJECTED","UNAVAILABLE"}:raise Reject("typed evidence")
 reasons=r.get("reasons")
 if not isinstance(reasons,list) or len(reasons)>8 or any(not isinstance(x,str) or x not in REASONS for x in reasons):raise Reject("typed reasons")
 if (r["outcome"]=="PASS" and (reasons or e["verification_status"]!="VERIFIED")) or (r["outcome"]!="PASS" and not reasons):raise Reject("contradictory or incomplete")
 return r

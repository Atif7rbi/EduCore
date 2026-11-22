"""Synthetic R005 anchored journal primitives; no host launcher or authority."""
from dataclasses import dataclass
import hashlib, hmac, json, threading

class Reject(RuntimeError): pass

def _pairs(pairs):
    obj = {}
    for key, value in pairs:
        if key in obj: raise Reject("duplicate JSON key")
        obj[key] = value
    return obj

def _canonical(value):
    try:
        return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False,
                          allow_nan=False).encode("utf-8")
    except (TypeError, ValueError) as exc:
        raise Reject("noncanonical value") from exc

GENESIS_KEYS = frozenset(("schema","journal_id","source_revision","fixture_identity","genesis_format_version"))
HEAD_KEYS = frozenset(("schema","journal_id","genesis_digest","sequence","head_digest"))

def genesis_bytes(fields):
    if not isinstance(fields, dict) or set(fields) != GENESIS_KEYS:
        raise Reject("exact genesis fields required")
    if any(not isinstance(fields[k], str) or not fields[k] for k in GENESIS_KEYS):
        raise Reject("invalid genesis field")
    return _canonical(fields)

def genesis_digest(fields):
    return hashlib.sha256(genesis_bytes(fields)).hexdigest()

@dataclass(frozen=True)
class Head:
    journal_id: str
    genesis_digest: str
    sequence: int
    head_digest: str

    def bytes(self):
        if not isinstance(self.sequence, int) or self.sequence < 0:
            raise Reject("invalid sequence")
        data={"schema":"educore.hctb002a.journal-head.v1","journal_id":self.journal_id,
              "genesis_digest":self.genesis_digest,"sequence":self.sequence,"head_digest":self.head_digest}
        if not all(isinstance(v,str) and len(v)==64 and all(c in "0123456789abcdef" for c in v)
                   for v in (data["genesis_digest"],data["head_digest"])):
            raise Reject("invalid digest")
        return _canonical(data)

def initial_head(fields):
    g=genesis_digest(fields)
    return Head(fields["journal_id"],g,0,g)

def intent_digest(intent):
    return hashlib.sha256(_canonical(intent)).hexdigest()

def next_head(current, intent):
    if not isinstance(current, Head): raise Reject("trusted head required")
    i=intent_digest(intent)
    n=current.sequence+1
    h=hashlib.sha256(_canonical({"journal_id":current.journal_id,"genesis_digest":current.genesis_digest,
                                 "sequence":n,"previous":current.head_digest,"intent_digest":i})).hexdigest()
    return Head(current.journal_id,current.genesis_digest,n,h)

class SyntheticAnchorStore:
    """Test-only CAS model. Caller must hold the external exclusive lock."""
    def __init__(self, head): self._head=head
    def read(self): return self._head
    def advance(self, expected, intent):
        if self._head != expected: raise Reject("stale or conflicting head")
        new=next_head(expected,intent)
        self._head=new
        return new

class UncertainCommit(Reject):
    """The caller must read state and verify a receipt; it must not retry CAS."""

def _receipt_payload(head, operation_identity, intent_digest_value, key_version):
    return {"schema":"educore.hctb002a.synthetic-receipt.v1",
            "journal_id":head.journal_id, "genesis_digest":head.genesis_digest,
            "operation_identity":operation_identity, "intent_digest":intent_digest_value,
            "sequence":head.sequence, "head_digest":head.head_digest,
            "authority_key_version":key_version}

@dataclass(frozen=True)
class Capability:
    role: str
    token: object

class SyntheticTrustedAnchorAuthority:
    """Deterministic Track-A authority; protocol evidence, never external trust."""
    def __init__(self, verification_key, state=None):
        if not isinstance(verification_key, bytes) or not verification_key:
            raise Reject("synthetic verification key required")
        self._key=verification_key; self._lock=threading.RLock(); self._available=True
        self._genesis=None; self._head=None; self._intents={}; self._receipts={}
        self._capabilities={r:Capability(r,object()) for r in ("provision","update","read")}
        if state is not None: self._restore(state)
    def set_available(self, value): self._available=bool(value)
    def _check_available(self):
        if not self._available: raise Reject("authority unavailable")
    def _require(self, cap, role):
        if cap is not self._capabilities[role]: raise Reject("unauthorized authority capability")
    def register_genesis(self, cap, fields):
        self._require(cap,"provision")
        blob=genesis_bytes(fields); digest=hashlib.sha256(blob).hexdigest()
        with self._lock:
            self._check_available()
            if self._genesis is not None:
                if self._genesis != blob: raise Reject("conflicting genesis")
                return initial_head(fields)
            self._genesis=blob; self._head=initial_head(fields); return self._head
    def persist_intent(self, cap, operation_identity, canonical_intent_bytes):
        self._require(cap,"update")
        if not isinstance(operation_identity,str) or not operation_identity: raise Reject("operation identity required")
        if not isinstance(canonical_intent_bytes,bytes) or not canonical_intent_bytes: raise Reject("canonical intent bytes required")
        try:
            parsed=json.loads(canonical_intent_bytes.decode("utf-8"), object_pairs_hook=_pairs)
        except Exception as exc: raise Reject("invalid persisted intent") from exc
        if _canonical(parsed)!=canonical_intent_bytes: raise Reject("noncanonical persisted intent")
        digest=hashlib.sha256(canonical_intent_bytes).hexdigest()
        with self._lock:
            self._check_available()
            old=self._intents.get(operation_identity)
            if old is not None and old != canonical_intent_bytes: raise Reject("substituted intent")
            self._intents[operation_identity]=canonical_intent_bytes
        return digest
    def get_head(self, cap):
        self._require(cap,"read")
        with self._lock:
            self._check_available()
            if self._head is None: raise Reject("genesis missing")
            return self._head
    def compare_and_advance(self, cap, expected, operation_identity, canonical_intent_digest, supplied_successor, *, uncertain_after_commit=False):
        self._require(cap,"update")
        with self._lock:
            self._check_available()
            if self._head is None or expected != self._head: raise Reject("stale or conflicting predecessor")
            raw=self._intents.get(operation_identity)
            if raw is None: raise Reject("persisted intent missing")
            actual=hashlib.sha256(raw).hexdigest()
            if actual != canonical_intent_digest: raise Reject("intent digest mismatch")
            item=json.loads(raw.decode("utf-8"), object_pairs_hook=_pairs)
            if item.get("operation_identity") != operation_identity or item.get("sequence") != expected.sequence+1: raise Reject("intent identity or sequence mismatch")
            derived=next_head(expected, item)
            if supplied_successor != derived.head_digest: raise Reject("forged successor")
            self._head=derived
            request={"journal_id":expected.journal_id,"genesis_digest":expected.genesis_digest,"operation_identity":operation_identity,"intent_digest":actual,"expected_sequence":expected.sequence,"expected_head_digest":expected.head_digest,"resulting_sequence":derived.sequence,"resulting_head_digest":derived.head_digest,"authority_key_version":"synthetic-v1"}
            request_digest=hashlib.sha256(_canonical(request)).hexdigest()
            receipt_payload=_receipt_payload(derived,operation_identity,actual,"synthetic-v1")
            receipt_payload.update({"request_digest":request_digest,"expected_sequence":expected.sequence,"expected_head_digest":expected.head_digest,"resulting_sequence":derived.sequence,"resulting_head_digest":derived.head_digest})
            payload=_canonical(receipt_payload)
            sig=hmac.new(self._key,payload,hashlib.sha256).hexdigest()
            receipt={"payload":json.loads(payload.decode("utf-8")),"signature":sig}
            self._receipts[operation_identity]=receipt
            if uncertain_after_commit: raise UncertainCommit("CAS outcome uncertain; read and verify")
            return receipt
    @staticmethod
    def verify_receipt(receipt, verification_key):
        if not isinstance(receipt,dict) or set(receipt)!={"payload","signature"}: raise Reject("malformed receipt")
        payload=receipt["payload"]
        required={"schema","journal_id","genesis_digest","operation_identity","intent_digest","sequence","head_digest","authority_key_version","request_digest","expected_sequence","expected_head_digest","resulting_sequence","resulting_head_digest"}
        if not isinstance(payload,dict) or set(payload)!=required: raise Reject("receipt fields")
        request={"journal_id":payload["journal_id"],"genesis_digest":payload["genesis_digest"],"operation_identity":payload["operation_identity"],"intent_digest":payload["intent_digest"],"expected_sequence":payload["expected_sequence"],"expected_head_digest":payload["expected_head_digest"],"resulting_sequence":payload["resulting_sequence"],"resulting_head_digest":payload["resulting_head_digest"],"authority_key_version":payload["authority_key_version"]}
        if payload["request_digest"] != hashlib.sha256(_canonical(request)).hexdigest() or payload["sequence"] != payload["resulting_sequence"] or payload["head_digest"] != payload["resulting_head_digest"]: raise Reject("receipt binding")
        expected=hmac.new(verification_key,_canonical(payload),hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected,receipt["signature"]): raise Reject("receipt signature")
        return payload
    def recover(self, cap):
        self._require(cap,"read")
        with self._lock:
            self._check_available()
            if self._genesis is None or self._head is None: raise Reject("authority state missing")
            fields=json.loads(self._genesis.decode("utf-8"), object_pairs_hook=_pairs); current=initial_head(fields)
            ordered=[]
            for op, raw in self._intents.items():
                item=json.loads(raw.decode("utf-8"), object_pairs_hook=_pairs)
                if not isinstance(item,dict) or item.get("operation_identity") != op or "sequence" not in item: raise Reject("intent identity missing")
                ordered.append((item["sequence"],op,item))
            for seq,op,item in sorted(ordered):
                if seq != current.sequence+1: raise Reject("intent sequence conflict")
                predecessor=current
                current=next_head(current,item)
                receipt=self._receipts.get(op)
                p=SyntheticTrustedAnchorAuthority.verify_receipt(receipt,self._key) if receipt else None
                actual=hashlib.sha256(self._intents[op]).hexdigest()
                request={"journal_id":predecessor.journal_id,"genesis_digest":predecessor.genesis_digest,"operation_identity":op,"intent_digest":actual,"expected_sequence":predecessor.sequence,"expected_head_digest":predecessor.head_digest,"resulting_sequence":current.sequence,"resulting_head_digest":current.head_digest,"authority_key_version":"synthetic-v1"}
                if p is None or p["journal_id"]!=predecessor.journal_id or p["genesis_digest"]!=predecessor.genesis_digest or p["head_digest"]!=current.head_digest or p["expected_sequence"]!=predecessor.sequence or p["expected_head_digest"]!=predecessor.head_digest or p["resulting_sequence"]!=current.sequence or p["resulting_head_digest"]!=current.head_digest or p["operation_identity"]!=op or p["intent_digest"]!=actual or p["authority_key_version"]!="synthetic-v1" or p["request_digest"]!=hashlib.sha256(_canonical(request)).hexdigest(): raise Reject("receipt conflict")
            if current != self._head: raise Reject("rollback divergence")
            return current
    def snapshot(self):
        with self._lock:
            return {"genesis":self._genesis,"head":self._head,"intents":dict(self._intents),"receipts":dict(self._receipts)}
    def _restore(self, state):
        if not isinstance(state,dict) or set(state)!={"genesis","head","intents","receipts"}: raise Reject("invalid persistent state")
        self._genesis=state["genesis"]; self._head=state["head"]; self._intents=dict(state["intents"]); self._receipts=dict(state["receipts"])

def provision_synthetic_authority(verification_key, state=None):
    """Harness-only issuance boundary; the TAA object exposes no capability getter."""
    authority=SyntheticTrustedAnchorAuthority(verification_key,state)
    caps=authority._capabilities
    return authority, (caps["provision"],caps["update"],caps["read"])


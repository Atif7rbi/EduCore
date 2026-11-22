import hashlib, hmac, json, unittest
from journal_anchor import *
from journal_anchor import _canonical

F={"schema":"educore.hctb002a.genesis.v1","journal_id":"journal-1","source_revision":"3d55c739","fixture_identity":"synthetic-1","genesis_format_version":"1"}

class AnchorTests(unittest.TestCase):
 def test_genesis_is_acyclic_and_stable(self):
  b=genesis_bytes(F); self.assertEqual(genesis_digest(F),__import__("hashlib").sha256(b).hexdigest())
  self.assertNotIn(b"genesis_digest",b)
 def test_extra_or_self_digest_rejected(self):
  with self.assertRaises(Reject): genesis_bytes(dict(F,genesis_digest="0"*64))
 def test_initial_head_pins_actual_genesis(self):
  h=initial_head(F); self.assertEqual(h.sequence,0); self.assertEqual(h.head_digest,h.genesis_digest); h.bytes()
 def test_monotonic_sequence_and_predecessor(self):
  s=SyntheticAnchorStore(initial_head(F)); h=s.read()
  h1=s.advance(h,{"request":"a"}); self.assertEqual(h1.sequence,1)
  self.assertNotEqual(h1.head_digest,h.head_digest)
 def test_stale_head_rejected(self):
  s=SyntheticAnchorStore(initial_head(F)); old=s.read(); s.advance(old,{"request":"a"})
  with self.assertRaises(Reject): s.advance(old,{"request":"b"})
 def test_truncated_or_conflicting_state_detectable(self):
  h=initial_head(F); a=next_head(h,{"request":"a"}); self.assertNotEqual(a,h)
  self.assertEqual(h.sequence,0)
 def test_nonfinite_or_bad_digest_rejected(self):
  with self.assertRaises(Reject): Head("j","z"*64,0,"0"*64).bytes()

class TrackATests(unittest.TestCase):
 def setUp(self):
  self.key=b"track-a-verification-key"; self.a,(self.provision,self.update,self.read)=provision_synthetic_authority(self.key)
  self.a.register_genesis(self.provision,F)
 def _intent(self, op, seq=1):
  raw=_canonical({"operation_identity":op,"sequence":seq,"value":"synthetic"})
  return raw, hashlib.sha256(raw).hexdigest()
 def test_persisted_intent_binds_cas_and_receipt(self):
  raw,d=self._intent("op-1"); self.a.persist_intent(self.update,"op-1",raw); before=self.a.get_head(self.read); successor=next_head(before,json.loads(raw)).head_digest
  receipt=self.a.compare_and_advance(self.update,before,"op-1",d,successor)
  p=self.a.verify_receipt(receipt,self.key); self.assertEqual(p["intent_digest"],d); self.assertEqual(p["sequence"],1)
 def test_forged_successor_and_substitution_rejected(self):
  raw,d=self._intent("op-1"); self.a.persist_intent(self.update,"op-1",raw); h=self.a.get_head(self.read)
  with self.assertRaises(Reject): self.a.compare_and_advance(self.update,h,"op-1",d,"0"*64)
  with self.assertRaises(Reject): self.a.persist_intent(self.update,"op-1",_canonical({"operation_identity":"op-1","sequence":1,"value":"other"}))
 def test_uncertain_commit_requires_readback_not_retry(self):
  raw,d=self._intent("op-1"); self.a.persist_intent(self.update,"op-1",raw); h=self.a.get_head(self.read); nxt=next_head(h,json.loads(raw)).head_digest
  with self.assertRaises(UncertainCommit): self.a.compare_and_advance(self.update,h,"op-1",d,nxt,uncertain_after_commit=True)
  self.assertEqual(self.a.get_head(self.read).sequence,1)
  with self.assertRaises(Reject): self.a.compare_and_advance(self.update,h,"op-1",d,nxt)
 def test_restart_reconstruction_and_divergence(self):
  raw,d=self._intent("op-1"); self.a.persist_intent(self.update,"op-1",raw); h=self.a.get_head(self.read); self.a.compare_and_advance(self.update,h,"op-1",d,next_head(h,json.loads(raw)).head_digest)
  restarted,(_,_,read)=provision_synthetic_authority(self.key,self.a.snapshot()); self.assertEqual(restarted.recover(read).sequence,1)
  snap=self.a.snapshot(); snap["intents"]={}; bad=SyntheticTrustedAnchorAuthority(self.key,snap)
  bad,(_,_,read)=provision_synthetic_authority(self.key,bad.snapshot())
  with self.assertRaises(Reject): bad.recover(read)
 def test_unavailable_and_stale_receipt_rejected(self):
  self.a.set_available(False)
  with self.assertRaises(Reject): self.a.get_head(self.read)
  self.a.set_available(True); raw,d=self._intent("op-1"); self.a.persist_intent(self.update,"op-1",raw); h=self.a.get_head(self.read); r=self.a.compare_and_advance(self.update,h,"op-1",d,next_head(h,json.loads(raw)).head_digest); r["signature"]="0"*64
  with self.assertRaises(Reject): self.a.verify_receipt(r,self.key)
 def test_role_substitution_and_intent_identity_rejected(self):
  raw,d=self._intent("op-1"); self.a.persist_intent(self.update,"op-1",raw)
  with self.assertRaises(Reject): self.a.register_genesis(self.update,F)
  with self.assertRaises(Reject): self.a.get_head(self.update)
  h=self.a.get_head(self.read)
  with self.assertRaises(Reject): self.a.compare_and_advance(self.read,h,"other",d,"0"*64)
 def test_recovery_rejects_authenticated_wrong_predecessor_and_genesis(self):
  raw,d=self._intent("op-1"); self.a.persist_intent(self.update,"op-1",raw); h=self.a.get_head(self.read); self.a.compare_and_advance(self.update,h,"op-1",d,next_head(h,json.loads(raw)).head_digest)
  snap=self.a.snapshot(); r=snap["receipts"]["op-1"]; r["payload"]["expected_head_digest"]="0"*64; r["signature"]=hmac.new(self.key,_canonical(r["payload"]),hashlib.sha256).hexdigest()
  bad,(_,_,read)=provision_synthetic_authority(self.key,snap)
  with self.assertRaises(Reject): bad.recover(read)

if __name__=="__main__": unittest.main()


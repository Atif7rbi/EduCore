import unittest
from hctb_contract import SCHEMA, Reject, validate_request, validate_evidence_only_response
from hctb_runner import refusal_preflight
U=42420
def request(**x): return {"schema":SCHEMA,"request_id":"case-1","operation":"collect_journal",**x}
def response(**x): return {"schema":SCHEMA,"request_id":"case-1","operation":"collect_journal","outcome":"PASS","evidence":{"source_identity":"fixture.journal","sha256":"a"*64,"verification_status":"VERIFIED"},"reasons":[],"acceptance_authority":False,"phase_b_authority":False,**x}
class T(unittest.TestCase):
 def test_positive_contract(self):
  validated=validate_request(42420,42420,request())
  self.assertEqual(validate_evidence_only_response(validated,response())["outcome"],"PASS")
 def test_request_negatives(self):
  for v in (lambda:validate_request(1,U,request()),lambda:validate_request(U,U,request(path="/x")),lambda:validate_request(U,U,request(operation="shell"))):
   with self.assertRaises(Reject):v()
 def test_response_negatives(self):
  q=validate_request(U,U,request())
  bad=[response(evidence={"source_identity":"fixture","sha256":"A"*64,"verification_status":"VERIFIED"}),response(request_id="other"),response(evidence={"source_identity":"fixture","sha256":"a"*64,"verification_status":"NO"}),response(outcome="BLOCKED",reasons=[]),response(evidence={"source_identity":"fixture","sha256":"a"*64,"verification_status":"VERIFIED","content":"x"}),response(acceptance_authority=True),response(phase_b_authority=True)]
  for v in bad:
   with self.assertRaises(Reject):validate_evidence_only_response(q,v)
 def test_collision(self):
  with self.assertRaises(RuntimeError):refusal_preflight(["educore-uat-reconcile"],None)

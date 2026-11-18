"""Unprivileged synthetic interoperability tests, not cross-UID/DAC validation."""
import hashlib
import os
import socket
import tempfile
import threading
import unittest
from unittest.mock import patch
from hctb_contract import canonical
from root_evidence_reader import FixtureTrustAnchor, Reject as ReaderReject
from reader_response_transport import serve_once, Reject as TransportReject
from unprivileged_controller import request_for, exchange, persist_evidence, EvidenceDirectoryAnchor, Reject as ControllerReject
from root_evidence_verifier import verify_evidence, TrustedReference, EvidenceDirectoryAnchor as VerifierAnchor, Reject as VerifierReject

class Integration(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = os.path.join(self.tmp.name, 'root')
        self.out = os.path.join(self.tmp.name, 'out')
        os.mkdir(self.root, 0o700)
        os.mkdir(self.out, 0o700)
        self.fd = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.out_fd = os.open(self.out, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.addCleanup(os.close, self.fd)
        self.addCleanup(os.close, self.out_fd)
        self.uid = os.geteuid()
        source = os.path.join(self.root, 'journal')
        with open(source, 'wb') as h: h.write(b'synthetic journal bytes')
        os.chmod(source, 0o600)
        self.source_hash = hashlib.sha256(b'synthetic journal bytes').hexdigest()
        st = os.fstat(self.fd)
        self.fixture_anchor = FixtureTrustAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        st = os.fstat(self.out_fd)
        self.out_anchor = EvidenceDirectoryAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        self.verifier_anchor = VerifierAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        self.req = request_for('integration-1','collect_journal')
        self.left,self.right=socket.socketpair()
        self.addCleanup(self.left.close)
        self.addCleanup(self.right.close)

    def run_reader(self, *, anchor=None, uid=None):
        errors=[]
        def serve():
            try:
                with patch('root_evidence_reader.peer_uid',return_value=self.uid if uid is None else uid):
                    serve_once(self.right,self.uid,self.fd,self.uid,trusted_root=self.fixture_anchor if anchor is None else anchor)
            except Exception as e:
                errors.append(e)
            finally:
                self.right.close()
        t=threading.Thread(target=serve)
        t.start()
        self.addCleanup(lambda: t.join(timeout=1))
        return t,errors

    def roundtrip(self):
        t,errors=self.run_reader()
        result=exchange(self.left,self.req)
        t.join(timeout=1)
        self.assertFalse(t.is_alive())
        self.assertEqual(errors,[])
        return result

    def test_full_lifecycle(self):
        r=self.roundtrip()
        self.assertEqual(r['evidence']['sha256'],self.source_hash)
        name=persist_evidence(self.out_fd,self.out_anchor,self.req,r)
        ref=TrustedReference('integration-1','collect_journal','synthetic.collect_journal',self.source_hash)
        result=verify_evidence(self.out_fd,self.verifier_anchor,ref)
        self.assertEqual(result,r)
        self.assertEqual(name,'evidence-integration-1.json')
        self.assertIs(result['acceptance_authority'],False)
        self.assertIs(result['phase_b_authority'],False)

    def test_wrong_digest_rejected(self):
        r=self.roundtrip()
        persist_evidence(self.out_fd,self.out_anchor,self.req,r)
        ref=TrustedReference('integration-1','collect_journal','synthetic.collect_journal','0'*64)
        with self.assertRaisesRegex(VerifierReject,'digest'):verify_evidence(self.out_fd,self.verifier_anchor,ref)

    def test_modified_evidence_rejected(self):
        r=self.roundtrip()
        name=persist_evidence(self.out_fd,self.out_anchor,self.req,r)
        with open(os.path.join(self.out,name),'ab') as h:h.write(b'x')
        ref=TrustedReference('integration-1','collect_journal','synthetic.collect_journal',self.source_hash)
        with self.assertRaises(VerifierReject):verify_evidence(self.out_fd,self.verifier_anchor,ref)

    def test_duplicate_evidence_rejected(self):
        r=self.roundtrip()
        persist_evidence(self.out_fd,self.out_anchor,self.req,r)
        with self.assertRaisesRegex(ControllerReject,'create refused'):
            persist_evidence(self.out_fd,self.out_anchor,self.req,r)

    def test_bad_evidence_directory_rejected(self):
        r=self.roundtrip()
        os.chmod(self.out,0o750)
        with self.assertRaises(ControllerReject):persist_evidence(self.out_fd,self.out_anchor,self.req,r)

    def test_unsupported_operation_fails_without_success(self):
        self.req=request_for('integration-1','collect_identity')
        t,errors=self.run_reader()
        with self.assertRaises(ControllerReject):exchange(self.left,self.req)
        t.join(timeout=1)
        self.assertTrue(any(isinstance(x,ReaderReject) for x in errors))
        self.assertEqual(os.listdir(self.out),[])

    def test_peer_reject_before_read(self):
        t,errors=self.run_reader(uid=self.uid+1)
        with self.assertRaises(ControllerReject):exchange(self.left,self.req)
        t.join(timeout=1)
        self.assertTrue(any(isinstance(x,ReaderReject) for x in errors))

    def test_missing_trust_anchor_fails_no_response(self):
        errors=[]
        def server():
            try:serve_once(self.right,self.uid,self.fd,self.uid,trusted_root=None)
            except Exception as e: errors.append(e)
            finally:self.right.close()
        t=threading.Thread(target=server);t.start()
        try:
            with self.assertRaises(ControllerReject):exchange(self.left,self.req)
        finally:t.join(timeout=1)
        self.assertTrue(any(isinstance(x,TransportReject) for x in errors))

    def test_wrong_source_identity_rejected(self):
        r=self.roundtrip()
        persist_evidence(self.out_fd,self.out_anchor,self.req,r)
        ref=TrustedReference('integration-1','collect_journal','synthetic.wrong',self.source_hash)
        with self.assertRaises(VerifierReject):verify_evidence(self.out_fd,self.verifier_anchor,ref)

    def test_request_mismatch_rejected(self):
        r=self.roundtrip()
        persist_evidence(self.out_fd,self.out_anchor,self.req,r)
        ref=TrustedReference('other-id','collect_journal','synthetic.collect_journal',self.source_hash)
        with self.assertRaises(VerifierReject):verify_evidence(self.out_fd,self.verifier_anchor,ref)

    def test_directory_anchor_rejected(self):
        r=self.roundtrip()
        from dataclasses import replace
        with self.assertRaises(ControllerReject):persist_evidence(self.out_fd,replace(self.out_anchor,inode=self.out_anchor.inode+1),self.req,r)

    def test_no_raw_source_bytes_in_response(self):
        r=self.roundtrip()
        self.assertNotIn(b'synthetic journal bytes',canonical(r))
        self.assertEqual(set(r),{'schema','request_id','operation','outcome','evidence','reasons','acceptance_authority','phase_b_authority'})

if __name__=='__main__':unittest.main()

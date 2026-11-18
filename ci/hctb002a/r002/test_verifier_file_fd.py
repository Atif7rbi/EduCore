"""S3-R001 file-FD verifier tests; synthetic broker endpoints, never privileged."""
import fcntl
import hashlib
import os
import socket
import tempfile
import threading
import unittest
from dataclasses import replace
from unittest.mock import patch
from hctb_contract import SCHEMA, canonical
from evidence_broker import BrokerBinding, DirectoryIdentity, send_pinned_evidence, receive_pinned_evidence
from root_evidence_verifier import TrustedReference, verify_received_file_fd, Reject


class FileFDVerifierTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        os.chmod(self.temp.name, 0o700)
        self.dfd = os.open(self.temp.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.addCleanup(os.close, self.dfd)
        self.path = os.path.join(self.temp.name, 'evidence-case-1.json')
        self.source_digest = hashlib.sha256(b'out-of-band synthetic vector').hexdigest()
        self.response = {'schema': SCHEMA, 'request_id': 'case-1', 'operation': 'collect_journal',
            'outcome': 'PASS', 'evidence': {'source_identity': 'fixture.journal',
            'sha256': self.source_digest, 'verification_status': 'VERIFIED'},
            'reasons': [], 'acceptance_authority': False, 'phase_b_authority': False}
        self.write()
        d=os.fstat(self.dfd);f=os.stat(self.path)
        self.binding=BrokerBinding('run-1','case-1','collect_journal','fixture.journal',
            DirectoryIdentity(d.st_dev,d.st_ino,d.st_uid,d.st_gid),f.st_uid,f.st_gid,
            f.st_dev,f.st_ino,os.geteuid(),os.getegid())
        self.ref=TrustedReference('case-1','collect_journal','fixture.journal',self.source_digest)
    def write(self, data=None):
        with open(self.path,'wb') as f:f.write(canonical(self.response) if data is None else data)
        os.chmod(self.path,0o600)
    def fd(self, flags=os.O_RDONLY): return os.open(self.path,flags)
    def assert_consumed(self, fd):
        with self.assertRaises(OSError):os.fstat(fd)
    def test_success_file_fd(self):
        fd=self.fd(); result=verify_received_file_fd(fd,self.binding,self.ref)
        self.assertEqual(result,self.response);self.assert_consumed(fd)
    def test_real_broker_transfer_to_verifier(self):
        a,b=socket.socketpair(socket.AF_UNIX,socket.SOCK_SEQPACKET)
        self.addCleanup(a.close);self.addCleanup(b.close)
        a.settimeout(.5);b.settimeout(.5)
        errors=[]
        def send():
            try:send_pinned_evidence(a,self.dfd,self.binding,used_ids=set())
            except Exception as e:errors.append(e)
            finally:a.close()
        t=threading.Thread(target=send);t.start()
        try:
            fd=receive_pinned_evidence(b,self.binding,broker_uid=os.geteuid())
            self.assertEqual(verify_received_file_fd(fd,self.binding,self.ref),self.response)
            self.assert_consumed(fd)
        finally:t.join(timeout=1)
        self.assertFalse(t.is_alive());self.assertFalse(errors)
    def test_wrong_reference_digest(self):
        fd=self.fd()
        with self.assertRaisesRegex(Reject,'digest'):
            verify_received_file_fd(fd,self.binding,replace(self.ref,expected_sha256='0'*64))
        self.assert_consumed(fd)
    def test_wrong_reference_request(self):
        fd=self.fd()
        with self.assertRaisesRegex(Reject,'identity mismatch'):
            verify_received_file_fd(fd,self.binding,replace(self.ref,request_id='other'))
        self.assert_consumed(fd)
    def test_wrong_reference_operation(self):
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,replace(self.ref,operation='collect_archive'))
        self.assert_consumed(fd)
    def test_wrong_reference_source(self):
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,replace(self.ref,source_identity='wrong'))
        self.assert_consumed(fd)
    def test_missing_binding(self):
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,None,self.ref)
        self.assert_consumed(fd)
    def test_invalid_fd(self):
        with self.assertRaises(Reject):verify_received_file_fd(-1,self.binding,self.ref)
    def test_directory_fd(self):
        fd=os.dup(self.dfd)
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_writable_fd(self):
        fd=self.fd(os.O_WRONLY)
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_read_write_fd(self):
        fd=self.fd(os.O_RDWR)
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_opath_fd(self):
        if not hasattr(os,'O_PATH'):self.skipTest('linux O_PATH unavailable')
        fd=self.fd(os.O_PATH)
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_wrong_inode(self):
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,replace(self.binding,evidence_inode=1),self.ref)
        self.assert_consumed(fd)
    def test_wrong_owner(self):
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,replace(self.binding,evidence_uid=self.binding.evidence_uid+1),self.ref)
        self.assert_consumed(fd)
    def test_wrong_mode(self):
        os.chmod(self.path,0o640);fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_hardlink(self):
        os.link(self.path,self.path+'-other');fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_truncated_content(self):
        fd=self.fd();seen=[False]
        def short(f,n):
            if seen[0]:return b''
            seen[0]=True;return os.read(f,3)
        with self.assertRaisesRegex(Reject,'modified'):
            verify_received_file_fd(fd,self.binding,self.ref,read_fn=short)
        self.assert_consumed(fd)
    def test_mutated_content(self):
        fd=self.fd();seen=[False]
        def mutate(f,n):
            if not seen[0]:
                seen[0]=True
                with open(self.path,'ab') as h:h.write(b'x')
            return os.read(f,n)
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref,read_fn=mutate)
        self.assert_consumed(fd)
    def test_duplicate_json(self):
        data=canonical(self.response)[:-1]+b',"schema":"again"}';self.write(data)
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_authority_promotion(self):
        self.response['phase_b_authority']=True;self.write();fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_invalid_read_cleanup(self):
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref,read_fn=lambda f,n:None)
        self.assert_consumed(fd)
    def test_read_exception_cleanup(self):
        fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref,read_fn=lambda f,n: (_ for _ in ()).throw(OSError('test')))
        self.assert_consumed(fd)
    def test_noncanonical(self):
        self.write(b' '+canonical(self.response));fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_malformed_json(self):
        self.write(b'{');fd=self.fd()
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)
        self.assert_consumed(fd)
    def test_closed_fd(self):
        fd=self.fd();os.close(fd)
        with self.assertRaises(Reject):verify_received_file_fd(fd,self.binding,self.ref)

if __name__=='__main__': unittest.main()

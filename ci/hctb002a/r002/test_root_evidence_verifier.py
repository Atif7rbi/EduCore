import os
import stat
import tempfile
import unittest
from dataclasses import replace
from unittest.mock import patch
from hctb_contract import SCHEMA, canonical
from root_evidence_verifier import (EvidenceDirectoryAnchor, TrustedReference,
                                    Reject, verify_evidence, verify_dir_fd,
                                    MAX_EVIDENCE_SIZE)

class VerifierTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        os.chmod(self.tmp.name, 0o700)
        self.dir_fd = os.open(self.tmp.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.addCleanup(os.close, self.dir_fd)
        st = os.fstat(self.dir_fd)
        self.anchor = EvidenceDirectoryAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        self.ref = TrustedReference('case-1', 'collect_journal', 'fixture.journal', 'a'*64)
        self.path = os.path.join(self.tmp.name, 'evidence-case-1.json')
        self.write()
    def record(self, **changes):
        r = {'schema': SCHEMA, 'request_id': 'case-1', 'operation': 'collect_journal',
             'outcome': 'PASS', 'evidence': {'source_identity': 'fixture.journal',
             'sha256': 'a'*64, 'verification_status': 'VERIFIED'}, 'reasons': [],
             'acceptance_authority': False, 'phase_b_authority': False}
        r.update(changes)
        return r
    def write(self, content=None):
        if content is None: content = canonical(self.record())
        with open(self.path, 'wb') as f: f.write(content)
        os.chmod(self.path, 0o600)
    def verify(self, **kw):
        return verify_evidence(self.dir_fd, self.anchor, self.ref, **kw)
    def test_valid_reference(self):
        self.assertEqual(self.verify()['evidence']['sha256'], 'a'*64)
    def test_digest_mismatch(self):
        with self.assertRaisesRegex(Reject,'digest'):verify_evidence(self.dir_fd,self.anchor,replace(self.ref,expected_sha256='b'*64))
    def test_missing_file(self):
        os.unlink(self.path)
        with self.assertRaises(Reject):self.verify()
    def test_substituted_file_digest(self):
        r=self.record();r['evidence']['sha256']='c'*64;self.write(canonical(r))
        with self.assertRaises(Reject):self.verify()
    def test_wrong_source_identity(self):
        r=self.record();r['evidence']['source_identity']='fixture.other';self.write(canonical(r))
        with self.assertRaises(Reject):self.verify()
    def test_request_id_mismatch(self):
        self.write(canonical(self.record(request_id='case-2')))
        with self.assertRaises(Reject):self.verify()
    def test_operation_mismatch(self):
        self.write(canonical(self.record(operation='collect_archive')))
        with self.assertRaises(Reject):self.verify()
    def test_authority_promotion(self):
        self.write(canonical(self.record(phase_b_authority=True)))
        with self.assertRaises(Reject):self.verify()
    def test_acceptance_promotion(self):
        self.write(canonical(self.record(acceptance_authority=True)))
        with self.assertRaises(Reject):self.verify()
    def test_bad_verification_status(self):
        r=self.record();r['evidence']['verification_status']='UNAVAILABLE';self.write(canonical(r))
        with self.assertRaises(Reject):self.verify()
    def test_extra_nested_field(self):
        r=self.record();r['evidence']['content']='secret';self.write(canonical(r))
        with self.assertRaises(Reject):self.verify()
    def test_duplicate_json_key(self):
        self.write(canonical(self.record())[:-1]+b',"schema":"duplicate"}')
        with self.assertRaisesRegex(Reject,'duplicate'):self.verify()
    def test_noncanonical_json(self):
        self.write(b' '+canonical(self.record()))
        with self.assertRaisesRegex(Reject,'noncanonical'):self.verify()
    def test_directory_mode(self):
        os.chmod(self.tmp.name,0o750)
        with self.assertRaises(Reject):self.verify()
    def test_untrusted_directory_identity(self):
        with self.assertRaises(Reject):verify_evidence(self.dir_fd,replace(self.anchor,inode=self.anchor.inode+1),self.ref)
    def test_missing_anchor(self):
        with self.assertRaises(Reject):verify_evidence(self.dir_fd,None,self.ref)
    def test_missing_reference(self):
        with self.assertRaises(Reject):verify_evidence(self.dir_fd,self.anchor,None)
    def test_symlink(self):
        os.unlink(self.path)
        os.symlink('/etc/passwd',self.path)
        with self.assertRaises(Reject):self.verify()
    def test_hardlink(self):
        os.link(self.path,os.path.join(self.tmp.name,'hardlink'))
        with self.assertRaises(Reject):self.verify()
    def test_wrong_file_mode(self):
        os.chmod(self.path,0o644)
        with self.assertRaises(Reject):self.verify()
    def test_wrong_file_uid_synthetic(self):
        real=os.fstat
        def fake(fd):
            result=real(fd)
            if stat.S_ISREG(result.st_mode):
                class Changed:
                    def __getattr__(self,name):return getattr(result,name)
                    st_uid=result.st_uid+1
                return Changed()
            return result
        with patch('root_evidence_verifier.os.fstat',side_effect=fake):
            with self.assertRaises(Reject):self.verify()
    def test_oversize(self):
        self.write(b'a'*(MAX_EVIDENCE_SIZE+1))
        with self.assertRaises(Reject):self.verify()
    def test_truncated(self):
        def premature(fd,n):
            if not hasattr(premature,'seen'):
                premature.seen=True
                return os.read(fd,3)
            return b''
        with self.assertRaisesRegex(Reject,'modified'):self.verify(read_fn=premature)
    def test_modified_during_read(self):
        triggered=False
        def mutate(fd,n):
            nonlocal triggered
            if not triggered:
                triggered=True
                with open(self.path,'ab') as f:f.write(b'!')
            return os.read(fd,n)
        with self.assertRaises(Reject):self.verify(read_fn=mutate)
    def test_descriptor_closed_on_error(self):
        seen=[]
        def fail(fd,n):
            seen.append(fd)
            raise OSError('injected')
        with self.assertRaises(Reject):self.verify(read_fn=fail)
        with self.assertRaises(OSError):os.fstat(seen[0])
    def test_no_arbitrary_request_id(self):
        with self.assertRaises(Reject):verify_evidence(self.dir_fd,self.anchor,replace(self.ref,request_id='../etc'))
    def test_not_pass_reference(self):
        with self.assertRaises(Reject):verify_evidence(self.dir_fd,self.anchor,replace(self.ref,outcome='BLOCKED'))
    def test_root_changed_during_read(self):
        triggered=False
        def mutate(fd,n):
            nonlocal triggered
            if not triggered:
                triggered=True
                os.chmod(self.tmp.name,0o750)
            return os.read(fd,n)
        with self.assertRaises(Reject):self.verify(read_fn=mutate)

if __name__ == '__main__':unittest.main()

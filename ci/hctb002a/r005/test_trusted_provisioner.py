"""S3-R002 synthetic disposable fixture tests. No EduCore host access."""
import hashlib
import os
import tempfile
import unittest
from dataclasses import replace
from hctb_contract import SCHEMA,canonical
from trusted_provisioner import (ExternalVector,ProvisioningInputs,Reject,
    seal_pre_controller,verify_pre_controller,finalize_broker_binding,MANIFEST_NAME)
from root_evidence_verifier import verify_received_file_fd

class ProvisioningTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.uid=os.geteuid();self.gid=os.getegid()
        self.cuid=31021 if self.uid != 31021 else 31022
        self.cgid=31021 if self.gid != 31021 else 31022
        self.vuid=31022 if self.cuid != 31022 else 31023
        self.vgid=31022 if self.cgid != 31022 else 31023
        self.paths=[os.path.join(self.tmp.name,n) for n in ('root','controller','trust')]
        for p in self.paths:os.mkdir(p,0o700)
        # The isolated unit container runs with uid 0; only these disposable
        # temporary directories are chowned for synthetic identity testing.
        if self.uid != 0:self.skipTest('isolated uid-0 fixture required to create distinct synthetic ownership')
        os.chown(self.paths[1],self.cuid,self.cgid)
        self.fds=[os.open(p,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW) for p in self.paths]
        for fd in self.fds:self.addCleanup(os.close,fd)
        self.inputs=ProvisioningInputs('run-1','case-1','collect_journal',self.cuid,self.cgid,self.vuid,self.vgid)
        self.vector=ExternalVector('1'*64,'fixture.journal',hashlib.sha256(b'external fixture content').hexdigest())
        self.record={'schema':SCHEMA,'request_id':'case-1','operation':'collect_journal',
            'outcome':'PASS','evidence':{'source_identity':'fixture.journal','sha256':self.vector.expected_source_sha256,
            'verification_status':'VERIFIED'},'reasons':[],'acceptance_authority':False,'phase_b_authority':False}
        self.file=os.path.join(self.paths[1],'evidence-case-1.json')
        with open(os.path.join(self.paths[0],'journal'),'wb') as f:
            f.write(b'external fixture content')
        os.chmod(os.path.join(self.paths[0],'journal'),0o600)
    def seed(self):
        return seal_pre_controller(self.fds[2],self.fds[0],self.fds[1],self.inputs,self.vector,
                                   provisioner_uid=self.uid,provisioner_gid=self.gid)
    def write_record(self, record=None):
        fd=os.open(self.file,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        try:
            data=canonical(self.record if record is None else record)
            os.write(fd,data);os.fsync(fd)
            os.fchown(fd,self.cuid,self.cgid)
        finally:os.close(fd)
    def finalize(self,seed):return finalize_broker_binding(self.fds[2],self.fds[0],self.fds[1],seed)
    def test_pre_controller_seed(self):
        seed=self.seed()
        self.assertIs(verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],seed),seed)
        self.assertEqual(seed.reference.expected_sha256,self.vector.expected_source_sha256)
        self.assertEqual(seed.fixture_anchor.uid,self.uid)
        self.assertEqual(seed.controller_anchor.uid,self.cuid)
        self.assertFalse(os.stat(os.path.join(self.paths[2],MANIFEST_NAME)).st_mode & 0o077)
    def test_one_shot_no_overwrite(self):
        self.seed()
        with self.assertRaises(Reject):self.seed()
    def test_no_manifest_before_controller(self):
        self.assertFalse(os.path.exists(os.path.join(self.paths[2],MANIFEST_NAME)))
        self.seed();self.assertTrue(os.path.exists(os.path.join(self.paths[2],MANIFEST_NAME)))
    def test_finalize_only_after_evidence(self):
        seed=self.seed()
        with self.assertRaisesRegex(Reject,'not ready'):self.finalize(seed)
        self.write_record();binding=self.finalize(seed)
        self.assertEqual(binding.evidence_uid,self.cuid)
        self.assertEqual(binding.source_identity,self.vector.source_identity)
        self.assertEqual(binding.verifier_uid,self.vuid)
    def test_full_fd_verifier_from_derived_binding(self):
        seed=self.seed();self.write_record();binding=self.finalize(seed)
        fd=os.open(self.file,os.O_RDONLY)
        self.assertEqual(verify_received_file_fd(fd,binding,seed.reference),self.record)
        with self.assertRaises(OSError):os.fstat(fd)
    def test_manifest_tamper_rejected(self):
        seed=self.seed()
        with open(os.path.join(self.paths[2],MANIFEST_NAME),'r+b') as f:f.seek(0);f.write(b'x')
        with self.assertRaises(Reject):verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],seed)
    def test_manifest_replacement_rejected(self):
        seed=self.seed();p=os.path.join(self.paths[2],MANIFEST_NAME)
        os.unlink(p)
        with open(p,'wb') as f:f.write(b'{}')
        os.chmod(p,0o600)
        with self.assertRaises(Reject):verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],seed)
    def test_manifest_mode_rejected(self):
        seed=self.seed();os.chmod(os.path.join(self.paths[2],MANIFEST_NAME),0o640)
        with self.assertRaises(Reject):verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],seed)
    def test_manifest_symlink_refused(self):
        seed=self.seed();p=os.path.join(self.paths[2],MANIFEST_NAME)
        os.unlink(p);os.symlink('/etc/passwd',p)
        with self.assertRaises(Reject):verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],seed)
    def test_trust_directory_swap_refused(self):
        seed=self.seed();os.chmod(self.paths[2],0o750)
        with self.assertRaises(Reject):verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],seed)
    def test_controller_directory_swap_refused(self):
        seed=self.seed();os.chmod(self.paths[1],0o750)
        with self.assertRaises(Reject):verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],seed)
    def test_external_fixture_digest_mismatch(self):
        with open(os.path.join(self.paths[0],'journal'),'wb') as f:f.write(b'modified fixture')
        with self.assertRaisesRegex(Reject,'digest mismatch'):self.seed()
        self.assertFalse(os.path.exists(os.path.join(self.paths[2],MANIFEST_NAME)))
    def test_missing_fixture_source(self):
        os.unlink(os.path.join(self.paths[0],'journal'))
        with self.assertRaisesRegex(Reject,'fixture validation'):self.seed()
    def test_invalid_external_expected_sha(self):
        with self.assertRaises(Reject):seal_pre_controller(self.fds[2],self.fds[0],self.fds[1],self.inputs,
            replace(self.vector,expected_source_sha256='bad'),provisioner_uid=self.uid,provisioner_gid=self.gid)
    def test_wrong_provisioner_identity(self):
        with self.assertRaises(Reject):seal_pre_controller(self.fds[2],self.fds[0],self.fds[1],self.inputs,self.vector,
            provisioner_uid=self.uid+1,provisioner_gid=self.gid)
    def test_no_controller_verifier_same_uid(self):
        with self.assertRaises(Reject):seal_pre_controller(self.fds[2],self.fds[0],self.fds[1],
            replace(self.inputs,verifier_uid=self.cuid),self.vector,provisioner_uid=self.uid,provisioner_gid=self.gid)
    def test_evidence_digest_not_trusted(self):
        seed=self.seed();bad=self.record.copy();bad['evidence']={**self.record['evidence'],'sha256':'f'*64}
        self.write_record(bad)
        with self.assertRaisesRegex(Reject,'pinned reference'):self.finalize(seed)
    def test_evidence_wrong_source(self):
        seed=self.seed();bad=self.record.copy();bad['evidence']={**self.record['evidence'],'source_identity':'other'}
        self.write_record(bad)
        with self.assertRaises(Reject):self.finalize(seed)
    def test_evidence_wrong_operation(self):
        seed=self.seed();self.write_record({**self.record,'operation':'collect_archive'})
        with self.assertRaises(Reject):self.finalize(seed)
    def test_evidence_wrong_authority(self):
        seed=self.seed();self.write_record({**self.record,'phase_b_authority':True})
        with self.assertRaises(Reject):self.finalize(seed)
    def test_evidence_wrong_uid(self):
        seed=self.seed();self.write_record();os.chown(self.file,0,0)
        with self.assertRaises(Reject):self.finalize(seed)
    def test_evidence_hardlink(self):
        seed=self.seed();self.write_record();os.link(self.file,self.file+'.link')
        with self.assertRaises(Reject):self.finalize(seed)
    def test_evidence_symlink(self):
        seed=self.seed();os.symlink('/etc/passwd',self.file)
        with self.assertRaises(Reject):self.finalize(seed)
    def test_duplicate_manifest_identity_run_id(self):
        seed=self.seed();other=replace(seed,inputs=replace(seed.inputs,run_id='other-run'))
        with self.assertRaises(Reject):verify_pre_controller(self.fds[2],self.fds[0],self.fds[1],other)

if __name__=='__main__':unittest.main()

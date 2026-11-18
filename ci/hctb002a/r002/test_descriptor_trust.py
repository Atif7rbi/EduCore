"""Synthetic descriptor trust tests; does not establish root authority or cross-UID DAC."""
import os
import stat
import tempfile
import unittest
from dataclasses import replace
from unittest.mock import patch
from root_evidence_reader import (FixtureTrustAnchor, Reject, read_source, verify_root_fd)

class DescriptorTrustTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        os.chmod(self.tmp.name,0o700)
        self.fd=os.open(self.tmp.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.addCleanup(os.close,self.fd)
        st=os.fstat(self.fd)
        self.anchor=FixtureTrustAnchor(st.st_dev,st.st_ino,st.st_uid,st.st_gid)
        self.p=os.path.join(self.tmp.name,'journal')
        with open(self.p,'wb') as f:f.write(b'fixture')
        os.chmod(self.p,0o600)

    def read(self,**kw):
        return read_source(self.fd,'collect_journal',os.geteuid(),trusted_root=self.anchor,**kw)

    def test_valid_anchor(self):
        self.assertEqual(self.read(),b'fixture')
    def test_no_anchor_is_rejected(self):
        with self.assertRaisesRegex(Reject,'untrusted root'):read_source(self.fd,'collect_journal',os.geteuid())
    def test_directory_mode_rejected(self):
        os.chmod(self.tmp.name,0o750)
        with self.assertRaisesRegex(Reject,'trust mismatch'):self.read()
    def test_directory_uid_mismatch_rejected(self):
        with self.assertRaises(Reject):read_source(self.fd,'collect_journal',os.geteuid(),trusted_root=replace(self.anchor,uid=self.anchor.uid+1))
    def test_directory_gid_mismatch_rejected(self):
        with self.assertRaises(Reject):read_source(self.fd,'collect_journal',os.geteuid(),trusted_root=replace(self.anchor,gid=self.anchor.gid+1))
    def test_untrusted_directory_inode_rejected(self):
        with self.assertRaises(Reject):read_source(self.fd,'collect_journal',os.geteuid(),trusted_root=replace(self.anchor,inode=self.anchor.inode+1))
    def test_untrusted_device_rejected(self):
        with self.assertRaises(Reject):read_source(self.fd,'collect_journal',os.geteuid(),trusted_root=replace(self.anchor,device=self.anchor.device+1))
    def test_non_directory_descriptor_rejected(self):
        fd=os.open(self.p,os.O_RDONLY)
        try:
            with self.assertRaises(Reject):verify_root_fd(fd,self.anchor)
        finally:os.close(fd)
    def test_file_gid_mismatch_rejected(self):
        real=os.fstat
        def fake(fd):
            result=real(fd)
            if stat.S_ISREG(result.st_mode):
                class Changed:
                    def __getattr__(self,name):return getattr(result,name)
                    st_gid=result.st_gid+1
                return Changed()
            return result
        with patch('root_evidence_reader.os.fstat',side_effect=fake):
            with self.assertRaisesRegex(Reject,'metadata'):self.read()
    def test_hardlink_rejected(self):
        os.link(self.p,os.path.join(self.tmp.name,'hardlink'))
        with self.assertRaisesRegex(Reject,'metadata'):self.read()
    def test_symlink_rejected(self):
        os.unlink(self.p)
        os.symlink('/etc/passwd',self.p)
        with self.assertRaises(Reject):self.read()
    def test_source_mutation_rejected(self):
        def change(fd,n):
            with open(self.p,'ab') as f:f.write(b'new')
            return os.read(fd,n)
        with self.assertRaises(Reject):self.read(read_fn=change)
    def test_failure_closes_source_fd(self):
        seen=[]
        def fail(fd,n):seen.append(fd);raise OSError('injected')
        with self.assertRaises(OSError):self.read(read_fn=fail)
        with self.assertRaises(OSError):os.fstat(seen[0])
    def test_root_changed_after_read_rejected(self):
        changed=False
        def mutate(fd,n):
            nonlocal changed
            if not changed:
                changed=True
                os.chmod(self.tmp.name,0o750)
            return os.read(fd,n)
        with self.assertRaises(Reject):self.read(read_fn=mutate)

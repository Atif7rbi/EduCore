import array
import fcntl
import os
import socket
import struct
import tempfile
import threading
import unittest
from dataclasses import replace
from unittest.mock import patch

from durable_replay import JournalAnchor
from test_transaction_harness import TransactionHarness
from evidence_broker import (BrokerBinding, DirectoryIdentity, Reject,
                             send_pinned_evidence, receive_pinned_evidence)

class BrokerTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        os.chmod(self.tmp.name,0o700)
        self.dfd=os.open(self.tmp.name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        self.addCleanup(os.close,self.dfd)
        self.path=os.path.join(self.tmp.name,'evidence-case-1.json')
        with open(self.path,'wb') as f:f.write(b'fixture-only')
        os.chmod(self.path,0o600)
        ds=os.fstat(self.dfd); fs=os.stat(self.path)
        self.binding=BrokerBinding('run-1','case-1','collect_journal','fixture.journal',
            DirectoryIdentity(ds.st_dev,ds.st_ino,ds.st_uid,ds.st_gid),
            fs.st_uid,fs.st_gid,fs.st_dev,fs.st_ino,os.geteuid(),os.getegid())
        self.a,self.b=socket.socketpair(socket.AF_UNIX,socket.SOCK_SEQPACKET)
        self.addCleanup(self.a.close);self.addCleanup(self.b.close)
        self.a.settimeout(0.6);self.b.settimeout(0.6)
        self.used=set()
        self.journal_path=os.path.join(self.tmp.name, 'durable-journal')
        os.mkdir(self.journal_path,0o700)
        self.journal_fd=os.open(self.journal_path,os.O_RDONLY|os.O_DIRECTORY)
        self.addCleanup(os.close,self.journal_fd)
        j=os.fstat(self.journal_fd)
        self.journal_anchor=JournalAnchor(j.st_dev,j.st_ino,j.st_uid,j.st_gid)
        self.durable_args={'journal_dir_fd':self.journal_fd,'journal_anchor':self.journal_anchor}
        self.tx=TransactionHarness(self.journal_path,self.binding); self.addCleanup(self.tx.close)
    def send_now(self,conn,binding=None,fd=None):
        binding=self.binding if binding is None else binding
        authorization=self.tx.authorize(conn,binding)
        return send_pinned_evidence(conn,self.dfd if fd is None else fd,binding,authorization=authorization)
    def serve(self,binding=None,fd=None,conn=None):
        errors=[]
        def go():
            try:self.send_now(conn or self.a,self.binding if binding is None else binding,fd)
            except Exception as exc:errors.append(exc)
            finally:(conn or self.a).close()
        t=threading.Thread(target=go);t.start();self.addCleanup(lambda:t.join(timeout=1))
        return t,errors
    def receive(self,binding=None):
        return receive_pinned_evidence(self.b,self.binding if binding is None else binding,broker_uid=os.geteuid())
    def test_valid_real_scm_rights_file_fd(self):
        t,errors=self.serve();fd=self.receive()
        try:
            self.assertEqual(os.read(fd,30),b'fixture-only')
            self.assertEqual(fcntl.fcntl(fd,fcntl.F_GETFL)&os.O_ACCMODE,os.O_RDONLY)
            self.assertTrue(fcntl.fcntl(fd,fcntl.F_GETFD)&fcntl.FD_CLOEXEC)
        finally:os.close(fd)
        t.join();self.assertFalse(errors)
    def test_wrong_verifier_uid(self):
        with self.assertRaisesRegex(Exception,'unauthorized'):
            self.send_now(self.a,replace(self.binding,verifier_uid=os.geteuid()+1))
        self.assertFalse(self.used)
    def test_wrong_broker_uid(self):
        with self.assertRaisesRegex(Reject,'unauthorized'):
            receive_pinned_evidence(self.b,self.binding,broker_uid=os.geteuid()+1)
    def test_replay_rejected(self):
        t,err=self.serve();fd=self.receive();os.close(fd);t.join()
        with self.assertRaisesRegex(Exception,'durable replay refused'):
            self.send_now(self.b)
    def test_directory_identity_mismatch(self):
        with self.assertRaisesRegex(Reject,'directory'):
            self.send_now(self.a,replace(self.binding,directory=replace(self.binding.directory,inode=1)))
    def test_missing_file(self):
        os.unlink(self.path)
        with self.assertRaises(Exception):self.send_now(self.a)
    def test_symlink_refused(self):
        os.unlink(self.path);os.symlink('/etc/passwd',self.path)
        with self.assertRaises(Exception):self.send_now(self.a)
    def test_hardlink_refused(self):
        os.link(self.path,self.path+'.2')
        with self.assertRaisesRegex(Reject,'descriptor mismatch'):
            self.send_now(self.a)
    def test_wrong_inode(self):
        with self.assertRaisesRegex(Reject,'descriptor mismatch'):
            self.send_now(self.a,replace(self.binding,evidence_inode=2))
    def test_wrong_file_mode(self):
        os.chmod(self.path,0o640)
        with self.assertRaisesRegex(Reject,'descriptor mismatch'):
            self.send_now(self.a)
    def test_empty_ancillary(self):
        self.a.send(b'EDUCORE-BROKER-FD-V1:run-1:case-1:collect_journal');self.a.shutdown(socket.SHUT_WR)
        with self.assertRaisesRegex(Reject,'ancillary'):
            self.receive()
    def test_multiple_fds_rejected_and_closed(self):
        fd1=os.open(self.path,os.O_RDONLY);fd2=os.open(self.path,os.O_RDONLY)
        try:
            self.a.sendmsg([b'EDUCORE-BROKER-FD-V1:run-1:case-1:collect_journal'],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[fd1,fd2]))]);self.a.shutdown(socket.SHUT_WR)
            with self.assertRaisesRegex(Reject,'descriptor count'):self.receive()
        finally:os.close(fd1);os.close(fd2)
    def test_directory_fd_rejected(self):
        self.a.sendmsg([b'EDUCORE-BROKER-FD-V1:run-1:case-1:collect_journal'],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[self.dfd]))]);self.a.shutdown(socket.SHUT_WR)
        with self.assertRaisesRegex(Reject,'descriptor mismatch'):self.receive()
    def test_writable_fd_rejected(self):
        fd=os.open(self.path,os.O_WRONLY)
        try:self.a.sendmsg([b'EDUCORE-BROKER-FD-V1:run-1:case-1:collect_journal'],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[fd]))]);self.a.shutdown(socket.SHUT_WR)
        finally:os.close(fd)
        with self.assertRaisesRegex(Reject,'descriptor mismatch'):self.receive()
    def test_mismatched_run_binding(self):
        t,errors=self.serve()
        with self.assertRaisesRegex(Reject,'binding mismatch'):
            self.receive(replace(self.binding,run_id='run-2'))
        t.join()
    def test_truncated_packet(self):
        self.a.sendmsg([b'EDUCORE-BROKER-FD-V1:run-1:case-1:collect_journal'+b'x'*300],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[self.dfd]))]);self.a.shutdown(socket.SHUT_WR)
        with self.assertRaises(Reject):self.receive()
    def test_second_packet_rejected(self):
        fd=os.open(self.path,os.O_RDONLY)
        try:self.a.sendmsg([b'EDUCORE-BROKER-FD-V1:run-1:case-1:collect_journal'],[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[fd]))]);self.a.send(b'EXTRA');self.a.shutdown(socket.SHUT_WR)
        finally:os.close(fd)
        with self.assertRaisesRegex(Reject,'second packet'):self.receive()
    def test_timeout_fail_closed(self):
        self.b.settimeout(.02)
        with self.assertRaisesRegex(Reject,'receive failure'):self.receive()
    def test_missing_manifest_binding(self):
        with self.assertRaisesRegex(Reject,'binding'):send_pinned_evidence(self.a,self.dfd,None,authorization=None)
    def test_controller_selected_filename_impossible(self):
        with self.assertRaises(Reject):send_pinned_evidence(self.a,self.dfd,self.binding,used_ids=self.used,filename='/etc/passwd')

if __name__=='__main__':unittest.main()

"""R004 journal tests against real accepted HCTB contract and Broker."""
import unittest
import tempfile
import errno
import os
import stat
import socket
from dataclasses import replace
from unittest.mock import patch
from evidence_broker import BrokerBinding, DirectoryIdentity, Reject as BrokerReject
from durable_replay import JournalAnchor, claim_transfer, send_pinned_evidence_durable, Reject, _key
from test_transaction_harness import TransactionHarness

class DurableJournalTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.path=os.path.join(self.temp.name,'private')
        os.mkdir(self.path,0o700)
        self.fd=os.open(self.path,os.O_RDONLY|os.O_DIRECTORY)
        self.addCleanup(os.close,self.fd)
        st=os.fstat(self.fd)
        self.anchor=JournalAnchor(st.st_dev,st.st_ino,st.st_uid,st.st_gid)
        self.binding=BrokerBinding('run-1','request-1','collect_journal','fixture.journal',
            DirectoryIdentity(st.st_dev,st.st_ino,st.st_uid,st.st_gid),
            os.geteuid(),os.getegid(),42,99,os.geteuid(),os.getegid())
        self.tx=TransactionHarness(self.path,self.binding);self.addCleanup(self.tx.close)

    def claim(self,**kw):return claim_transfer(self.fd,self.anchor,self.binding,sequence=1,operation_identity=self.binding.request_id,**kw)

    def test_claim_is_durable_and_replay_refused(self):
        self.assertEqual(len(self.claim().digest),64)
        with self.assertRaisesRegex(Reject,'replay'): self.claim()
        name=_key(self.binding)
        st=os.stat(name,dir_fd=self.fd)
        self.assertEqual(stat.S_IMODE(st.st_mode),0o600)
        with open(os.path.join(self.path,name),'rb') as f:
            content=f.read()
        self.assertIn(b'INTENT_NO_RETRY',content)
        self.assertIn(b'"phase_b_authority":false',content)

    def test_restart_rejects(self):
        self.claim()
        os.close(self.fd)
        self.fd=os.open(self.path,os.O_RDONLY|os.O_DIRECTORY)
        with self.assertRaisesRegex(Reject,'replay'): self.claim()

    def test_key_boundary_noncollision(self):
        a=replace(self.binding,run_id='a-b',request_id='c')
        b=replace(self.binding,run_id='a',request_id='b-c')
        self.assertNotEqual(_key(a),_key(b))
        claim_transfer(self.fd,self.anchor,a,sequence=1,operation_identity=a.request_id)
        claim_transfer(self.fd,self.anchor,b,sequence=1,operation_identity=b.request_id)

    def test_replaced_directory_denied(self):
        with self.assertRaises(Reject):claim_transfer(self.fd,replace(self.anchor,inode=1),self.binding,sequence=1,operation_identity=self.binding.request_id)
        self.assertEqual(os.listdir(self.path),[])

    def test_wrong_owner_denied(self):
        with self.assertRaises(Reject):claim_transfer(self.fd,replace(self.anchor,uid=self.anchor.uid+1),self.binding,sequence=1,operation_identity=self.binding.request_id)

    def test_wrong_mode_denied(self):
        os.chmod(self.path,0o750)
        with self.assertRaises(Reject):self.claim()

    def test_opath_denied(self):
        if not hasattr(os,'O_PATH'):self.skipTest('O_PATH Linux only')
        fd=os.open(self.path,os.O_PATH|os.O_DIRECTORY)
        try:
            with self.assertRaises(Reject):claim_transfer(fd,self.anchor,self.binding,sequence=1,operation_identity=self.binding.request_id)
        finally:os.close(fd)

    def test_preexisting_symlink_does_not_follow(self):
        os.symlink('/etc/passwd',os.path.join(self.path,_key(self.binding)))
        with self.assertRaises(Reject):self.claim()

    def test_failure_after_file_fsync_consumes_intent(self):
        def crash():raise RuntimeError('injected interruption')
        with self.assertRaisesRegex(RuntimeError,'injected'):
            self.claim(after_file_sync=crash)
        with self.assertRaisesRegex(Reject,'replay'):self.claim()

    def test_failure_before_fsync_is_not_replayed(self):
        with patch('durable_replay.os.fsync',side_effect=OSError(errno.EIO,'disk failed')):
            with self.assertRaisesRegex(Reject,'durably'):self.claim()
        with self.assertRaisesRegex(Reject,'replay'):self.claim()

    def test_parallel_competition_one_winner(self):
        children=[]
        for i in range(4):
            r,w=os.pipe()
            pid=os.fork()
            if pid==0:
                os.close(r)
                try:
                    try:claim_transfer(self.fd,self.anchor,self.binding,sequence=1,operation_identity=self.binding.request_id);status=b'W'
                    except Reject:status=b'R'
                    os.write(w,status)
                finally:os.close(w)
                os._exit(0)
            os.close(w);children.append((pid,r))
        values=[]
        for pid,r in children:
            values.append(os.read(r,1));os.close(r)
            _,status=os.waitpid(pid,0)
            self.assertEqual(status,0)
        self.assertEqual(values.count(b'W'),1)
        self.assertEqual(values.count(b'R'),3)

    def test_unauthorized_peer_does_not_consume_key(self):
        a,b=socket.socketpair(socket.AF_UNIX,socket.SOCK_SEQPACKET)
        try:
            with patch('durable_replay._peer',return_value=(123,os.geteuid()+1,os.getegid())):
                with self.assertRaisesRegex(Reject,'unauthorized'):
                    self.tx.authorize(a)
            self.assertEqual(os.listdir(self.path),[])
        finally:a.close();b.close()

    def test_ambiguous_send_consumes_intent(self):
        a,b=socket.socketpair(socket.AF_UNIX,socket.SOCK_SEQPACKET)
        def send_then_break(conn,evidence_dir_fd,binding,**kwargs):
            raise OSError(errno.EPIPE,'broken pipe')
        try:
            with patch('evidence_broker.send_pinned_evidence',side_effect=send_then_break):
                with self.assertRaises(OSError):
                    send_pinned_evidence_durable(a,0,self.binding,authorization=self.tx.authorize(a))
            with self.assertRaisesRegex(Reject,'replay'):self.claim()
        finally:a.close();b.close()

if __name__=='__main__':unittest.main()

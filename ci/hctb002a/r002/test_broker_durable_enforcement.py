"""S3-R004 real accepted Broker integration. Run separately from stub journal unit tests."""
import os, socket, tempfile, threading, stat, unittest
from unittest.mock import patch
from evidence_broker import BrokerBinding, DirectoryIdentity, Reject, send_pinned_evidence, receive_pinned_evidence
from durable_replay import JournalAnchor, _key, claim_transfer


class BrokerDurableEnforcement(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.evidence=os.path.join(self.tmp.name,'evidence')
        self.journal=os.path.join(self.tmp.name,'journal')
        os.mkdir(self.evidence,0o700);os.mkdir(self.journal,0o700)
        self.e_fd=os.open(self.evidence,os.O_RDONLY|os.O_DIRECTORY)
        self.j_fd=os.open(self.journal,os.O_RDONLY|os.O_DIRECTORY)
        self.addCleanup(os.close,self.e_fd);self.addCleanup(os.close,self.j_fd)
        evidence_path=os.path.join(self.evidence,'evidence-req1.json')
        with open(evidence_path,'wb') as f:f.write(b'{"synthetic":true}')
        os.chmod(evidence_path,0o600)
        es=os.stat(evidence_path);ed=os.fstat(self.e_fd);jd=os.fstat(self.j_fd)
        self.binding=BrokerBinding('run1','req1','collect_journal','fixture.journal',
            DirectoryIdentity(ed.st_dev,ed.st_ino,ed.st_uid,ed.st_gid),
            es.st_uid,es.st_gid,es.st_dev,es.st_ino,os.geteuid(),os.getegid())
        self.anchor=JournalAnchor(jd.st_dev,jd.st_ino,jd.st_uid,jd.st_gid)

    def sockets(self):
        a,b=socket.socketpair(socket.AF_UNIX,socket.SOCK_SEQPACKET)
        a.settimeout(2);b.settimeout(2)
        self.addCleanup(a.close);self.addCleanup(b.close)
        return a,b

    def send(self,sock,**extra):
        return send_pinned_evidence(sock,self.e_fd,self.binding,
            journal_dir_fd=self.j_fd,journal_anchor=self.anchor,**extra)

    def test_legacy_sender_without_journal_cannot_send(self):
        a,b=self.sockets()
        with self.assertRaisesRegex(Reject,'durable journal required'):
            send_pinned_evidence(a,self.e_fd,self.binding,used_ids=set())
        with self.assertRaises(socket.timeout): b.recv(1)
        self.assertEqual(os.listdir(self.journal),[])

    def test_real_scm_rights_after_durable_intent(self):
        a,b=self.sockets()
        received=[]
        def reader():
            received.append(receive_pinned_evidence(b,self.binding,broker_uid=os.geteuid()))
        thread=threading.Thread(target=reader);thread.start()
        self.send(a)
        thread.join(timeout=3)
        self.assertFalse(thread.is_alive())
        self.assertEqual(len(received),1)
        try:self.assertEqual(os.read(received[0],128),b'{"synthetic":true}')
        finally:os.close(received[0])
        names=os.listdir(self.journal)
        self.assertEqual(names,[_key(self.binding)])
        self.assertEqual(stat.S_IMODE(os.stat(os.path.join(self.journal,names[0])).st_mode),0o600)

    def test_new_connection_replay_rejected_after_restart(self):
        self._claim()
        os.close(self.j_fd)
        self.j_fd=os.open(self.journal,os.O_RDONLY|os.O_DIRECTORY)
        a,b=self.sockets()
        with self.assertRaisesRegex(Reject,'intent refused'):
            self.send(a)

    def _claim(self):
        claim_transfer(self.j_fd,self.anchor,self.binding)

    def test_ambiguous_failure_consumes_intent_before_any_send(self):
        a,b=self.sockets()
        with patch('evidence_broker._directory_check',side_effect=Reject('injected failure')):
            with self.assertRaisesRegex(Reject,'injected failure'):
                self.send(a)
        self.assertEqual(os.listdir(self.journal),[_key(self.binding)])
        c,d=self.sockets()
        with self.assertRaisesRegex(Reject,'intent refused'):
            self.send(c)

    def test_unauthorized_peer_cannot_claim(self):
        a,b=self.sockets()
        with patch('evidence_broker._peer',return_value=(1234,31023,31023)):
            with self.assertRaisesRegex(Reject,'unauthorized verifier'):
                self.send(a)
        self.assertEqual(os.listdir(self.journal),[])

if __name__=='__main__':unittest.main()

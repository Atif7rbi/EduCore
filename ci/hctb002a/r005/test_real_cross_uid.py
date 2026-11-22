"""S3-R003 cross-UID proof in a disposable, isolated UID-0 container only.

Not host Root, not a production launcher, and not Phase B authority.
All child processes discard supplementary groups and private inherited FDs.
"""
import hashlib
import os
import socket
import stat
import tempfile
import unittest
from hctb_contract import SCHEMA, canonical
from trusted_provisioner import ExternalVector, ProvisioningInputs, seal_pre_controller, finalize_broker_binding
from unprivileged_controller import EvidenceDirectoryAnchor, persist_evidence, request_for
from evidence_broker import send_pinned_evidence, receive_pinned_evidence, BrokerBinding, DirectoryIdentity, Reject as BrokerReject, TrustedAuthorizationRegistry
from durable_replay import JournalAnchor
from test_transaction_harness import TransactionHarness
from root_evidence_verifier import verify_received_file_fd

CONTROLLER = 31021
VERIFIER = 31022


def _drop(uid):
    os.setgroups([])
    os.setgid(uid)
    os.setuid(uid)
    assert (os.getuid(), os.geteuid(), os.getgid(), os.getegid()) == (uid, uid, uid, uid)


def _child_result(fd, value):
    os.write(fd, value.encode('ascii'))
    os.close(fd)
    os._exit(0)


@unittest.skipUnless(os.geteuid() == 0 and os.getegid() == 0,
                     'isolated container UID-0 required for real cross-UID test')
class CrossUidProcessBoundaryTests(unittest.TestCase):
    def test_real_wrong_verifier_uid_rejected_before_file_access(self):
        with tempfile.TemporaryDirectory(prefix='educore-crossuid-peer-') as home:
            os.chmod(home,0o755)
            journal=os.path.join(home,'transfer-journal')
            os.mkdir(journal,0o700)
            journal_fd=os.open(journal,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
            st=os.fstat(journal_fd)
            journal_anchor=JournalAnchor(st.st_dev,st.st_ino,st.st_uid,st.st_gid)
            self.addCleanup(os.close,journal_fd)
            address=os.path.join(home,'broker.sock')
            sock=socket.socket(socket.AF_UNIX,socket.SOCK_SEQPACKET)
            sock.bind(address);os.chmod(address,0o666)
            sock.listen(1);sock.settimeout(4)
            read_pipe,write_pipe=os.pipe()
            child=os.fork()
            if child==0:
                try:
                    os.close(read_pipe);sock.close()
                    _drop(31023)
                    peer=socket.socket(socket.AF_UNIX,socket.SOCK_SEQPACKET)
                    peer.settimeout(2);peer.connect(address)
                    try: peer.recv(1)
                    except (ConnectionResetError,TimeoutError): pass
                    peer.close();_child_result(write_pipe,'CHILD_CONNECTED')
                except BaseException as e:
                    _child_result(write_pipe,'CHILD_ERROR_'+type(e).__name__)
            os.close(write_pipe)
            try:
                conn,_=sock.accept();conn.settimeout(2)
                binding=BrokerBinding('run-1','case-1','collect_journal','fixture.journal',
                    DirectoryIdentity(1,1,0,0),31021,31021,1,1,VERIFIER,VERIFIER)
                with self.assertRaisesRegex(BrokerReject,'unauthorized verifier'):
                    send_pinned_evidence(conn,-1,binding,authorization=None)
                self.assertEqual(os.listdir(journal),[])
                conn.close()
                result=os.read(read_pipe,256).decode('ascii')
                _,status=os.waitpid(child,0)
                self.assertEqual(status,0)
                self.assertEqual(result,'CHILD_CONNECTED')
            finally:
                os.close(read_pipe);sock.close()

    def test_controller_dac_deny_and_broker_verified_one_fd(self):
        with tempfile.TemporaryDirectory(prefix='educore-crossuid-') as home:
            os.chmod(home, 0o755)
            fixture, evidence, trust, journal = [os.path.join(home, p) for p in ('fixture','evidence','trust','transfer-journal')]
            for p in (fixture, evidence, trust, journal):
                os.mkdir(p, 0o700)
            os.chown(evidence, CONTROLLER, CONTROLLER)
            with open(os.path.join(fixture, 'journal'), 'wb') as f:
                f.write(b'cross-UID fixture source bytes')
            os.chmod(os.path.join(fixture,'journal'), 0o600)
            fixture_fd = os.open(fixture, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            evidence_fd = os.open(evidence, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            trust_fd = os.open(trust, os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
            journal_fd = os.open(journal,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
            journal_stat = os.fstat(journal_fd)
            journal_anchor = JournalAnchor(journal_stat.st_dev,journal_stat.st_ino,
                                           journal_stat.st_uid,journal_stat.st_gid)
            try:
                inputs = ProvisioningInputs('run-1','case-1','collect_journal',CONTROLLER,CONTROLLER,VERIFIER,VERIFIER)
                vector = ExternalVector('1'*64,'fixture.journal', hashlib.sha256(b'cross-UID fixture source bytes').hexdigest())
                seed = seal_pre_controller(trust_fd,fixture_fd,evidence_fd,inputs,vector,
                                           provisioner_uid=0,provisioner_gid=0)
                request = request_for('case-1','collect_journal')
                response = {'schema': SCHEMA, 'request_id':'case-1', 'operation':'collect_journal',
                            'outcome':'PASS','evidence': {'source_identity':'fixture.journal',
                            'sha256': vector.expected_source_sha256,'verification_status':'VERIFIED'},
                            'reasons':[],'acceptance_authority':False,'phase_b_authority':False}
                # A distinct controller process writes only within its own 0700 evidence directory.
                pipe_r,pipe_w = os.pipe()
                child = os.fork()
                if child == 0:
                    try:
                        os.close(pipe_r)
                        for fd in (fixture_fd, evidence_fd, trust_fd, journal_fd): os.close(fd)
                        _drop(CONTROLLER)
                        local = os.open(evidence,os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
                        st = os.fstat(local)
                        anchor = EvidenceDirectoryAnchor(st.st_dev,st.st_ino,st.st_uid,st.st_gid)
                        persist_evidence(local,anchor,request,response)
                        os.close(local)
                        _child_result(pipe_w,'CONTROLLER_PASS')
                    except BaseException as exc:
                        _child_result(pipe_w,'CONTROLLER_ERROR_'+type(exc).__name__)
                os.close(pipe_w)
                result = os.read(pipe_r,512).decode('ascii'); os.close(pipe_r)
                _,status = os.waitpid(child,0)
                self.assertEqual(status,0);self.assertEqual(result,'CONTROLLER_PASS')
                binding = finalize_broker_binding(trust_fd,fixture_fd,evidence_fd,seed)
                tx=TransactionHarness(journal,binding);self.addCleanup(tx.close)
                # AF_UNIX pathname socket is created by broker Root before verifier UID connects.
                address=os.path.join(home,'broker.sock')
                server = socket.socket(socket.AF_UNIX,socket.SOCK_SEQPACKET)
                server.bind(address);os.chmod(address,0o666)
                server.listen(1)
                server.settimeout(4)
                r,w=os.pipe()
                verifier_pid=os.fork()
                if verifier_pid==0:
                    try:
                        os.close(r);server.close()
                        for fd in (fixture_fd,evidence_fd,trust_fd,journal_fd,tx.fd):os.close(fd)
                        _drop(VERIFIER)
                        denied=0
                        for private_dir in (fixture,evidence,trust):
                            try:
                                local=os.open(private_dir,os.O_RDONLY|os.O_DIRECTORY)
                            except PermissionError:denied+=1
                            else:os.close(local)
                        if denied!=3: raise AssertionError('DAC private-directory denial failed')
                        client=socket.socket(socket.AF_UNIX,socket.SOCK_SEQPACKET)
                        client.settimeout(4);client.connect(address)
                        received=receive_pinned_evidence(client,binding,broker_uid=0)
                        data=verify_received_file_fd(received,binding,seed.reference)
                        client.close()
                        if data!=response: raise AssertionError('response mismatch')
                        _child_result(w,'VERIFIER_PASS_DAC_3')
                    except BaseException as exc:
                        _child_result(w,'VERIFIER_ERROR_'+type(exc).__name__)
                os.close(w)
                try:
                    conn,_=server.accept();conn.settimeout(4)
                    try:send_pinned_evidence(conn,evidence_fd,binding,authorization=tx.authorize(conn))
                    finally:conn.close()
                    vr=os.read(r,512).decode('ascii')
                finally:
                    os.close(r);server.close()
                _,status=os.waitpid(verifier_pid,0)
                self.assertEqual(status,0);self.assertEqual(vr,'VERIFIER_PASS_DAC_3')
                self.assertEqual(len(os.listdir(journal)),1)
                self.assertEqual(os.stat(evidence).st_mode & 0o777,0o700)
                self.assertEqual(os.stat(os.path.join(evidence,'evidence-case-1.json')).st_mode & 0o777,0o600)
            finally:
                for fd in (fixture_fd,evidence_fd,trust_fd,journal_fd): os.close(fd)

if __name__ == '__main__': unittest.main()

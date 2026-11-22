"""Focused R005-BI security regressions for the mandatory transaction path."""
import os
import socket
import tempfile
import threading
import unittest
from unittest.mock import patch

from durable_replay import (DurableIntent, JournalAnchor, Reject as DurableReject,
                            claim_transfer, send_pinned_evidence_durable)
from evidence_broker import (BrokerBinding, DirectoryIdentity, Reject,
                             _register_verified_transaction, harness_authorization_registry,
                             issue_harness_authorization,
                             send_pinned_evidence)
from hctb_contract import canonical
from test_transaction_harness import TransactionHarness


class BrokerSecurityRemediationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.evidence = os.path.join(self.tmp.name, 'evidence')
        self.journal = os.path.join(self.tmp.name, 'journal')
        os.mkdir(self.evidence, 0o700); os.mkdir(self.journal, 0o700)
        self.e_fd = os.open(self.evidence, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, self.e_fd)
        path = os.path.join(self.evidence, 'evidence-req1.json')
        with open(path, 'wb') as handle: handle.write(b'{"synthetic":true}')
        os.chmod(path, 0o600)
        source = os.stat(path); directory = os.fstat(self.e_fd)
        self.binding = BrokerBinding(
            'run1', 'req1', 'collect_journal', 'fixture.journal',
            DirectoryIdentity(directory.st_dev, directory.st_ino, directory.st_uid, directory.st_gid),
            source.st_uid, source.st_gid, source.st_dev, source.st_ino,
            os.geteuid(), os.getegid())
        self.tx = TransactionHarness(self.journal, self.binding)
        self.addCleanup(self.tx.close)

    def pair(self):
        left, right = socket.socketpair(socket.AF_UNIX, socket.SOCK_SEQPACKET)
        self.addCleanup(left.close); self.addCleanup(right.close)
        return left, right

    def test_caller_selected_registry_is_not_an_api(self):
        left, right = self.pair()
        with self.assertRaisesRegex(Reject, 'legacy sender arguments rejected'):
            send_pinned_evidence(left, self.e_fd, self.binding,
                                 authorization=object(), registry=object())
        self.assertEqual(os.listdir(self.journal), [])

    def test_forged_authorization_and_wrapper_bypass_are_rejected(self):
        left, right = self.pair()
        with self.assertRaisesRegex(Reject, 'unissued or consumed'):
            send_pinned_evidence(left, self.e_fd, self.binding, authorization=object())
        with self.assertRaisesRegex(Exception, 'unissued or consumed'):
            send_pinned_evidence_durable(left, self.e_fd, self.binding, authorization=object())
        self.assertEqual(os.listdir(self.journal), [])

    def test_readback_mismatch_rejects_before_digest_return(self):
        fd = os.open(self.journal, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, fd)
        st = os.fstat(fd); anchor = JournalAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        # A finite short read models an interrupted/substituted backing file;
        # it must fail before a digest can be returned.
        with patch('durable_replay.os.read', side_effect=[b'{"changed":true}', b'']):
            with self.assertRaisesRegex(DurableReject, 'persisted intent'):
                claim_transfer(fd, anchor, self.binding, sequence=1,
                               operation_identity=self.binding.request_id)

    def test_one_shot_consumption_is_atomic(self):
        left, right = self.pair()
        authorization = self.tx.authorize(left)
        registry = harness_authorization_registry()
        peer = (1, self.binding.verifier_uid, self.binding.verifier_gid)
        results = []
        lock = threading.Lock()

        def consume():
            try:
                registry.consume(authorization, self.binding, peer)
                value = 'consumed'
            except Reject:
                value = 'rejected'
            with lock: results.append(value)

        threads = [threading.Thread(target=consume), threading.Thread(target=consume)]
        for thread in threads: thread.start()
        for thread in threads: thread.join()
        self.assertCountEqual(results, ['consumed', 'rejected'])

    def test_registry_issue_requires_private_harness_token(self):
        left, right = self.pair()
        authorization = self.tx.authorize(left)
        registry = harness_authorization_registry()
        with self.assertRaisesRegex(Reject, 'unauthorized authorization issuance'):
            registry.issue(object(), self.binding, authorization.journal_anchor,
                           authorization.intent, authorization.predecessor,
                           authorization.receipt, left)

    def test_direct_issuance_helper_rejects_fabricated_proof(self):
        left, right = self.pair()
        fabricated = {'payload': {'operation_identity': self.binding.request_id}}
        with self.assertRaisesRegex(Reject, 'unverified or consumed transaction proof'):
            issue_harness_authorization(fabricated, left)
        self.assertEqual(os.listdir(self.journal), [])

    def test_direct_registration_fabrication_cannot_authorize_or_send(self):
        """A direct private-helper call lacks a verified TAA CAS completion."""
        left, right = self.pair()
        predecessor = self.tx.taa.get_head(self.tx.read)
        fabricated_intent = DurableIntent('fabricated.intent', b'{}', '0' * 64,
                                          1, self.binding.request_id)
        fabricated_receipt = {'payload': {}, 'signature': '0' * 64}
        with self.assertRaisesRegex(Reject, 'unverified transaction completion'):
            _register_verified_transaction(self.binding, self.tx.anchor, fabricated_intent,
                                           predecessor, fabricated_receipt,
                                           taa=self.tx.taa, read_capability=self.tx.read)
        with self.assertRaisesRegex(Reject, 'unverified or consumed transaction proof'):
            issue_harness_authorization(object(), left)
        with self.assertRaisesRegex(Reject, 'unissued or consumed'):
            send_pinned_evidence(left, self.e_fd, self.binding, authorization=None)
        right.settimeout(0.05)
        with self.assertRaises(socket.timeout):
            right.recv(1)

    def test_journal_intent_without_cas_is_orphan_and_blocks_next_claim(self):
        fd = os.open(self.journal, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, fd)
        st = os.fstat(fd); anchor = JournalAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        # Models interruption after both journal fsyncs but before TAA CAS.
        claim_transfer(fd, anchor, self.binding, sequence=1,
                       operation_identity=self.binding.request_id)
        left, right = self.pair()
        with self.assertRaisesRegex(Exception, 'journal and authority divergence'):
            self.tx.authorize(left)

    def test_authority_intent_without_pinned_journal_is_rejected(self):
        raw = canonical({'schema':'educore.hctb002a.transfer-intent.v1',
                         'run_id':self.binding.run_id, 'request_id':self.binding.request_id,
                         'operation':self.binding.operation, 'source_identity':self.binding.source_identity,
                         'evidence_device':self.binding.evidence_device,
                         'evidence_inode':self.binding.evidence_inode, 'sequence':1,
                         'operation_identity':self.binding.request_id,
                         'acceptance_authority':False, 'phase_b_authority':False,
                         'status':'INTENT_NO_RETRY'})
        self.tx.taa.persist_intent(self.tx.update, self.binding.request_id, raw)
        left, right = self.pair()
        with self.assertRaisesRegex(Exception, 'receipt|divergence'):
            self.tx.authorize(left)


if __name__ == '__main__':
    unittest.main()

"""R007 adversarial regressions for Reader, Controller and Verifier decoding."""
import json
import socket
import struct
import threading
import time
import unittest
from unittest.mock import patch

from hctb_contract import SCHEMA, canonical
from root_evidence_reader import handle, Reject as ReaderReject
from root_evidence_verifier import _parse_and_verify, TrustedReference, Reject as VerifierReject
from unprivileged_controller import receive_response, request_for, Reject as ControllerReject
from strict_json import strict_json_loads

Q = request_for('case-1', 'collect_journal')
R = {'schema': SCHEMA, 'request_id': 'case-1', 'operation': 'collect_journal',
     'outcome': 'PASS', 'evidence': {'source_identity': 'fixture.journal',
     'sha256': 'a'*64, 'verification_status': 'VERIFIED'}, 'reasons': [],
     'acceptance_authority': False, 'phase_b_authority': False}
REF = TrustedReference('case-1', 'collect_journal', 'fixture.journal', 'a'*64)


class StrictParserTests(unittest.TestCase):
    def test_duplicate_top_level_request(self):
        with self.assertRaisesRegex(ValueError, 'duplicate'):
            strict_json_loads(b'{"schema":1,"schema":2}')

    def test_duplicate_nested_response(self):
        with self.assertRaisesRegex(ValueError, 'duplicate'):
            strict_json_loads(b'{"evidence":{"sha256":"a","sha256":"b"}}')

    def test_duplicate_same_value_still_rejected(self):
        with self.assertRaisesRegex(ValueError, 'duplicate'):
            strict_json_loads(b'{"x":1,"x":1}')

    def test_nonfinite_variants(self):
        for constant in (b'NaN', b'Infinity', b'-Infinity'):
            with self.subTest(constant=constant):
                with self.assertRaisesRegex(ValueError, 'nonfinite'):
                    strict_json_loads(b'{"outer":[{"v":'+constant+b'}]}')

    def test_malformed_utf8(self):
        with self.assertRaises(ValueError):
            strict_json_loads(b'\xff')

    def test_malformed_json(self):
        with self.assertRaises(ValueError):
            strict_json_loads(b'{')

    def test_valid_canonical(self):
        self.assertEqual(strict_json_loads(canonical(R)), R)

    def test_reader_rejects_duplicate_before_source_read(self):
        self._reader_reject(b'{"schema":"x","schema":"y"}')

    def test_reader_rejects_nonfinite_before_source_read(self):
        self._reader_reject(canonical(Q)[:-1] + b',"x":NaN}')

    def _reader_reject(self, payload):
        left, right = socket.socketpair()
        self.addCleanup(left.close)
        self.addCleanup(right.close)
        frame = struct.pack('!I', len(payload)) + payload
        left.sendall(frame)
        left.shutdown(socket.SHUT_WR)
        with patch('root_evidence_reader.peer_uid', return_value=1234), \
             patch('root_evidence_reader.read_source', side_effect=AssertionError('source read attempted')) as source:
            with self.assertRaises((ReaderReject, ValueError)):
                handle(right, 1234, -1, 0, trusted_root=object())
            source.assert_not_called()

    def test_controller_rejects_nonfinite_response(self):
        self._controller_reject(canonical(R)[:-1] + b',"extra":NaN}')

    def test_controller_rejects_duplicate_nested_response(self):
        raw = canonical(R)
        raw = raw.replace(b'"verification_status":"VERIFIED"', b'"verification_status":"VERIFIED","verification_status":"VERIFIED"')
        self._controller_reject(raw)

    def _controller_reject(self, body):
        left, right = socket.socketpair()
        self.addCleanup(left.close)
        self.addCleanup(right.close)
        right.sendall(struct.pack('!I', len(body)) + body)
        right.shutdown(socket.SHUT_WR)
        with self.assertRaises(ControllerReject):
            receive_response(left, Q, time.monotonic()+1)

    def test_verifier_rejects_nonfinite_evidence(self):
        with self.assertRaises(VerifierReject):
            _parse_and_verify(canonical(R)[:-1] + b',"extra":Infinity}', REF)

    def test_verifier_rejects_duplicate_nested_evidence(self):
        raw = canonical(R).replace(b'"sha256":"'+b'a'*64+b'"', b'"sha256":"'+b'a'*64+b'","sha256":"'+b'a'*64+b'"')
        with self.assertRaises(VerifierReject):
            _parse_and_verify(raw, REF)


if __name__ == '__main__':
    unittest.main()

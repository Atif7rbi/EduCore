"""Source-only framing tests: no privileged identities or host sockets."""
import json
import os
import socket
import struct
import tempfile
import time
import unittest
from unittest.mock import patch

from hctb_contract import SCHEMA
from root_evidence_reader import handle, recv_frame, MAX_MESSAGE, Reject, FixtureTrustAnchor


def packet(payload):
    return struct.pack('!I', len(payload)) + payload


class FramingTests(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory()
        self.addCleanup(self.fixture.cleanup)
        self.root_fd = os.open(self.fixture.name, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, self.root_fd)
        self.uid = os.geteuid()
        os.chmod(self.fixture.name, 0o700)
        st = os.fstat(self.root_fd)
        self.anchor = FixtureTrustAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        with open(os.path.join(self.fixture.name, 'journal'), 'wb') as f:
            f.write(b'journal sample')
        os.chmod(os.path.join(self.fixture.name, 'journal'), 0o600)
        self.left, self.right = socket.socketpair()
        self.addCleanup(self.left.close)
        self.addCleanup(self.right.close)
        self.request = json.dumps({'schema': SCHEMA, 'request_id': 'case-1',
                                   'operation': 'collect_journal'}).encode()

    def send(self, data, finish=True):
        self.right.sendall(data)
        if finish:
            self.right.shutdown(socket.SHUT_WR)

    def handle(self):
        # Monkeypatch peer identity only; this does not test SO_PEERCRED runtime.
        with patch('root_evidence_reader.peer_uid', return_value=self.uid):
            return handle(self.left, self.uid, self.root_fd, self.uid, trusted_root=self.anchor)

    def test_valid_single_frame(self):
        self.send(packet(self.request))
        response = self.handle()
        self.assertEqual(response['outcome'], 'PASS')
        self.assertFalse(response['acceptance_authority'])
        self.assertFalse(response['phase_b_authority'])

    def test_fragmented_header(self):
        import threading
        def writer():
            data = packet(self.request)
            for b in data[:4]:
                self.right.sendall(bytes([b]))
                time.sleep(.002)
            self.right.sendall(data[4:])
            self.right.shutdown(socket.SHUT_WR)
        t = threading.Thread(target=writer)
        t.start()
        try:
            self.assertEqual(self.handle()['outcome'], 'PASS')
        finally:
            t.join(timeout=1)
            self.assertFalse(t.is_alive())

    def test_fragmented_payload(self):
        import threading
        def writer():
            data = packet(self.request)
            self.right.sendall(data[:7])
            time.sleep(.01)
            self.right.sendall(data[7:])
            self.right.shutdown(socket.SHUT_WR)
        t = threading.Thread(target=writer)
        t.start()
        try:
            self.assertEqual(self.handle()['outcome'], 'PASS')
        finally:
            t.join(timeout=1)
            self.assertFalse(t.is_alive())

    def test_empty_payload(self):
        self.send(struct.pack('!I', 0))
        with self.assertRaises(Reject): self.handle()

    def test_oversize_declared_payload(self):
        self.send(struct.pack('!I', MAX_MESSAGE + 1))
        with self.assertRaises(Reject): self.handle()

    def test_truncated_header(self):
        self.send(b'\x00\x01')
        with self.assertRaisesRegex(Reject, 'truncated'): self.handle()

    def test_truncated_payload(self):
        self.send(struct.pack('!I', 100) + b'abc')
        with self.assertRaisesRegex(Reject, 'truncated'): self.handle()

    def test_trailing_bytes(self):
        self.send(packet(self.request) + b'x')
        with self.assertRaisesRegex(Reject, 'trailing'): self.handle()

    def test_second_frame_rejected(self):
        self.send(packet(self.request) + packet(self.request))
        with self.assertRaisesRegex(Reject, 'trailing'): self.handle()

    def test_malformed_utf8(self):
        self.send(packet(b'\xff'))
        with self.assertRaisesRegex(Reject, 'json'): self.handle()

    def test_malformed_json(self):
        self.send(packet(b'{'))
        with self.assertRaisesRegex(Reject, 'json'): self.handle()

    def test_timeout_when_sender_does_not_half_close(self):
        self.send(packet(self.request), finish=False)
        with patch('root_evidence_reader.IPC_TOTAL_DEADLINE_SECONDS', .03):
            with self.assertRaisesRegex(Reject, 'deadline'): self.handle()

    def test_timeout_on_stalled_header(self):
        self.right.sendall(b'\x00')
        with patch('root_evidence_reader.IPC_TOTAL_DEADLINE_SECONDS', .03):
            with self.assertRaisesRegex(Reject, 'deadline'): self.handle()

    def test_unauthorized_peer_rejected_before_receive(self):
        with patch('root_evidence_reader.peer_uid', return_value=self.uid + 1), \
             patch('root_evidence_reader.recv_frame', side_effect=AssertionError('frame read occurred')):
            with self.assertRaisesRegex(Reject, 'peer'):
                handle(self.left, self.uid, self.root_fd, self.uid, trusted_root=self.anchor)

    def test_unsupported_operation_before_filesystem(self):
        request = {'schema': SCHEMA, 'request_id': 'case-2', 'operation': 'collect_identity'}
        self.send(packet(json.dumps(request).encode()))
        with patch('root_evidence_reader.os.open', side_effect=AssertionError('file opened')):
            with self.assertRaisesRegex(Reject, 'unsupported'):
                self.handle()

    def test_unexpected_request_field_rejected(self):
        request = {'schema': SCHEMA, 'request_id': 'case-2',
                   'operation': 'collect_journal', 'path': '/tmp/escape'}
        self.send(packet(json.dumps(request).encode()))
        with self.assertRaises(ValueError): self.handle()

    def test_maximum_payload_boundary(self):
        # Valid framing for 4096 bytes; payload need not be valid JSON.
        self.send(packet(b' ' * MAX_MESSAGE))
        got = recv_frame(self.left, time.monotonic() + 1)
        self.assertEqual(len(got), MAX_MESSAGE)


if __name__ == '__main__':
    unittest.main()

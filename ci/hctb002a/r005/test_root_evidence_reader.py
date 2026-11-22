import os
import tempfile
import unittest
from root_evidence_reader import read_source, Reject, MAX_SOURCE_SIZE, MAX_MESSAGE, FixtureTrustAnchor

class T(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = os.path.join(self.tmp.name, 'journal')
        self.fd = os.open(self.tmp.name, os.O_RDONLY | os.O_DIRECTORY)
        self.addCleanup(os.close, self.fd)
        self.uid = os.geteuid()
        os.chmod(self.tmp.name, 0o700)
        st = os.fstat(self.fd)
        self.anchor = FixtureTrustAnchor(st.st_dev, st.st_ino, st.st_uid, st.st_gid)
        self.write(b'x')

    def write(self, b):
        with open(self.path, 'wb') as f: f.write(b)
        os.chmod(self.path, 0o600)

    def read(self, **kw):
        return read_source(self.fd, 'collect_journal', self.uid, trusted_root=self.anchor, **kw)

    def test_allowlist(self):
        self.assertEqual(self.read(), b'x')
    def test_bad_path(self):
        for name in ('../x', 'collect_unknown'):
            with self.subTest(name=name), self.assertRaises(Reject):
                read_source(self.fd, name, self.uid, trusted_root=self.anchor)
    def test_symlink_metadata(self):
        os.unlink(self.path)
        os.symlink('/etc/passwd', self.path)
        with self.assertRaises(Reject): self.read()
    def test_mode(self):
        os.chmod(self.path, 0o644)
        with self.assertRaises(Reject): self.read()
    def test_empty(self):
        self.write(b'')
        self.assertEqual(self.read(), b'')
    def test_normal(self):
        data = bytes(range(256)) * 6
        self.write(data)
        self.assertEqual(self.read(), data)
    def test_larger_than_ipc(self):
        data = bytes(range(256)) * 20
        self.assertGreater(len(data), MAX_MESSAGE)
        self.write(data)
        self.assertEqual(self.read(), data)
    def test_exact_limit(self):
        data = bytes(range(256)) * (MAX_SOURCE_SIZE // 256)
        self.write(data)
        self.assertEqual(self.read(), data)
    def test_oversize(self):
        self.write(b'x' * (MAX_SOURCE_SIZE + 1))
        with self.assertRaises(Reject): self.read()
    def test_interrupted(self):
        data = b'hello' * 100
        self.write(data)
        attempts = 0
        def reader(fd, n):
            nonlocal attempts
            attempts += 1
            if attempts == 1: raise InterruptedError()
            return os.read(fd, min(n, 13))
        self.assertEqual(self.read(read_fn=reader), data)
        self.assertGreater(attempts, 2)
    def test_premature_eof(self):
        self.write(b'x' * 100)
        times = 0
        def reader(fd, n):
            nonlocal times
            times += 1
            return os.read(fd, 10) if times == 1 else b''
        with self.assertRaises(Reject): self.read(read_fn=reader)
    def test_modified_during_read(self):
        self.write(b'x' * 100)
        mutated = False
        def reader(fd, n):
            nonlocal mutated
            if not mutated:
                mutated = True
                with open(self.path, 'ab') as f: f.write(b'y')
            return os.read(fd, n)
        with self.assertRaises(Reject): self.read(read_fn=reader)
    def test_descriptor_closed_on_success(self):
        seen = []
        def reader(fd, n):
            seen.append(fd)
            return os.read(fd, n)
        self.assertEqual(self.read(read_fn=reader), b'x')
        with self.assertRaises(OSError): os.fstat(seen[0])
    def test_descriptor_closed_on_failure(self):
        seen = []
        def reader(fd, n):
            seen.append(fd)
            raise RuntimeError('injected')
        with self.assertRaises(RuntimeError): self.read(read_fn=reader)
        with self.assertRaises(OSError): os.fstat(seen[0])

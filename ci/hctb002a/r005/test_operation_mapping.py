import os,tempfile,unittest
from root_evidence_reader import *
from hctb_contract import OPERATIONS
class T(unittest.TestCase):
 def setUp(self):
  self.d=tempfile.TemporaryDirectory();os.chmod(self.d.name,0o700);self.fd=os.open(self.d.name,os.O_RDONLY)
  st=os.fstat(self.fd);self.anchor=FixtureTrustAnchor(st.st_dev,st.st_ino,st.st_uid,st.st_gid)
  for n in SOURCES.values():
   with open(self.d.name+"/"+n,"wb") as h:h.write(n.encode())
   os.chmod(self.d.name+"/"+n,0o600)
 def tearDown(self):os.close(self.fd);self.d.cleanup()
 def test_contract_partition(self):self.assertEqual(SUPPORTED_OPERATIONS|UNSUPPORTED_OPERATIONS,OPERATIONS)
 def test_all_supported(self):
  for op in SUPPORTED_OPERATIONS:self.assertEqual(read_source(self.fd,op,os.geteuid(),trusted_root=self.anchor),SOURCES[op].encode())
 def test_all_unsupported_before_read(self):
  for op in UNSUPPORTED_OPERATIONS:
   with self.assertRaises(Reject):read_source(-1,op,os.geteuid(),trusted_root=self.anchor)
 def test_unknown(self):
  with self.assertRaises(Reject):read_source(self.fd,"collect_shell",os.geteuid(),trusted_root=self.anchor)

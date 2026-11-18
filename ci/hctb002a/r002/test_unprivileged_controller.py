import json, os, socket, struct, tempfile, threading, time, unittest
from dataclasses import replace
from unittest.mock import patch
from hctb_contract import SCHEMA, canonical
from unprivileged_controller import (request_for, receive_response, exchange,
    persist_evidence, verify_evidence_directory, EvidenceDirectoryAnchor, Reject, MAX_MESSAGE)

Q = request_for('case-1','collect_journal')
def response(**kw):
    return {'schema':SCHEMA,'request_id':'case-1','operation':'collect_journal',
        'outcome':'PASS','evidence':{'source_identity':'fixture.journal','sha256':'a'*64,'verification_status':'VERIFIED'},
        'reasons':[],'acceptance_authority':False,'phase_b_authority':False, **kw}
def frame(body): return struct.pack('!I',len(body))+body

class ControllerTests(unittest.TestCase):
    def setUp(self):
        self.a,self.b=socket.socketpair()
        self.addCleanup(self.a.close);self.addCleanup(self.b.close)
    def serve(self, data, fragment=False, finish=True):
        def writer():
            if fragment:
                for chunk in (data[:1],data[1:3],data[3:10],data[10:]):
                    self.b.sendall(chunk)
            else:self.b.sendall(data)
            if finish:self.b.shutdown(socket.SHUT_WR)
        t=threading.Thread(target=writer);t.start();self.addCleanup(t.join)
    def test_valid_response(self):
        self.serve(frame(canonical(response())))
        self.assertEqual(receive_response(self.a,Q,time.monotonic()+1)['outcome'],'PASS')
    def test_fragmented_response(self):
        self.serve(frame(canonical(response())),fragment=True)
        self.assertEqual(receive_response(self.a,Q,time.monotonic()+1)['outcome'],'PASS')
    def test_identity_mismatch(self):
        self.serve(frame(canonical(response(request_id='different'))))
        with self.assertRaises(ValueError):receive_response(self.a,Q,time.monotonic()+1)
    def test_authority_promotion(self):
        self.serve(frame(canonical(response(phase_b_authority=True))))
        with self.assertRaises(ValueError):receive_response(self.a,Q,time.monotonic()+1)
    def test_nested_extra_field(self):
        bad=response();bad['evidence']['payload']='leak'
        self.serve(frame(canonical(bad)))
        with self.assertRaises(ValueError):receive_response(self.a,Q,time.monotonic()+1)
    def test_response_operation_mismatch(self):
        self.serve(frame(canonical(response(operation='collect_archive'))))
        with self.assertRaises(ValueError):receive_response(self.a,Q,time.monotonic()+1)
    def test_duplicate_json_key(self):
        body=canonical(response())[:-1]+b',"schema":"duplicate"}'
        self.serve(frame(body))
        with self.assertRaisesRegex(Reject,'duplicate json key'):receive_response(self.a,Q,time.monotonic()+1)
    def test_invalid_utf8(self):
        self.serve(frame(b'\xff'))
        with self.assertRaisesRegex(Reject,'invalid response json'):receive_response(self.a,Q,time.monotonic()+1)
    def test_empty_frame(self):
        self.serve(struct.pack('!I',0))
        with self.assertRaisesRegex(Reject,'frame size'):receive_response(self.a,Q,time.monotonic()+1)
    def test_invalid_sha(self):
        bad=response();bad['evidence']['sha256']='BAD'
        self.serve(frame(canonical(bad)))
        with self.assertRaises(ValueError):receive_response(self.a,Q,time.monotonic()+1)
    def test_truncated_header(self):
        self.serve(b'\x00\x00')
        with self.assertRaisesRegex(Reject,'truncated'):receive_response(self.a,Q,time.monotonic()+1)
    def test_truncated_body(self):
        self.serve(struct.pack('!I',100)+b'abc')
        with self.assertRaisesRegex(Reject,'truncated'):receive_response(self.a,Q,time.monotonic()+1)
    def test_oversized_frame(self):
        self.serve(struct.pack('!I',MAX_MESSAGE+1))
        with self.assertRaisesRegex(Reject,'frame size'):receive_response(self.a,Q,time.monotonic()+1)
    def test_trailing_data(self):
        self.serve(frame(canonical(response()))+b'x')
        with self.assertRaisesRegex(Reject,'trailing'):receive_response(self.a,Q,time.monotonic()+1)
    def test_timeout(self):
        self.b.sendall(b'\x00')
        with self.assertRaisesRegex(Reject,'deadline'):receive_response(self.a,Q,time.monotonic()+.02)
    def test_request_framing_halfclose(self):
        observed=[]
        def server():
            raw=self.b.recv(4096)
            observed.append(raw)
            self.assertEqual(self.b.recv(1),b'')
            self.b.sendall(frame(canonical(response())))
            self.b.shutdown(socket.SHUT_WR)
        thread=threading.Thread(target=server);thread.start()
        try: self.assertEqual(exchange(self.a,Q)['outcome'],'PASS')
        finally:thread.join(timeout=1)
        self.assertEqual(struct.unpack('!I',observed[0][:4])[0],len(observed[0])-4)
    def test_request_rejects_extra_fields(self):
        with self.assertRaises(Reject):exchange(self.a,{**Q,'path':'/etc'})

class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        os.chmod(self.tmp.name,0o700)
        self.fd=os.open(self.tmp.name,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        self.addCleanup(os.close,self.fd)
        st=os.fstat(self.fd)
        self.anchor=EvidenceDirectoryAnchor(st.st_dev,st.st_ino,st.st_uid,st.st_gid)
    def test_write_only_typed(self):
        name=persist_evidence(self.fd,self.anchor,Q,response())
        path=os.path.join(self.tmp.name,name)
        with open(path,'rb') as f:content=json.load(f)
        self.assertFalse(content['phase_b_authority'])
        self.assertEqual(os.stat(path).st_mode & 0o777,0o600)
        self.assertEqual(set(content),set(response()))
    def test_no_overwrite(self):
        persist_evidence(self.fd,self.anchor,Q,response())
        with self.assertRaisesRegex(Reject,'create refused'):persist_evidence(self.fd,self.anchor,Q,response())
    def test_symlink_existing_rejected(self):
        os.symlink('/etc/passwd',os.path.join(self.tmp.name,'evidence-case-1.json'))
        with self.assertRaises(Reject):persist_evidence(self.fd,self.anchor,Q,response())
    def test_directory_mode_rejected(self):
        os.chmod(self.tmp.name,0o750)
        with self.assertRaises(Reject):persist_evidence(self.fd,self.anchor,Q,response())
    def test_no_anchor(self):
        with self.assertRaises(Reject):persist_evidence(self.fd,None,Q,response())
    def test_wrong_identity(self):
        with self.assertRaises(Reject):verify_evidence_directory(self.fd,replace(self.anchor,inode=self.anchor.inode+1))
    def test_bad_response_no_write(self):
        with self.assertRaises(ValueError):persist_evidence(self.fd,self.anchor,Q,response(acceptance_authority=True))
        self.assertEqual(os.listdir(self.tmp.name),[])
    def test_no_arbitrary_path(self):
        bad={**Q,'request_id':'../escape'}
        with self.assertRaises(Reject):persist_evidence(self.fd,self.anchor,bad,response())
    def test_bad_file_descriptor(self):
        with self.assertRaises(Reject):verify_evidence_directory(-1,self.anchor)

if __name__=='__main__':unittest.main()

#!/usr/bin/env python3
from __future__ import annotations
import argparse,json,os,pathlib,socket,struct,hashlib,stat
REPLAY_REJECTED=75
class ReplayRejected(Exception):pass
def bad(x):raise RuntimeError(x)
def child(d,n,f):
 if not n or '/'in n or n in('.','..'):raise ValueError('containment')
 return os.open(n,f|os.O_NOFOLLOW,0o600,dir_fd=d)
def write(d,n,b):
 fd=child(d,n,os.O_WRONLY|os.O_CREAT|os.O_EXCL)
 try:
  i=0
  while i<len(b):
   k=os.write(fd,b[i:]);
   if k<=0:bad('short');i+=k
   else:i+=k
  os.fsync(fd)
 finally:os.close(fd)
 os.fsync(d)
def server(a):
 p=pathlib.Path(a.socket);p.unlink(missing_ok=True);s=socket.socket(socket.AF_UNIX);s.settimeout(8);s.bind(str(p));os.chmod(p,0o666);s.listen(3);pathlib.Path(a.ready).write_text('READY\n');got=[]
 for _ in range(3):
  c,_=s.accept()
  with c:
   try:
    uid=struct.unpack('3i',c.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,12))[1];v=json.loads(c.recv(256));ok=uid==a.controller_uid and v=={'op':'validate-intermediate'};c.sendall(b'OK'if ok else b'DENY');got.append(ok)
   except Exception:c.sendall(b'DENY');got.append(False)
 s.close();p.unlink(missing_ok=True)
 if got!=[True,False,False]:bad('peer')
 pathlib.Path(a.result).write_text('IPC_PEERCRED=PASS\n')
def client(a):
 s=socket.socket(socket.AF_UNIX);s.settimeout(5);s.connect(a.socket);s.sendall(a.payload.encode());r=s.recv(8);s.close()
 if(a.authorized=='yes')!=(r==b'OK'):bad('client')
def controller(a):
 d=os.open(a.evidence,os.O_RDONLY|os.O_DIRECTORY)
 try:
  if a.interrupt:write(d,'intent',b'INTENT');print('INTERRUPTED');return
  if 'intent'in os.listdir(a.evidence):raise ReplayRejected('replay')
  write(d,'intermediate.json',json.dumps({'schema':'educore.g2.pa001.reconciliation-evidence-intermediate.v1','phase_b_authorized':False,'not_acceptance_evidence':True}).encode())
  for n in('../journal-trust/journal','/etc/passwd',''):
   try:child(d,n,os.O_RDONLY);bad('traversal')
   except(ValueError,OSError):pass
 finally:os.close(d)
def verifier(a):
 r=pathlib.Path(a.root)
 jd=os.open(r/'journal-trust',os.O_RDONLY|os.O_DIRECTORY)
 jfd=child(jd,'journal',os.O_RDONLY)
 try:
  jm=os.fstat(jfd);before=os.read(jfd,65536)
 finally:os.close(jfd);os.close(jd)
 d=os.open(r/'evidence',os.O_RDONLY|os.O_DIRECTORY)
 fd=child(d,'intermediate.json',os.O_RDONLY)
 try:
  m=os.fstat(fd);content=os.read(fd,65536);v=json.loads(content)
 finally:os.close(fd);os.close(d)
 if not stat.S_ISREG(jm.st_mode) or jm.st_uid!=0 or stat.S_IMODE(jm.st_mode)!=0o600:bad('journal-metadata')
 if hashlib.sha256(before).hexdigest()!=a.journal_sha or hashlib.sha256(content).hexdigest()!=a.evidence_sha:bad('content-digest')
 if not stat.S_ISREG(m.st_mode)or m.st_uid!=a.controller_uid or stat.S_IMODE(m.st_mode)!=0o600:bad('metadata')
 expected={'schema':'educore.g2.pa001.reconciliation-evidence-intermediate.v1','phase_b_authorized':False,'not_acceptance_evidence':True}
 if v!=expected or (r/'evidence/acceptance.json').exists() or (r/'evidence/phase-b-capability').exists():bad('evidence-schema')
 print('PA001_PROCESS_BOUNDARY=PASS')
p=argparse.ArgumentParser();s=p.add_subparsers(dest='mode',required=True)
for n in('server','client','controller','verifier'):s.add_parser(n)
s.choices['server'].add_argument('--socket',required=True);s.choices['server'].add_argument('--controller-uid',type=int,required=True);s.choices['server'].add_argument('--result',required=True);s.choices['server'].add_argument('--ready',required=True)
s.choices['client'].add_argument('--socket',required=True);s.choices['client'].add_argument('--authorized',required=True);s.choices['client'].add_argument('--payload',required=True)
s.choices['controller'].add_argument('--evidence',required=True);s.choices['controller'].add_argument('--interrupt',action='store_true');s.choices['verifier'].add_argument('--root',required=True);s.choices['verifier'].add_argument('--journal-sha',required=True);s.choices['verifier'].add_argument('--evidence-sha',required=True);s.choices['verifier'].add_argument('--controller-uid',type=int,required=True)
a=p.parse_args()
try:{'server':server,'client':client,'controller':controller,'verifier':verifier}[a.mode](a)
except ReplayRejected:print('REPLAY_REJECTED');raise SystemExit(REPLAY_REJECTED)

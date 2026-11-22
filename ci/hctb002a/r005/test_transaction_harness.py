"""Shared synthetic fixture for R005 migrated sender tests."""
import os, tempfile
from durable_replay import JournalAnchor, trusted_writer_commit_and_authorize_transfer
from journal_anchor import provision_synthetic_authority

class TransactionHarness:
    def __init__(self, journal_path, binding):
        self.key=b"r005-track-a-test-key"
        self.taa,(self.provision,self.update,self.read)=provision_synthetic_authority(self.key)
        self.taa.register_genesis(self.provision,{"schema":"educore.hctb002a.genesis.v1","journal_id":"r005-test-journal","source_revision":"synthetic","fixture_identity":"fixture","genesis_format_version":"1"})
        self.fd=os.open(journal_path,os.O_RDONLY|os.O_DIRECTORY)
        st=os.fstat(self.fd); self.anchor=JournalAnchor(st.st_dev,st.st_ino,st.st_uid,st.st_gid)
        self.binding=binding
    def close(self): os.close(self.fd)
    def authorize(self, conn, binding=None):
        binding=self.binding if binding is None else binding
        return trusted_writer_commit_and_authorize_transfer(conn,binding,journal_dir_fd=self.fd,journal_anchor=self.anchor,taa=self.taa,update_capability=self.update,read_capability=self.read)


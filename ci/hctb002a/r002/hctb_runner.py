"""Future GitHub-runner-only harness contract; no local execution entrypoint."""
from hctb_contract import (
    validate_request,
    validate_evidence_only_response,
)
STAGES=("PREFLIGHT","IDENTITIES","FIXTURES","IPC","VERIFIER","CLEANUP")
def refusal_preflight(existing_identities, existing_root):
    if existing_identities or existing_root: raise RuntimeError("HCTB_STAGE=PREFLIGHT unexpected pre-existing resource")
    return True

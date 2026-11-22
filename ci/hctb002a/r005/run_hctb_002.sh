#!/usr/bin/env bash
# Future ephemeral runner only.  Intentionally refuses local execution.
set -euo pipefail
echo 'HCTB_STAGE=PREFLIGHT future GitHub-hosted runner execution required' >&2
exit 64

# HCTB-002A runner preparation

This is a review-only proposal, separate from the frozen PA001 workflow. Its
future manual-only GitHub-hosted Ubuntu 24.04 run creates disposable synthetic
identities: Root Reader, `educore-uat-reconcile` Controller, and Root Verifier.
It refuses identity/root collisions, records fixed stages, and cleans only
resources registered as created by that run; cleanup failure is nonzero.

Fixture contract: journal/trust `root:root 0700`, files `0600`; controller
evidence `educore-uat-reconcile 0700`; IPC directory
`root:educore-uat-reconcile 0750`; socket root-owned `0660`. The future server
checks SO_PEERCRED before decoding the fixed HCTB schema. No network, secrets,
real host paths, authority issuance, reconciliation, or Phase-B path exists.

Local execution is deliberately refused. Cross-UID/DAC/SO_PEERCRED tests are
future runner acceptance only.

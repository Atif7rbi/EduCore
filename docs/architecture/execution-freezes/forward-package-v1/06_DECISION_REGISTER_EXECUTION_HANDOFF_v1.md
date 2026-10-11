# EduCore — Decision Register, Reconciliation Gates & Future Codex Handoff

**Document ID:** DR-001  
**Version:** 1.0 · **Date:** 2026-10-11  
**Repository:** `Atif7rbi/EduCore` · **Evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Decision authority:** User-authorized architecture preparation and approval.  
**Approval class:** APPROVED POLICY / CONDITIONAL EXECUTION FREEZE. Inherited FROZEN rules retain precedence. An unresolved contract must not be presented as verified or executable.  
**Execution authorization:** NONE. No Codex run, implementation, branch, commit, push, PR change, merge or deployment.

## Formal decision index
| Document | Approved today | Remaining verification before executable |
|---|---|---|
| 01 Forward Architecture | Governance, authority and authorization limits | None for documentation policy |
| 02 H6-S6 | Nonregression/UX/authorization constraints | Actual frozen H6-S6 requirements and API contracts |
| 03 H6-S7 | Integration matrix and acceptance evidence | S6 implementation, test environment and actual cases |
| 04 Learner Gap | Audit method, no duplication, historical/role guards | Endpoint-by-endpoint gap audit |
| 05 Pilot | Minimal journey and delivery gates | Participants/content/runtime and UAT thresholds |

## Decision escalation protocol
`STOP → locate exact conflict (code/spec/route/test) → cite authoritative passage and deferred decision ID → provide options with DB/history/security/UX impact → independent review → user approves specific amendment → package update → resume only after separate start authorization`.

## Future Codex launch checklist (not an instruction to start)
- [ ] Named package has `EXECUTION READY` decision after evidence reconciliation.
- [ ] User authorizes start of that package and exact scope.
- [ ] Base SHA, branch, remote, clean worktree checked.
- [ ] Test DB isolated and PostgreSQL verified.
- [ ] All API schemas/lifecycle matrices confirmed; no G4/DD blocker.
- [ ] Package provides acceptance tests and exact non-execution boundaries.
- [ ] Git permissions separately stated; no merge or deployment rights.

## Mandatory Codex completion report
`PACKAGE; BASE; BRANCH; HEAD; TREE; CHANGED_FILES; TEST_COMMANDS; TEST_COUNTS; POSTGRESQL_TEST_EVIDENCE; TYPECHECK; BUILD; CI; FINDINGS; DD_CONFLICTS; PATCH_SHA256; WORKTREE_STATUS; REQUESTED_DECISION`.

## Approval effect
The user delegated creation and formal architecture-package approval. This authorization permits **approval of documented policy and bounded requirements**; it cannot make a missing API fact, unresolved deferred product requirement or unverified integration result into a verified truth. Those entries stay gated. Future implementation requires a separate instruction.

**Final result:** `ARCHITECTURE PACKAGE v1 — FORMALLY APPROVED FOR PLANNING / CONDITIONAL PACKAGE FREEZES / ZERO IMPLEMENTATION AUTHORIZATION`.
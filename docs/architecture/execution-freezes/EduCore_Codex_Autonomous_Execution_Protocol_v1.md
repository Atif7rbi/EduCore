# EduCore — Codex Autonomous Execution Protocol v1

Decision: ECF-CODEX-001
Status: APPROVED — operating policy; NOT an authorization to start execution
Date: 2026-10-11
Repository: Atif7rbi/EduCore

## Responsibility split
User: final authority for scope, architecture changes, PR readiness, merge, deployment and each Git permission. Codex: implementation/test/remediation within approved scope. ChatGPT: architecture and independent evidence/diff review.

## Entry gate
Before editing, Codex must receive an explicit user start instruction naming scope and permitted Git actions, an approved frozen slice packet with exact baseline SHA and authority hierarchy, API/lifecycle/authorization/integrity contracts, acceptance tests, and an approved isolated test environment. Prove correct repo/branch/HEAD, clean workspace and PostgreSQL non-production test DB. Missing evidence: STOP rather than guessing.

## Autonomous implementation loop
1. Read current architectural pack, amendments, slice scope, actual controllers and tests.
2. Plan internally within frozen scope, implement without opportunistic redesign.
3. Run targeted tests, fix ordinary implementation defects independently, add regression and true PostgreSQL concurrency tests where required.
4. Run full applicable test suites, lint/typecheck/build, diff checks and integration gates.
5. Produce one final review packet with implementation diff, acceptance mapping and reproducible evidence.
6. A single final review is normal but never excuses material defects, missing tests or regression repair.

## Mandatory escalation
STOP for any change in role/identity, ownership or Phase F learner grant; PostgreSQL schema, immutable facts or lock ordering; attempt scoring/regrade/provenance; published historical state; new API/lifecycle/permissions; deferred decisions; scope expansion; production data or CI bypass. Report exact clause/decision, options, impact and proposed amendment. Codex cannot self-approve the exception.

## Git and release controls
Reading and tests only inside authorized workspace. Branch/commit/push/draft PR actions only when specifically authorized in the current handoff. No force push, hidden branch changes, merge, ready-for-review, deployment or production data mutation without distinct explicit permission. Avoid unrelated refactoring.

## Final handoff requirements
Repo, base/HEAD, branch, clean/staged state, file list and diff stats; contract-to-tests matrix; exact commands and test counts; PostgreSQL isolation and multiprocess evidence; frontend typecheck/build/lint; risks and blockers; CI link/status if authorized pushed; operations not performed. Independent reviewer checks actual diff and evidence, and user approves next action.

## Decision
Protocol APPROVED for later use; execution of H6-S6/S7 or any other slice is NOT AUTHORIZED by this document alone.

# EduCore — Forward Architecture & Execution Freeze

**Document ID:** FAEF-001  
**Version:** 1.0 · **Date:** 2026-10-11  
**Repository:** `Atif7rbi/EduCore` · **Evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Decision authority:** User-authorized architecture preparation and approval.  
**Approval class:** APPROVED POLICY / CONDITIONAL EXECUTION FREEZE. Inherited FROZEN rules retain precedence. An unresolved contract must not be presented as verified or executable.  
**Execution authorization:** NONE. No Codex run, implementation, branch, commit, push, PR change, merge or deployment.

## 1. Authoritative decision
Adopt **Architecture-First Fast Delivery v1**. Prepare all planned architecture documents now; this supersedes the previous *serial drafting* restriction only. Approval of this governance package is **not** an approval to invent missing product/API decisions, nor does it grant execution authority.

## 2. Source precedence
1. `docs/architecture/README.md` and the frozen architecture pack.
2. Approved amendments (notably `CDA-008` and `CDA-009`) override only explicit superseded clauses.
3. Approved Phase H architecture/implementation freezes, provided their actual versions are verified.
4. Current code, schema, tests and API routes establish *implemented truth*, not automatically product authorization.
5. This forward package governs workflow and acceptance approach; it never overrides 1–4.

**No inference:** `docs/architecture/60_EDUCORE_DEFERRED_DECISIONS_v1.md` records intentionally deferred questions. STOP if a feature needs one. Do not treat stale progress statements as execution status.

## 3. Inherited immutables
- Exact published/released source revisions and pinned provenance; no `MAX/latest` as a source of authority.
- PostgreSQL is canonical for domain/integrity/concurrency tests; no SQLite substitution.
- Student user and LearnerProfile are distinct; valid Student requires exactly one profile, malformed states fail closed.
- Active authorization chain: active Student User → LearnerProfile → active StudentEnrollment → active TeacherSubjectAssignment → exact Curriculum ownership and applicable content.
- Roles (`student|teacher|admin`) immutable; `User.status` (`active|disabled`).
- Teacher owns teacher-scoped authoring; Admin is read-only for teacher-owned content.
- Historic Attempts/Responses/RegradeCorrections retain immutable fact semantics.
- No speculative generic RBAC, tenants, commercial entitlements, global student score or Layer 3 thresholds.

## 4. Decision register
| ID | Decision | State | Effect |
|---|---|---|---|
| FAEF-001 | Architecture-first packaged delivery | APPROVED | Preparation only until separate start approval |
| FAEF-002 | Inherited source precedence and deferred-decision stop | APPROVED | Fail closed on contradiction |
| FAEF-003 | One consolidated Codex implementation handoff per package | APPROVED | Self-remediate implementation defects inside scope |
| FAEF-004 | Independent final architecture/code/CI review | APPROVED | Findings remediate before closure |
| FAEF-005 | H6-S6 lifecycle/auth UX detail | CONDITIONAL | Requires reconciled H6 plan and live contract matrix |
| FAEF-006 | H6-S7 cross-feature validation | CONDITIONAL | Depends on S6 & required test evidence |
| FAEF-007 | Learner gap work | CONDITIONAL | Audit first; existing pages must be reused |
| FAEF-008 | Pilot delivery | CONDITIONAL | Explicit participants, content set, UAT and target runtime |

## 5. Delivery dependency graph
`H6-S5 CLOSED → S6 contract reconciliation → S6 implementation/CI → S7 end-to-end gate → H6 final independent review → separately authorized PR #9 merge`

In parallel, **documentation-only** learner gap audit, pilot scenario specification and production preflight may proceed; **no code changes** without package-specific authorization. Learner front-end already includes discovery, curriculum, lesson, practice, attempt, exam, results and progress pages. Avoid a fictitious greenfield Student phase.

## 6. Standard frozen package template
Every execution package MUST contain: immutable baseline SHA; source-of-truth references; in/out scope; actors/role matrix; exact API requests/responses and error mappings; lifecycle matrix; PostgreSQL and historical truth constraints; UX/navigation/mutation race cases; tests and verification commands; stop conditions; final deliverable evidence; permitted Git operations.

## 7. Codex operations policy (approved, not yet active)
- Start only on **explicit user authorization** naming package, branch, baseline and Git rights.
- Recheck head/branch/worktree, current contract and isolated test PostgreSQL; abort on mismatch.
- Implement full package, run focused/full tests, remediate ordinary issues autonomously; no mandatory intermediate ChatGPT review for minor fixes.
- STOP on new API/schema/lifecycle/authorization decision, DB lock-order deviation, evidence-proven integrity issue, change to frozen spec or need to touch production.
- Final handoff: branch, base/head/tree, full changed-file inventory, test evidence, CI status, open risks, SHA/patch evidence and decision requests.
- Commit/push/draft PR operations require explicit package grant; merge and deployment always separately authorized.

## 8. Freeze interpretation
**Approved**: governance, authority, escalation, non-regression, test and handoff policies.
**Conditionally frozen**: proposed scope envelopes for S6/S7 and later packages; must pass the named evidence gates before any Codex implementation.
**Not approved**: new semantics for deferred DDs, unverified endpoint names, UI affordances unavailable in APIs, or unconditional project delivery dates.

## 9. Evidence anchors
- `docs/architecture/00_EDUCORE_ARCHITECTURE_v1.md`
- `docs/architecture/README.md`
- `docs/architecture/60_EDUCORE_DEFERRED_DECISIONS_v1.md`
- `docs/architecture/amendments/CDA-009_TEACHER_STUDENT_ACCESS_MODEL_v1.md`
- `routes/api.php` and `resources/frontend/app/router.tsx`
- `docs/production/PRODUCTION_READINESS_AUDIT.md`
- GitHub PR #9 is OPEN/DRAFT at reviewed SHA; EduCore CI run #11 SUCCESS.

**Disposition:** `FAEF-001 — GOVERNANCE APPROVED / EXECUTION NOT AUTHORIZED`.
# EduCore — H6-S6 Contract Reconciliation Annex v1

**Gate:** S6-CONTRACT-001
**Date:** 2026-10-11
**Status:** PARTIALLY RECONCILED — ORIGINAL APPROVED H6 IMPLEMENTATION PLAN TEXT NOT LOCATED; EXECUTION READY = NO
**Repository:** Atif7rbi/EduCore
**Evidence implementation SHA:** 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d
**Document branch:** docs/educore-execution-contract-freezes-v1
**Mode:** Read-only assessment + documentation; no code authorization.

## 1. Evidence and authoritative boundaries

The approved Phase H Implementation Plan v1 is known from prior architecture review at baseline ae7cbbb52771f0d1e9da01145200b7455b869828; historical H-IP-001..004 findings were CLOSED. **The authoritative full S6/S7 subsection text was not located in the current repository tree or available past-plan extracts.** Neither the new forward-package S6 envelope nor the current UI implementation may be substituted for that missing primary approved plan.

The following implementation paths were inspected read-only at the H6-S5 baseline:
- resources/frontend/teacher/TeacherWorkspacePage.tsx
- resources/frontend/teacher/TeacherContextSelector.tsx
- resources/frontend/teacher/api.ts
- resources/frontend/teacher/TeacherWorkspacePage.test.tsx
- resources/frontend/teacher/TeacherPracticeExamPanels.test.tsx
Additional previously reviewed evidence: resources/frontend/api/errors.ts and routes/api.php.

Governance: docs/architecture/README.md; CDA-009 Teacher/Student; 50_EDUCORE_INTEGRITY_RULES_v1.md; 02_H6_S6_ARCHITECTURE_SCOPE_FREEZE_v1.md; ECF-API-001, ECF-AUTH-001, ECF-FE-001.

## 2. Verified implementation mappings

| Contract | Evidence | Disposition |
|---|---|---|
| Workspace aggregate draft guard | TeacherWorkspacePage.tsx uses useBlocker and useBeforeUnload, and aggregates topic/placement/lesson/assessment/practice/exam dirty flags | IMPLEMENTED PATH OBSERVED; behavioral coverage exists, completeness not proven |
| Guarded navigation between contexts | TeacherWorkspacePage.test.tsx tests canceled and confirmed navigation while dirty | DIRECT TEST EVIDENCE IDENTIFIED |
| Context loss via 403/404 | TeacherContextSelector.tsx checks 403/404; TeacherWorkspacePage.test.tsx tests forced unavailable-context navigation after dirty state | DIRECT TEST EVIDENCE IDENTIFIED |
| Context-scoped React Query keys | teacher/api.ts provides actor and curriculum/version scoped teacherQueryKey variants | IMPLEMENTED PATH OBSERVED; full key inventory pending |
| Abort signal propagation | teacher/api.ts accepts AbortSignal for selected read functions | IMPLEMENTED PATH OBSERVED; complete read inventory pending |
| Late practice mutation A → selected B | TeacherPracticeExamPanels.test.tsx contains an explicit test | DIRECT TEST EVIDENCE IDENTIFIED |
| Late exam template mutation A → selected B | TeacherPracticeExamPanels.test.tsx contains an explicit test | DIRECT TEST EVIDENCE IDENTIFIED |
| Unmount while generation succeeds | TeacherPracticeExamPanels.test.tsx contains late generation success test | DIRECT TEST EVIDENCE IDENTIFIED |
| 409 conflict reconciliation | TeacherPracticeExamPanels.test.tsx covers input retention and authoritative lifecycle refresh; TeacherWorkspacePage.test.tsx checks conflict query invalidation | DIRECT TEST EVIDENCE IDENTIFIED |
| 422 form validation | TeacherPracticeExamPanels.test.tsx tests exam version 422 detail rendering | DIRECT TEST EVIDENCE IDENTIFIED |
| Published/historical read-only behavior | TeacherPracticeExamPanels.test.tsx contains immutable published history assertion | DIRECT TEST EVIDENCE IDENTIFIED |
| Practice active last membership safety | TeacherPracticeExamPanels.test.tsx contains last active membership and read-only history test | DIRECT TEST EVIDENCE IDENTIFIED |
| Client error normalization | resources/frontend/api/errors.ts normalizes code/status/details/requestId | IMPLEMENTED PATH OBSERVED |

Note: test names/paths confirm intent and presence, NOT an independently rerun green result in this audit.

## 3. Unclosed exact-contract questions

**S6-R001 — ORIGINAL PLAN MISSING — BLOCKING:** Obtain the exact approved H6 Implementation Plan v1 S6 section including definition of done; compare line-by-line with H6-S6-AF and document authorized in/out scope. Until then a self-contained Codex execution package cannot be declared complete.

**S6-R002 — API/STATE MATRIX — OPEN:** Enumerate every S6-touched teacher endpoint from routes/api.php, owning controller/method, legal lifecycle transitions, preconditions, exact error envelope and response fields, then map each frontend function. Do not infer fields from generic client behavior.

**S6-R003 — FULL UI RACE/ERROR COVERAGE — OPEN:** Build a panel-by-panel evidence table for topics, placements, lessons, assessments, practices, templates, version and generation; list identity-scoped pending mutations, 401/403/404/409/422, dirty/create-edit transitions, and controlled deferred response tests.

**S6-R004 — POSTGRESQL BACKEND PROOF — OPEN:** Connect S6-visible authorization/lifecycle failures to existing authoritative transaction-time service tests and deterministic parent-to-child lock protocols. Avoid modifying backend where frontend-only S6 contract forbids it.

**S6-R005 — BASELINE DRIFT — OPEN:** Rerun targeted and full tests against candidate execution HEAD when Codex resumes. Prior 342/342 frontend test evidence and H6-S5 CI SUCCESS are historical baseline, not S6 closure.

## 4. Explicit prohibited assumptions

- S6 is not a new backend feature or permission expansion.
- The existence of React hooks or a test name is not proof of complete race protection.
- No added admin authoring, new student enrollment semantics, new lifecycle statuses, or arbitrary API endpoints.
- No inference of execution authorization from APPROVED POLICY.
- No merge/deployment, production tests or migrations.

## 5. Proposed evidence-based next documentation work

A. Recover the full approved Phase H plan S6 excerpt (from its original source).
B. Populate exact routes/controller/action table and write concise S6 endpoint annex.
C. Populate comprehensive panel/race/error matrix and cite test cases.
D. Cross-check all cases against authoritative PostgreSQL and user/assignment lifecycle.
E. Close S6-R001..R005 only on positive evidence and independent confirmation; then issue S6 EXECUTION READY decision requiring a separate user start authorization.

**Final gate:** S6-CONTRACT-001 PARTIAL; architecture nonregression boundaries remain APPROVED; H6-S6 execution remains NOT AUTHORIZED and NOT YET EXECUTION READY.

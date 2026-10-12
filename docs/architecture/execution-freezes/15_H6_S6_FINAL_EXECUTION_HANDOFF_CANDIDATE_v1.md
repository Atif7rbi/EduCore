# EduCore — H6-S6 Final Execution Handoff Candidate v1

**Packet ID:** H6-S6-HANDOFF-001
**Prepared:** 2026-10-12
**Status:** CANDIDATE ONLY — EXECUTION READY = NO
**Repository:** Atif7rbi/EduCore
**Reviewed implementation baseline:** 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d
**Documentation branch:** docs/educore-execution-contract-freezes-v1
**Current permission:** Documentation preparation/review only. No Codex start, application code change, implementation branch creation, PR edit, commit/push to implementation branch, merge, migration, production test or deployment is authorized by this packet.

## 1. Source precedence

1. Frozen docs/architecture/README.md and Architecture Pack (00/20/35/50/60).
2. Approved amendments CDA-008 and CDA-009 for explicitly superseded clauses.
3. Approved Phase H Architecture and Implementation Plan v1, including H-IP-001..004 remediation and final User.status terminology active|disabled.
4. Current code/routes/tests as implemented truth, never a substitute for unapproved product decisions.
5. Conditional forward-package S6/S7 freezes and execution-contract freezes under docs/architecture/execution-freezes.

The original Phase H implementation plan was recovered from earlier project files in audit 14. It defines H6 as the overall Teacher Workspace Frontend phase, NOT the later H6-S6 slice. The later slice-specific acceptance baseline is still not independently established. Never invent this missing source.

## 2. Existing work to preserve

H6-S1..S5 is already implemented on feat/h6-teacher-workspace-v1. The reviewed H6-S5 code SHA is stated above; do not rebuild the Teacher workspace or previously implemented Topic/Skill Placement/Lesson/Assessment/Practice/Exam panels.

Existing source-proven behavior includes aggregated unsaved-change guarding, context-scoped teacher query keys, abortion-aware reads where supported, unavailable-context fail-closed navigation, 409 authoritative refresh, 422 validation messages, immutable published-history views, and selected-resource mutation race tests. None of these source observations by themselves prove full release conformance.

## 3. Bounded S6 candidate scope

**IN, only after actual slice acceptance cross-check:**
- Verify and remediate only evidenced gaps in Teacher workspace lifecycle action visibility, authoring enable/disable controls, 401/403/404/409/419/422 UX behavior, semantic dirty-state protection, and async resource/context/principal isolation.
- Strengthen specific tests for uncovered negative transitions and races.
- Preserve exact backend authority and existing phase boundaries.

**OUT:** New API route, backend domain lifecycle, PostgreSQL migration/schema, permission grant, generic RBAC, Student/learner redesign, unrelated refactor, new content-scoring or analytics semantics, production operation.

## 4. Backend/API contract references

- 10_H6_S6_EXHAUSTIVE_API_MATRIX_v2.md: **63 teacher routes inventoried**, covering three Teacher Workspace reads and 60 nested authoring routes. The matrix is a route/visible-validator inventory, not independent runtime proof of every transport and error contract.
- 11_H6_S6_VALIDATION_SERIALIZATION_EXCEPTION_LOCK_ANNEX_v1.md: nested validators, serializers, direct 409 conflict mapping and service guards.
- 12_H6_S6_FRONTEND_TYPES_EXCEPTION_HANDLER_AUDIT_v1.md: TypeScript DTO structural mapping, central exception matrix, and unresolved S6-TYPE-001 / S6-ERR-001.
- API transport: success {data:T}; error {error:{code,message,details?}}; 401 unauthenticated; 403 forbidden; 404 not_found; 409 named conflicts; 419 csrf_token_mismatch; 422 validation_failed; 500 internal_error. Preserve centralized session-failure handling; do not conflate 409 blocker codes with 422 human-readable field messages.
- Canonical browser routes are /portal/teacher and /portal/student, not /teacher and /student. Teacher backend API remains /api/teacher/*.

## 5. Frozen authorization/lock order

Every Teacher mutation must revalidate actor/subject/assignment/curriculum inside an authoritative transaction. LockActiveTeacherCurriculumAuthority requires active transaction and locks:

Teacher User -> Subject -> TeacherSubjectAssignment -> Curriculum -> (operation-specific CurriculumVersion and child locks).

The first assignment identity discovery is intentionally unlocked and non-authoritative; revalidation follows the locks. TransactionManager::run executes one transaction attempt (attempts: 1) and translates PostgreSQL exceptions; do not presume transparent retry. Preserve deterministic sorted same-type row locking and immutability of released/published history.

See 13_H6_S6_TRANSACTION_LOCK_AND_CODEX_TEST_REGISTER_v1.md for exact source paths, existing suites, 12 test requirements and PostgreSQL barriers.

## 6. Candidate acceptance requirements

| ID | Mandatory result | Evidence |
|---|---|---|
| S6-A01 | Exact eligible Teacher, active canonical Subject, active assignment and exact owned Curriculum access only | backend transactional authorization tests and UI route tests |
| S6-A02 | Disabled Teacher and revoked/inactive assignment fail closed during pending mutation | two-process PostgreSQL concurrency + frontend context loss test |
| S6-A03 | Correct 401/403/404/409/419/422 mapping and no false success | API feature tests, frontend error/session assertions |
| S6-A04 | Dirty edits survive canceled navigation and are explicitly discarded on approved navigation | existing and extended panel tests |
| S6-A05 | Pending GET/mutation in resource/context A cannot corrupt or authorize resource/context B | controlled deferred-response/react-query test |
| S6-A06 | Draft-only mutation and released/published-history immutability | service and frontend negative tests |
| S6-A07 | Practice active membership retains valid >=1 item; ExamTemplateVersion/Generation transitions use exact source authority | server tests and UI lifecycle regression |
| S6-A08 | S1-S5 and Student/Admin role/portal regression unchanged | frontend/backend full suites and build/typecheck |
| S6-A09 | Real PostgreSQL multi-process blocker evidence with nonzero test counts | named suites and process barrier evidence |
| S6-A10 | No new schema, lifecycle, permission, route or deferred-decision semantics | independent diff review |

## 7. Codex future execution matrix

Use exact test names, not invented pass counts. The proposed work package must run only in a verified isolated non-production PostgreSQL clone.

| Phase | Test family | Gate |
|---|---|---|
| Preflight | SHA, branch, clean tree, exact resolved DB/database/driver identity, no production DB | STOP on mismatch |
| API authorization | LockActiveTeacherCurriculumAuthorityTest; TeacherWorkspaceReadApiTest; TeacherCurriculumAuthoringApiTest | all discovered tests nonzero/green |
| Lifecycle | TeacherLessonAssessmentAuthoringApiTest; TeacherPracticeExamAuthoringApiTest | negative transitions/immutable history pass |
| Concurrency | TeacherAuthoringAuthorityConcurrencyTest; TeacherAuthoringLifecycleConcurrencyTest; TeacherExamTemplateConcurrencyTest; TeacherExamGenerationConcurrencyTest; TeacherPracticeAttemptConcurrencyTest; ParentLockProtocolTest | true separate OS processes/connections and observed blocker PIDs; green |
| Frontend | TeacherWorkspacePage.test.tsx; TeacherPracticeExamPanels.test.tsx; relevant lessons/assessment/taxonomy panels and session/auth suites | navigate/context/async/error regressions pass |
| Final | full backend suite + required frontend test/typecheck/build + Pint + CI after separately authorized push | full nonzero evidence, clean diff |

The 12-point detailed PostgreSQL test register remains authoritative. If any test uses zero discovered tests, skips, fixed sleep instead of blocking proof or unverified DB identity, it is NOT a PASS.

## 8. Findings / STOP list

- **S6-GATE-001 — OPEN:** Locate/verify the later original H6-S6 slice acceptance instruction and reconcile it with forward-package 02. Original Phase H H6 plan is already recovered; lack of a literal H6-S6 heading in it is NOT a valid blocker.
- **S6-GATE-002 — OPEN:** Verify S6-TYPE-001 request JSON object/array transport and S6-ERR-001 conflict blocker presentation through actual tests; only code-confirmed defects enter scope.
- **S6-GATE-003 — OPEN:** Complete panel-by-panel async/dirty/error gap classification; distinguish existing S1-S5 coverage from proven S6 defects.
- **S6-GATE-004 — OPEN:** Execute authoritative PostgreSQL tests on the eventual candidate head with positive isolation and multi-process evidence.
- **S6-GATE-005 — OPEN:** Obtain explicit user authorization identifying baseline, implementation branch, editing rights and optional commit/push/Draft PR limits.

Any previously deferred DD requiring a new product decision must be escalated separately. Do not unilaterally resolve the DD.

## 9. Required final implementation handoff (after future authorization)

PACKAGE; SOURCE PLAN VERSION; BASE; BRANCH; HEAD; CLEAN WORKTREE; DIFF INVENTORY; API/LIFECYCLE MATRIX; GAPS RESOLVED; TEST COMMANDS; TEST COUNT; PG DATABASE IDENTITY; BLOCKING PID EVIDENCE; FRONTEND TYPECHECK/BUILD; PINT; CI; OPEN RISKS; ARCHITECTURE EXCEPTIONS; NO-GO ITEMS; GIT OPERATIONS PERFORMED/NOT PERFORMED; REQUESTED USER DECISION.

## 10. Approval gate

**H6-S6 EXECUTION HANDOFF CANDIDATE — PREPARED.**
**H6-S6 ARCHITECTURE SCOPE — CONDITIONAL.**
**H6-S6 IMPLEMENTATION / CODEX START — NOT AUTHORIZED.**
**H6-S7 — DEPENDENT ON VERIFIED S6 CLOSURE.**
**MAIN / PR #9 / DEPLOYMENT — UNCHANGED.**

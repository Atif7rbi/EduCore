# EduCore — API & Lifecycle Contract Freeze v1

**Decision ID:** ECF-API-001  
**Status:** APPROVED — CONTRACT PRESERVATION / PARTIAL DISCOVERY (not execution-ready)  
**Date:** 2026-10-11  
**Evidence baseline:** `Atif7rbi/EduCore` @ `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Authority:** User-delegated architecture-document approval. All previously frozen architecture and approved amendments supersede this document upon conflict.  
**Permissions:** DOCUMENTATION ONLY. No Codex, implementation, Git operations, PR changes, merge or deployment.

## 1. Objective and scope
Freeze **how forthcoming frontend work must consume existing API and lifecycle authority**; do not freeze unverified server behavior. Applies to H6-S6/S7, learner audit, and pilot integration. This document does not create endpoints, lifecycle transitions, new roles, or schema changes.

## 2. Verified source facts
Read at the exact evidence baseline:
- `routes/api.php`: Teacher authoring is nested under `subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}`, with dedicated controllers for curriculum authoring, lesson/assessment authoring, and practice/exam authoring.
- `resources/frontend/teacher/api.ts`: has scoped query-key builders and request functions for topics, placements, lessons, assessment items, practice activities and exam templates/versions/generation.
- `resources/frontend/api/errors.ts`: normalizes Axios errors to `EduCoreApiError { code, message, status, details?, requestId }`; `details` is a map of field names to string arrays when valid; request ID comes from `x-request-id` header.
- `resources/frontend/teacher/TeacherWorkspacePage.tsx`: contains dirty-navigation protection, scoped context selection, and a lifecycle conflict refresh callback.
- Inherited architecture `00_EDUCORE_ARCHITECTURE_v1.md` and `CDA-009`: active teacher/assignment/ownership/access boundaries; published/released historical truth cannot be rewritten. Student access is a complete transactional grant chain, not enrollment alone.

**Evidence limit:** Route and client-function existence proves an interface surface, not every response field, permission predicate, exception code or state transition. Those need controller/test-level verification.

## 3. Frozen normative constraints (ECF-API)
| ID | Rule |
|---|---|
| API-01 | Server authorization is authoritative; frontend visibility is not a grant. Teacher must remain within active teacher, assignment, ownership and curriculum-version boundaries. |
| API-02 | Use existing route paths and established request/response envelopes. No fabricated endpoint, response field or error code. |
| API-03 | Errors must be processed through canonical client normalization; preserve status, code, message, valid field `details` and request-id when present. |
| API-04 | Treat 401 as an authentication/session concern; 403/404 as denial/not-found without leaking or retaining unauthorized authoring context; 409 as potential stale lifecycle/conflict requiring authoritative refresh; 422 as validation failure with field-level detail when supplied. These are **UX rules**, not claims that each endpoint emits each status. |
| API-05 | Exact resource/context/principal identity must scope state and asynchronous callbacks. Late responses must not overwrite a different identity. Reads should use supported cancellation; late mutations cannot falsely confirm the current context. |
| API-06 | Draft-only edits, released revision immutability, published version history, and source snapshots follow existing backend lifecycle truth. UI cannot synthesize missing transition operations. |
| API-07 | Exam generation is a one-shot create operation in the verified teacher API surface. No implied history/list endpoint without explicit verification. |
| API-08 | All negative authorization and lifecycle transitions require tests at the appropriate layer; PostgreSQL-backed integrity tests cannot be replaced with SQLite. |
| API-09 | An API mismatch is a STOP condition: record endpoint, observed payload/status, existing contract, and proposed resolution. Do not silently adapt architecture to frontend convenience. |

## 4. Verified teacher route families (not a complete endpoint-by-endpoint contract)
| Family | Observed operations at HEAD | Scope boundary |
|---|---|---|
| Assignment and curriculum | assignment-context reads, curriculum/version read/create/update and lifecycle actions | exact teacher subject assignment + owning curriculum |
| Taxonomy | topic and skill placement reads/writes; home-topic changes | same curriculum version |
| Lessons | lesson create/update; revision create/update; revision skill add/remove; release/publish/unpublish | existing lesson/version and release rules |
| Assessment items | item create/update; revision create/update; skill add/remove; release/publish/retire | existing item/version and released history |
| Practice | GET activities and memberships, create/update, activate/archive, add/delete membership | exact released AssessmentItemRevision; preserve membership/order and lifecycle |
| Exam templates | GET templates/version(s), create/update, activate/archive; version create/update, publish/retire; generation create | exact template/version and immutable published history |

This table is a **route-family inventory**. Precise path, HTTP method, payload, response and legal transition matrix must be copied from current controllers/tests before execution authorization.

## 5. Required contract reconciliation annex before H6-S6 implementation
For each S6-touched endpoint, produce one row containing:
`method | actual route | controller action | middleware | actor/ownership predicate | parent-to-child lock order if any | legal source state | effect state | request schema | response schema | errors observed/tested | UI consumer | tests`.

Evidence precedence: approved architectural amendment → production schema/integrity rule → controller/application service → integration tests → frontend client/types. Conflicts are issues, not permission to rewrite a higher authority.

Stop labels:
- `CONTRACT_VERIFIED` — enough evidence for implementation.
- `CONTRACT_MISSING` — incomplete evidence; may perform read-only investigation.
- `ARCH_DECISION_REQUIRED` — incompatible requirements or unresolved deferred decision; stop execution.

## 6. Acceptance and handoff gate
- Confirm authoritative original H6-S6 scope, not only its shorthand title.
- Verify every impacted endpoint against controller/service and real tests.
- Demonstrate negative permission, disabled user/inactive assignment, stale version, semantic dirty navigation and late async races.
- Verify no new backend capability or alteration to historical truth.
- Audit `git diff`, regression tests, typecheck/build and PostgreSQL suite as applicable.
- Submit consolidated evidence to independent review.

**Decision:** `ECF-API-001 = APPROVED NORMATIVE RULES`; `H6-S6 API CONTRACT ANNEX = NOT YET VERIFIED`; `EXECUTION AUTHORIZATION = NONE`.

## 7. Remaining architecture-document sequence
1. Authorization & Concurrency Contract Freeze.
2. Frontend Interaction & Async State Freeze.
3. Assessment / Attempts / Results Contract Freeze.
4. Codex Autonomous Execution Protocol.
5. Pilot Product Configuration Freeze.
6. Cross-document consistency and execution readiness audit.
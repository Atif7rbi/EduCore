# EduCore — Authorization & Concurrency Contract Freeze v1

**Document ID:** ECF-AUTH-001  
**Date:** 2026-10-11  
**Status:** APPROVED — NORMATIVE GUARDRAILS / CONDITIONAL ON PER-PATH VERIFICATION  
**Repository:** `Atif7rbi/EduCore`  
**Evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d` (H6-S5)  
**Mode:** Architecture-only; NO implementation, code change, commit, push, merge, or deployment.

## 1. Purpose and authority

This contract consolidates *existing* authorization and concurrency invariants for Phase H6 completion, learner-facing integration, and Pilot readiness. It introduces no new permission, lifecycle transition, endpoint, database table, or lock order. Where a concrete operational lock sequence is not established by source review, it remains **UNVERIFIED** and must be traced to implementation and tests before an execution package can claim readiness.

**Precedence:** FROZEN EduCore architecture pack → explicit approved amendments (especially CDA-009) → already approved phase-specific contracts → this cross-phase consolidation. If a conflict exists, **STOP; do not reinterpret or silently supersede the higher authority**.

Source anchors reviewed at the baseline:
- `docs/architecture/00_EDUCORE_ARCHITECTURE_v1.md`
- `docs/architecture/50_EDUCORE_INTEGRITY_RULES_v1.md`
- `docs/architecture/60_EDUCORE_DEFERRED_DECISIONS_v1.md`
- `docs/architecture/amendments/CDA-009_TEACHER_STUDENT_ACCESS_MODEL_v1.md`
- `routes/api.php`
- `resources/frontend/teacher/TeacherWorkspacePage.tsx`
- `resources/frontend/api/errors.ts`

## 2. Actor, account, and lifecycle authority — FROZEN

1. **Single immutable v1 role:** `student | teacher | admin`. Do not introduce generic RBAC, role switching, or implicit combined roles.
2. **User status:** `active | disabled`. Check active status on **every** authenticated request, including after an already-established session. `disabled` immediately fails closed for protected capabilities.
3. **Student identity:** actor `users.id` is distinct from `learner_profiles.id`. Product-valid Student has exactly one LearnerProfile. Missing/malformed association cannot grant learner access. Look up by **exact UUID links**, never name/email or `latest`.
4. **Session auth:** Laravel `web` session is v1. Do not silently add bearer tokens/Sanctum or treat a frontend role guard as an authorization authority.
5. **Teacher provisioning:** active Admin-only dedicated flow; new Teacher account initially `disabled`; no changing an existing user's role into Teacher; activation is controlled by the approved credential-establishment path.
6. **Admin teacher-owned curriculum:** inspection/read only; may not author, edit, publish, or perform lifecycle mutations on teacher-owned curriculum or descendants.
7. **Historical ownerless curriculum:** explicitly distinguished legacy compatibility; not a path for new content creation.
8. **Authorization abstraction:** centralize role/ownership checks per approved architecture; controllers must not accumulate new scattered raw role comparisons.

## 3. Exact effective learner grant — FROZEN

An active enrollment **alone is not an effective access grant**. For a learner-facing curriculum operation, enforce the complete current Phase F conjunction established in CDA-009 and the implementation's approved transactional protocol:

- Authenticated actor is the exact active `student` User.
- Exact `LearnerProfile` belongs to that User and is locked/validated where the operation is authoritative.
- Exact active `StudentEnrollment` belongs to that LearnerProfile.
- The enrollment's `teacher_subject_assignment_id` equals the selected teacher-owned `Curriculum.teacher_subject_assignment_id`.
- Referenced `TeacherSubjectAssignment` is active, belongs to the owning Teacher, and passes applicable active-user/canonical-Subject checks under the approved Phase F conjunction.
- The target Curriculum/Version and action-specific lifecycle/source eligibility is independently valid; historical provenance is not equivalent to current authority.

**Enforcement:** reject on missing, mismatched, inactive, stale, or ambiguous links. Read contexts must never imply that a result of one earlier GET authorizes a later mutation.

## 4. Relationship operations — FROZEN

| Operation | Actor authority | Forbidden substitution |
|---|---|---|
| Establish/reactivate/deactivate TeacherSubjectAssignment | Active Admin under approved lifecycle | Teacher self-assignment; creating canonical Subject identities |
| Request/rejoin StudentEnrollment | Active Student owner via approved lifecycle | Admin silently enrolling student |
| Accept/decline enrollment | Active owning Teacher | Routine Admin approval/decline |
| Deactivate enrollment | Owning Teacher or Admin when approved | Arbitrary non-owner Teacher |
| Exceptional relationship correction | Dedicated future approved Admin correction workflow | Treating correction as ordinary acceptance or inventing a generic endpoint |
| Mutate teacher-owned curriculum/content | Active authorized owning Teacher | Admin write access or unrelated Teacher |
| View teacher-owned content for management | Authorized Admin read boundary | Admin mutation based on inspection access |

All operations must obey the already-frozen lifecycle transition/replay rules; UI affordances do not extend API permissions.

## 5. PostgreSQL concurrency and integrity — FROZEN

- PostgreSQL is authoritative for domain integration, schema, triggers, and concurrency. **SQLite is not a substitute.**
- Acquire transactional actor/ownership authorization locks in the **existing approved operation-specific order**, followed by protected aggregate and child locks in deterministic **parent-to-child** order where required by the contract.
- A parent/child aggregate with cross-row final-state constraints must use the **same parent lock** on all competing mutations. Do not rely on a deferred trigger alone to prevent write skew.
- Re-evaluate lifecycle state, access, effective grant, and source eligibility **inside the authoritative mutation transaction**, after acquiring the specified locks, immediately before writes that depend on those predicates.
- Prefer existing atomic services/transactions over a new independent lock protocol. Where concurrency tests reveal inversion or missing serialization, **STOP for review** if repair alters canonical lock order.
- Preserve published/released provenance and historical attempt snapshots; do not overwrite sealed state to satisfy a current-state read.
- Transactional errors do not authorize a frontend retry of a non-idempotent POST without explicit, proven replay semantics.

**Important limitation:** The reviewed architecture establishes deterministic parent-to-child locking and parent serialization, but does **not** in this document establish a globally enumerated lock order for every service. No engineer may infer a universal table order from this consolidation.

## 6. API security and frontend failure handling — FROZEN

- Treat `401` as loss of authenticated access; do not preserve privileged context after authentication loss.
- Treat `403` as denied authority; `404` may represent unavailable or deliberately concealed resources. Neither authorizes fallback to another identity or unrestricted lookup.
- Treat `409` as authoritative state/lifecycle conflict: invalidate and refetch relevant resource and context; do not auto-repeat a mutation.
- Treat `422` as structured validation failure: display approved field/general errors without guessing accepted schema.
- Honor server-provided error code/details and `X-Request-ID` for safe troubleshooting; do not expose hidden server internals.
- On principal/context/resource switch, late network responses and mutation callbacks must not contaminate the new context. Cancellation, mount/identity guards, and principal-scoped query keys support this; none substitute for backend authorization.
- Protect genuine unsaved drafts when leaving the teacher workspace or replacing a form; never silently discard after navigating among versions, templates, or activities.

## 7. Verification matrix: mandatory before execution-ready

| Gate ID | Required proof | Status at document issue |
|---|---|---|
| AUTH-G01 | Actor / LearnerProfile exact identity and disabled-user per-request guards | **Verification required per path** |
| AUTH-G02 | TeacherSubjectAssignment and StudentEnrollment lifecycle actor matrix | **Verification required per path** |
| AUTH-G03 | Full Phase F learner grant under transactional locks | **Verification required per mutation class** |
| AUTH-G04 | Teacher curriculum ownership and Admin read-only boundary | **Verification required per controller** |
| AUTH-G05 | Locks and order traced to named services, with two-session PostgreSQL contention coverage | **Verification required** |
| AUTH-G06 | Lifecycle conflict / replay / duplicate operation behavior tested | **Verification required** |
| AUTH-G07 | Late responses, principal/context switch, unsaved drafts regression tests | **Partially evidenced by H6-S5; full coverage verification required** |
| AUTH-G08 | Frontend error mapping to actual HTTP/error envelope contracts | **Verification required per relevant endpoint** |

A `PASS` may only be marked with actual source locations and reproducible test references (file, case name, PostgreSQL runtime, outcome), not an assertion based on this document.

## 8. Escalation and stop rules

Immediately stop the affected scope and report an architecture decision if implementation requires:
1. Changing a role, active/disabled semantics, or authorization conjunction.
2. An additional Admin mutation over teacher-owned content.
3. A new endpoint or lifecycle transition absent from approved API contracts.
4. A new lock order, lock inversion workaround, or compromised transaction-time eligibility validation.
5. Relaxing PostgreSQL integrity, historical immutability, or exact provenance.
6. Silently resolving a deferred decision (`DD-*`) or introducing subscription/tenant/classroom authority.
7. Manufacturing a passing test by reducing concurrency coverage, test assertions, or database strictness.

Ordinary code bugs, typing, UI errors, local regression failures, and implementation details that preserve all these contracts can be handled by Codex *only after separate implementation authorization*.

## 9. Acceptance and formal disposition

**Approved now:** This document's inherited invariants, prohibitions, verification matrix, and escalation rules are the approved cross-phase standard.

**Not approved by this document:** New architecture, proof that all implementations comply, final lock sequences for each service, H6-S6 execution, H6-S7 execution, merge, deployment, or Pilot sign-off.

**Next architecture-only artifact:** `Frontend Interaction & Async State Contract Freeze v1`, followed by Assessment/Results, autonomous execution, Pilot configuration, and cross-document consistency review.

**Decision record:** `ECF-AUTH-001 — APPROVED (NORMATIVE GUARDRAILS); PER-PATH IMPLEMENTATION EVIDENCE OPEN`.
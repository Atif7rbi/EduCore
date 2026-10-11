# EduCore — Frontend Interaction & Async State Contract Freeze v1

**Decision ID:** ECF-FE-001  
**Date:** 2026-10-11  
**Status:** APPROVED — NORMATIVE CONTRACT; implementation conformance remains a separate verification gate  
**Repository:** `Atif7rbi/EduCore`  
**Read-only evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d` (H6-S5)
**Mode:** ARCHITECTURE ONLY — NO IMPLEMENTATION AUTHORIZATION

## 1. Purpose and precedence

Freeze reusable frontend interaction, asynchronous state, and contextual authorization requirements for future EduCore work. This document *does not* supersede the frozen architecture pack, CDA-009, Phase F grant rules, authoritative backend lifecycles, or any more specific approved H6 scope. On conflict, **STOP, identify the conflicting clause, and obtain an explicit amendment**. No endpoint, role, lifecycle transition, or product capability is created by this document.

## 2. Verified evidence and limits

Read from GitHub at the baseline SHA:

- `resources/frontend/teacher/TeacherWorkspacePage.tsx`: workspace-level dirty-state aggregation, route/browser navigation guards, context invalidation handling, and reset of dirty flags.
- `resources/frontend/teacher/TeacherContextSelector.tsx`: teacher context reads and eligibility selection.
- `resources/frontend/teacher/TeacherExamTemplatesPanel.tsx`: React Query scoping, template/version dirty tracking, confirmation guards, mounted-state checks and HTTP 409 handling.
- `resources/frontend/teacher/TeacherPracticeMembershipEditor.tsx`: membership queries, dirty propagation, mounted-state checks, and prevention in the UI of removing the final membership from active Practice.
- `resources/frontend/teacher/api.ts`: dedicated teacher query keys and API client functions.
- `resources/frontend/api/errors.ts`: `EduCoreApiError` with `code`, `status`, `details`, and `requestId`.
- `resources/frontend/app/router.tsx`: role-aware teacher routes and existing learner routes.

Prior H6-S5 validation evidence: 342 frontend tests passed, typecheck/build passed, four review findings closed, and GitHub CI succeeded on the baseline. These successes do **not** prove every future scenario below.

## 3. Frozen identity and context state

**FE-001 — Actor-scoped state.** Protected query caches, in-memory form state, mutations, optimistic projections, and routing must be scoped to the authenticated principal and all resource identities affecting authorization. Do not share protected content across users, assignments, curriculum versions, or source records.

**FE-002 — Fail closed on context loss.** When a backend response or fresh context check establishes a revoked/inaccessible actor-resource relation, remove access affordances, invalidate affected context reads, and leave the protected editing context. Do not retain editable controls based on stale client state.

**FE-003 — Frontend is not authorization authority.** Disabled buttons and route guards improve UX only. Backend authorization, transaction-time revalidation and PostgreSQL integrity remain authoritative. An active enrollment alone never establishes the full Phase F learner grant.

**FE-004 — Never infer current state using latest/MAX** where an explicit published revision/current pointer or authorized curriculum-version identity exists. Exact historical revision and attempt provenance must remain intact.

## 4. Frozen asynchronous behavior

**FE-005 — Scoped GET and cancellation.** GETs with principal- and resource-scoped keys must be abort-aware when the query library supplies an AbortSignal. A late response from context A must never overwrite state rendered for context B.

**FE-006 — Late mutation settlement.** Mutation callbacks must not write UI state into unmounted panels, changed principals or changed resource identities. A `mountedRef` check alone does not prove safety if an editor remains mounted while the selected resource or draft changes. Implementers must guard *mutation origin identity* (principal + context + record + draft generation, as applicable), or prevent conflicting transitions while a mutation is pending. Guard successful and failed settlements, refresh callbacks, toast/error displays, form resets and post-save selection.

**FE-007 — Server reconciliation.** Following a successful mutation, invalidate/refetch the smallest authoritative dependent query set. Do not synthesize lifecycle truth from a presumed success payload. No silent optimistic client transition when backend final truth is required.

**FE-008 — Pending-operation UX.** Make pending work visible; prevent duplicate submissions and destructive concurrent edits where required. If a navigation would discard a pending/draft operation, distinguish unsaved local changes from server-submitted in-flight work, and do not falsely claim cancellation of an already transmitted mutation.

**FE-009 — Retry discipline.** Never automatically replay non-idempotent POST or other write operations after timeout/error unless the endpoint's approved replay/idempotency contract explicitly permits it. GET retries follow the existing client policy and must not reveal protected stale data.

## 5. Frozen draft and navigation behavior

**FE-010 — Accurate dirty tracking.** Dirty state reflects material user edits against the selected resource's original draft values, not merely whether an editor is open. Distinguish untouched create/edit forms from modified drafts where supported by the approved H6 behavior.

**FE-011 — Guard all destructive transitions.** Before discarding actual unsaved edits, require user confirmation when changing routes, assignment/curriculum/version, selected lesson/item/practice/template, switching create↔edit, or closing/replacing an editor. Cancel keeps the same draft and context; accept intentionally discards it.

**FE-012 — Hierarchical dirty propagation.** Child editor → resource panel → workspace parent must propagate dirty state and clear it upon confirmed discard or unmount. Avoid false persistent dirty state after a valid context reset. Browser leave/reload protection should work while materially dirty.

**FE-013 — Restore safety.** After save failure, preserve input and field-level errors unless an authoritative loss-of-access condition requires exit. Never silently clear a changed draft because a background refresh completed.

## 6. Frozen HTTP / lifecycle response mapping

- `401`: session no longer authorized/authenticated; follow existing global auth/session handling.
- `403`: forbidden; fail closed and remove unauthorized operation affordances.
- `404`: absent or inaccessible source as defined by backend; do not assume the record is safe to reveal.
- `409`: lifecycle/concurrency conflict; stop the attempted transition, refresh authoritative lifecycle/context, preserve input when safe, explain that current data changed.
- `422`: render field-specific validation feedback and retain the draft; generic non-field feedback where applicable.
- Network/5xx: distinguish transient failure from authorization failure; preserve safe draft and provide retry affordance without inventing success.

Use the existing `EduCoreApiError` normalized fields. Honor `requestId` for operational diagnosis without exposing stack traces. Do not hardcode a broader cross-endpoint error schema than the backend guarantees.

## 7. Lifecycle and protected content

**FE-014 — Mutation affordances match the approved lifecycle** and exact current resource state. Inactive/archived/retired/published/released objects retain historical read-only representation where the backend authorizes it; no implied authoring of sealed historical states.

**FE-015 — No client override of aggregate invariants.** UI checks (e.g., blocking removal of the last member from an active Practice) are advisory; database/application must enforce the authoritative invariant.

**FE-016 — Existing routes and API contracts are reused.** A frontend implementation must not invent a history endpoint, generic admin mutation capability, or a teacher-owned content write route without an approved backend contract.

## 8. Mandatory acceptance matrix

| ID | Required test/evidence |
|---|---|
| FE-T01 | Switching actor/principal never exposes cached content from prior principal |
| FE-T02 | Assignment/curriculum/version change while GET A is pending does not display A in B |
| FE-T03 | Resource A mutation settling after switch to B cannot reset B's form, show A's notice or invalidate unrelated context |
| FE-T04 | Unmount during mutation success/failure causes no stale UI writes |
| FE-T05 | Dirty route/context/record/create-edit transitions: cancel preserves draft; accept discards |
| FE-T06 | Untouched forms do not produce unnecessary discard prompts; edit/revert semantics tested |
| FE-T07 | 401/403/404/409/422/network failures handled without fabricating success |
| FE-T08 | 409 refresh uses authoritative state; 422 retains field input |
| FE-T09 | Inactive assignment, disabled teacher/user, revoked enrollment, and lost content ownership fail closed under the authoritative backend contract |
| FE-T10 | Published/released/sealed content has no unauthorized edit path; historical read remains exact |
| FE-T11 | Query cancellation and scoped keys are verified with controlled deferred responses |
| FE-T12 | Full frontend tests, typecheck/build, and relevant backend/PostgreSQL regression tests pass |

All tests must assert behavior, not just hook invocation. Existing H6-S5 test results are a baseline, not a substitute for new H6-S6/S7 coverage.

## 9. Required implementation preflight (not yet verified)

Before executing a future slice, Codex must inventory its affected components and document: identities in query keys; every mutation's origin-identity guard; dirty propagation; 401–422 semantics; backend route ownership; relevant tests; and any lifecycle cases absent from the API. If any of these requires a new architectural choice, **STOP → ADR/amendment → independent approval**.

In particular, *within-component A→B switching while a mutation remains in flight* is a **mandatory targeted verification**. The presence of `mountedRef` guards alone is insufficient evidence that this scenario is safe; this is not a declaration that the currently approved H6-S5 implementation is defective.

## 10. Codex authority and delivery boundaries

This document authorizes **no code changes, schema changes, migration, branch creation, commit, push, PR modification, merge or deployment**. On future explicit execution authorization, Codex may implement and fix purely technical details **within the frozen contracts** and provide one final evidence bundle. Backend permission expansion, schema/integrity modification, identity/provenance change, or new lifecycle behavior requires a separate architectural decision.

## 11. Decision result

`ECF-FE-001 — NORMATIVE CONTRACT APPROVED`

`H6-S6/S7 IMPLEMENTATION CONFORMANCE — NOT YET VERIFIED`

`CODEX EXECUTION — NOT AUTHORIZED`

**Next document:** `Assessment & Results Contract Freeze v1`.
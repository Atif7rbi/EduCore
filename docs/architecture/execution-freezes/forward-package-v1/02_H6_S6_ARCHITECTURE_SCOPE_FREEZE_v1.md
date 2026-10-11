# EduCore — H6-S6 Lifecycle & Authorization UX — Architecture/Scope Freeze

**Document ID:** H6-S6-AF  
**Version:** 1.0 · **Date:** 2026-10-11  
**Repository:** `Atif7rbi/EduCore` · **Evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Decision authority:** User-authorized architecture preparation and approval.  
**Approval class:** APPROVED POLICY / CONDITIONAL EXECUTION FREEZE. Inherited FROZEN rules retain precedence. An unresolved contract must not be presented as verified or executable.  
**Execution authorization:** NONE. No Codex run, implementation, branch, commit, push, PR change, merge or deployment.

## Intended outcome
Consolidate teacher authoring lifecycle visibility, access revocation, conflicts and unsaved-draft UX **across existing H6 authoring panels**. Do not duplicate S1–S5 features or expand teacher powers.

## Source anchors / prerequisite reconciliation
- Existing `resources/frontend/teacher/TeacherWorkspacePage.tsx` includes context navigation, dirty-state guards and lifecycle conflict handling.
- Existing topics, skill placements, lessons, assessments, practices, templates/version panels and API client must be inventoried at the approved branch HEAD.
- Read the actual approved H6 implementation plan/S6 section before authorizing execution; the title **S6** alone is not an authoritative complete specification.
- Reconcile teacher authoring endpoints in `routes/api.php` with `resources/frontend/teacher/api.ts`, including 401/403/404/409/422 contracts.

## Approved boundary rules
1. Teacher access is constrained by active Teacher User, assignment, Curriculum ownership and version status. No Admin authoring elevation.
2. Inactive/disabled/principal-switched sessions and contexts must fail closed. No stale cached content authoring after context loss.
3. 401/session expiry follows global auth handling. 403/404 invalid context must remove inaccessible workspace content. 409 stale lifecycle must refresh authority and keep data consistent; 422 surfaces field-level errors. No invented response fields.
4. Unsaved edits are guarded on route/context/tab/resource/form replacement and browser leave where applicable; no redundant prompts when semantically clean.
5. Late async reads/mutations may never apply stale state to another principal, assignment, curriculum or resource. Abort-aware GET and identity-bound mutation updates.
6. Published/released/historical entities are read-only when contracts require it; no mutation bypass, remapping or retroactive edits.

## Scope envelope
**IN:** consistency audit; confirmed deficiencies in lifecycle controls, disabled-state handling, context change, async races, accessible error feedback, and targeted regression tests.
**OUT:** creating new endpoint, backend lifecycle transition, schema migration, canonical Subject mutation, learner UI, new enrollment model, redesign or cosmetic rewrite.

## Conditional acceptance matrix
| ID | Acceptance evidence required |
|---|---|
| S6-01 | Authorized Teacher in exact assignment/curriculum/version sees only permitted actions |
| S6-02 | Disabled Teacher and inactive assignment fail closed (server authority, not only frontend controls) |
| S6-03 | 401/403/404/409/422 handling matches current API contract without false success |
| S6-04 | Navigation and resource switches preserve or explicitly discard semantic drafts |
| S6-05 | Late GET/mutation from context A cannot overwrite context B |
| S6-06 | Published/released history is never editable through UI; server remains authoritative |
| S6-07 | Tests cover S1–S5 regression paths; typecheck/build pass |

## Nonnegotiable pre-execution gate
`S6-CONTRACT-001`: verify original approved H6-S6 plan text, actual error payloads, action lifecycle table and ownership rules against HEAD; append a reconciled contract annex. Any undiscovered behavior stays **NOT SPECIFIED — STOP**, not Codex discretion.

**Disposition:** `S6 POLICY ENVELOPE APPROVED / IMPLEMENTATION FREEZE CONDITIONAL / NO START`.
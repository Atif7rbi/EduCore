# EduCore — Assessment & Results Contract Freeze v1

Decision: ECF-AR-001
Status: APPROVED — normative boundaries; implementation conformance still requires verification
Date: 2026-10-11
Evidence baseline: Atif7rbi/EduCore @ 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d
Authority: frozen Architecture Pack, CDA-009 and Phase F transactional grant; this document does not supersede them.

## Existing capability
Learner AttemptPage, ResultsPage and ProgressPage already exist. API routes include attempt construction, response, finalization, read, regrade and progress. Future work audits and closes verified gaps, not rebuilds from scratch.

## Frozen contracts
AR-01. Attempt source is exactly ExamGeneration XOR PracticeActivity. Exam AttemptItem set equals generation items; Practice AttemptItem set equals activity membership at creation. Pin exact revisions, source, item order, Presented/Scoring/Classification snapshots. Later content edits never rewrite attempt truth.
AR-02. The only attempt lifecycle is in_progress → submitted | abandoned. Construction is atomic. While in progress, owner may mutate responses through approved operations; no frontend-invented reopening or lifecycle transitions. Transaction-time source/grant checks remain authoritative.
AR-03. Exactly one AttemptResponse per AttemptItem. While in_progress original_is_correct is NULL. At finalization unanswered means response NULL/correctness NULL; answered means response non-NULL/BOOLEAN correctness. Final responses immutable. Students cannot submit trusted scoring decisions.
AR-04. RegradeCorrections are append-only immutable history. Effective outcome uses the greatest correction_number if one exists; otherwise original outcome. UI never overwrites original evidence or edits sealed answers. Regrade only under separately approved management authority.
AR-05. Exact published/current pointers, not MAX/latest, control current content. Preserve historical provenance and distinguish completion from mastery.
AR-06. Follow approved measurement semantics: single-primary, multi-primary and supporting evidence distinctions; unanswered provides no skill-scoring evidence; insufficient evidence is not poor performance; no global student score v1.
AR-07. Deferred decisions remain deferred: DD-002 repetition, DD-004 temporal default, DD-005 sufficiency, DD-006 interpretation thresholds, DD-007 coverage population. No invented Layer 3 labels or longitudinal production materialization without amendment.
AR-08. Effective learner authorization requires full Phase F grant and precise actor/learner ownership; active enrollment alone is insufficient. 401/403/404 fail closed, 409 refreshes authoritative state, 422 preserves form input.
AR-09. Query/cache and asynchronous result handling must isolate authenticated principal and source; no late request may reveal another learner's data.

## Acceptance matrix
AR-T01: Practice/exam source XOR and exact pinned item-set construction.
AR-T02: Atomic creation under concurrency; grant and ownership revalidation in PostgreSQL.
AR-T03: Save in-progress responses and valid submit/abandon; no invalid transition.
AR-T04: Answered/unanswered correctness invariants; forbid post-finalization mutation.
AR-T05: Regrade append-only ordering, original vs effective result read.
AR-T06: No student scoring/regrade; cross-learner IDOR and disabled/revoked actor fail closed.
AR-T07: Results/progress/history present correct loading/empty/error/current views and no inferred mastery labels.
AR-T08: Context-switch late GET/mutation does not contaminate next user/resource.
AR-T09: Full relevant frontend, backend and PostgreSQL regression/concurrency gates with nonzero executed tests.

## Exceptions and execution boundary
No new routes, scoring algorithm, JSON schema, lifecycle, DB schema, permissions, analytics thresholds or user-facing capability is authorized. Unresolved DD/ADR: STOP → impact analysis → independent approval. Normative contract APPROVED; route-by-route conformance and future implementation NOT YET VERIFIED. No code, branch, commit, push, merge or deployment authorization.

# EduCore — H6-S6 Frontend Gap Classification & Remediation Register v1

**Audit:** S6-FG-001
**Date:** 2026-10-12
**Code baseline:** 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d
**Documentation branch:** docs/educore-execution-contract-freezes-v1
**Status:** SOURCE-LEVEL INVENTORY COMPLETE; BEHAVIORAL CONFORMANCE AND EXECUTION READY NOT VERIFIED
**Change boundary:** Documentation only. No application edits, test execution, migrations, Codex start, PR modification, merge or deployment.

## 1. Classification standard

- **G0 — Evidenced existing capability:** implementation and a named test explicitly exercise the behavior. This DOES NOT mean the test was rerun for this audit.
- **G1 — UI behavior gap:** defect demonstrated in a concrete test or reproducible state transition, with unchanged backend contract.
- **G2 — API integration mismatch:** demonstrated contradiction between client requests/response rendering and authoritative backend contract.
- **G3 — Missing approved workflow:** only actionable after original approved slice/plan evidence proves the workflow is required.
- **G4 — Architectural decision:** existing frozen authority cannot settle the change; STOP.
- **V — Verification gap:** missing negative/matrix coverage or full-run proof; not a confirmed software defect.

A source file containing an event handler, mountedRef or query hook does not itself guarantee lifecycle/race correctness.

## 2. Source inventory

The following production UI modules were read from exact H6-S5 baseline:
- TeacherWorkspacePage.tsx; TeacherContextSelector.tsx
- TeacherTopicsPanel.tsx; TeacherSkillPlacementsPanel.tsx
- TeacherLessonsPanel.tsx; TeacherLessonRevisionEditor.tsx
- TeacherAssessmentItemsPanel.tsx; TeacherAssessmentRevisionEditor.tsx
- TeacherPracticeActivitiesPanel.tsx; TeacherPracticeMembershipEditor.tsx
- TeacherExamTemplatesPanel.tsx; TeacherExamTemplateVersionEditor.tsx

Tests inspected:
- TeacherWorkspacePage.test.tsx
- TeacherContextSelector.test.tsx
- TeacherTopicsPanel.test.tsx
- TeacherSkillPlacementsPanel.test.tsx
- TeacherLessonAssessmentPanels.test.tsx
- TeacherRevisionEditors.test.tsx
- TeacherPracticeExamPanels.test.tsx

All paths above are under resources/frontend/teacher. Existing test source is evidence of *intent and assertions*, not actual runtime PASS.

## 3. Component-by-component classifications

| Component | Verified existing behavior / named test | Classification | Remaining proof |
|---|---|---|---|
| TeacherWorkspacePage | aggregated dirty flags; useBlocker/useBeforeUnload; test cancel/confirm navigation, invalid-context exit, conflict invalidation | G0 + V | all role/session transitions and 419; candidate HEAD run |
| TeacherContextSelector | exact scoped URL parent identity, active assignment/Subject eligibility, stale parent fetch cancellation and principal-scoped query; 14 test declarations | G0 + V | real API source/status changes and exhaustive context-switch matrix |
| TeacherTopicsPanel | exact-version topic read/create, published read-only, 422 retains draft, 409 retains draft/refetch; declared tests | G0 + V | delayed mutation across selected topic/context + 403/404/419 matrix |
| TeacherSkillPlacementsPanel | exact-version placement/home topic, read-only published, conflicting placement, provenance, delete confirm; declared tests | G0 + V | late add/remove outcome after context switch, permissions loss |
| TeacherLessonsPanel | draft create, historical read-only, conflict refresh, parent disappearance; panel suite | G0 + V | same-resource lifecycle mutation races and disabled teacher |
| TeacherLessonRevisionEditor | invalid JSON blocked client-side, revision editor/dirty support; Editor tests | G0 partial + V | specific released revision mutation rejection, 401/419 and context A→B pending write |
| TeacherAssessmentItemsPanel | exact-version create, published read-only, 409 refresh, published/retired transitions; panel suite | G0 + V | delayed retire/publish result, principal switch safety |
| TeacherAssessmentRevisionEditor | invalid JSON blocked, released revision read-only; Editor tests | G0 + V | detailed revision/skill action conflict races and server 422 |
| TeacherPracticeActivitiesPanel | archived creation, delayed Practice A mutation isolation, semantic create/edit guard, 404 fail-closed, 409 refresh | G0 + V | cross-principal mutation, 401/419, multiple concurrent mutation orders |
| TeacherPracticeMembershipEditor | server membership order, dirty selection protection, active last-item guard tests | G0 + V | concurrent active membership revalidation from real PostgreSQL; no stale false success |
| TeacherExamTemplatesPanel | late Template A mutation isolation, dirty edit replacement, historical read-only tests | G0 + V | cross-context late status change and global session handling |
| TeacherExamTemplateVersionEditor | released/published history, 409 with rule draft retained, 422, generation after unmount, JSON semantic dirty guard tests | G0 + V + S6-TYPE-001 | runtime object/array rules_payload transport, authority race, conflict detail rendering |

**Counts:** 12 inventoried UI modules; seven named test files inspected. Test declaration counts are not executed test counts, especially for parameterized it.each.

## 4. Confirmed source-level contract risks requiring narrowly targeted tests

**FG-001 — S6-TYPE-001 — VERIFY, not proven defect:** TeacherRulesPayload permits JSON arrays and objects. Controller request validator declares rules_payload present/array. PHP JSON object decoding may result in array; cannot assert outright rejection without Laravel feature tests. Test empty array, object, empty object, null, scalar, and actual request encoding. Do not change backend shape on assumption.

**FG-002 — S6-ERR-001 — UX interpretation risk:** Error normalizer preserves blockers string arrays from 409 curriculum_version_not_ready but formats them through same field-array abstraction used for 422. Practice/Exam panels use Object.values(error.details).flat() to render text. Check whether machine error codes leak into user-readable error messages and whether conflict refresh remains authoritative. Do not invent a new response contract.

**FG-003 — V / cross-cutting:** There is evidence for late mutation isolation in Practice and Exam and stale query cancellation in ContextSelector, but no full ten-panel actor/context/resource-change matrix yet. Not a confirmed bug. Add controlled deferred-promise tests only where a concrete gap is demonstrated.

**FG-004 — V / session:** sessionEvents detects 401 and 419 for ordinary protected requests and excludes auth lifecycle URLs. Verify effect of 419 during dirty forms and pending panel mutations, and ensure revocation never grants continued edit authority.

## 5. Conditional candidate work register (not approved code tasks)

| ID | Requirement | Test/assessment first | Remediation permitted only if |
|---|---|---|---|
| S6-W01 | Route/context dirty exit invariant | TeacherWorkspacePage and ContextSelector tests on candidate HEAD | a failing transition is proven |
| S6-W02 | Taxonomy panel A→B pending write isolation | deferred request tests on Topics/Placements | an out-of-context state write is reproduced |
| S6-W03 | Lesson/Assessment published transition authority | lifecycle refetch and delayed mutation tests | current UI displays illegal action or stale success |
| S6-W04 | Practice/Exam late status/selection race | expand existing controlled race tests | verified asynchronous defect |
| S6-W05 | 409 blockers vs 422 field details | response fixture plus user-visible assertions | confirmed UX mismatch with approved semantics |
| S6-W06 | rules_payload object/array transport | real Laravel feature cases and frontend JSON parser tests | mismatch confirmed, remedial contract approved |
| S6-W07 | Auth/session/context 401/403/404/419 | global session and panel tests, RoleGuard regression | demonstrated unauthorized content/action persists |

Tests and fixes above must not be interpreted as a license for speculative new workflows or backend changes.

## 6. Dependency gates and STOP conditions

- Original broad Phase H H6 plan RECOVERED; *later exact H6-S6 slice-level acceptance source* still not independently located, as recorded in audit 14.
- Source-level teacher API inventory (63 endpoints) and authoritative lock protocol exist; live controller payload/DTO tests and multi-process PostgreSQL evidence still required.
- All future tests must run in verified isolated non-production DB; full executed test counts, typecheck and build are required, and no execution is authorized by this register.
- STOP if fixing any suspected frontend issue requires a new endpoint, lifecycle state, teacher permission, DB schema change, deferred DD, or change to exact historical facts.

## 7. Handoff decision

**FRONTEND GAP CLASSIFICATION: COMPLETE AT SOURCE/TARGETED-TEST-INVENTORY LEVEL.**
**CONFIRMED FUNCTIONAL CODE DEFECTS: NONE ESTABLISHED BY THIS READ-ONLY AUDIT.**
**FOCUSED RISK ITEMS: FG-001..FG-004 OPEN FOR TEST/VERIFICATION.**
**H6-S6 IMPLEMENTATION REMEDIATION SCOPE: NOT AUTHORIZED/NOT YET PROVEN.**
**S6-CONTRACT-001: OPEN. EXECUTION READY: NO.**

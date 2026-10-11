# EduCore — H6-S6 API / Frontend Traceability Matrix v1

**Gate:** S6-CONTRACT-001 (partial evidence annex)
**Status:** DOCUMENTED / ADDITIONAL VERIFICATION REQUIRED — NOT EXECUTION READY
**Date:** 2026-10-11
**Read-only implementation baseline:** 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d
**Documentation branch:** docs/educore-execution-contract-freezes-v1

## Source of truth

- routes/api.php lines 790–950, scoped Teacher assignment / curriculum/version endpoints.
- resources/frontend/teacher/api.ts (teacher client/read/query keys).
- TeacherWorkspacePage.tsx, TeacherContextSelector.tsx and teacher panels.
- TeacherWorkspacePage.test.tsx, TeacherPracticeExamPanels.test.tsx.
- Frozen Architecture Pack, CDA-009, ECF-API-001 / ECF-AUTH-001 / ECF-FE-001.
- **The original approved Phase H Implementation Plan S6 text is still not located.** This matrix cannot stand in for the approved S6 plan.

## Teacher-authoring routes mapped to controllers

All endpoint path fragments below are *relative to the existing nested Teacher assignment/curriculum/version scope*, whose outer guards and exact URL prefix must be rechecked before sending an execution packet. The endpoint table is based on literal route lines, not inferred new APIs.

| Group | Route line(s) | Backend controller | Supported operations |
|---|---|---|---|
| Teacher context reads | 790–810 | TeacherWorkspaceReadController | Exact assignment, curriculum and version context reads |
| Curriculum and version | 818–899 | TeacherCurriculumAuthoringController | Curriculum list/create/update; version list/create/update/publish/retire; taxonomy topics, placements, home topics CRUD |
| Lessons | 904–915 | TeacherLessonAssessmentAuthoringController | Lesson list/create/update; revision list/create/update; revision skill attach/remove; release; publish/unpublish |
| Assessments | 917–928 | TeacherLessonAssessmentAuthoringController | Assessment list/create/update; revision list/create/update; revision skill attach/remove; release; publish/retire |
| Practice | 929–936 | TeacherPracticeExamAuthoringController | Practice list/read items/create/update/activate/archive; add/remove memberships |
| Exams | 938–949 | TeacherPracticeExamAuthoringController | Template list/create/update/activate/archive; version list/detail/create/update/publish/retire; build generation |

**Important:** This is a controller-level source inventory. A complete HTTP method/URL + request/response + exception-code matrix is NOT YET VERIFIED; controller-specific validation and backend service paths must be inspected before approval.

## Frontend mapping

| Group | Frontend client / panel | Current evidence | Remaining task |
|---|---|---|---|
| Context | TeacherContextSelector.tsx; TeacherWorkspacePage.tsx; fetchTeacherAssignments, fetchTeacherCurricula, fetchTeacherCurriculumVersions | 403/404 handling; guarded navigation, query keys | Inspect actor/context guard for every transition and late response |
| Taxonomy | TeacherTopicsPanel.tsx; TeacherSkillPlacementsPanel.tsx; teacher topic/placement APIs | Client CRUD exists, dirty propagation present | Exact lifecycle/error/mutation matrix; 409, 422, late A→B coverage |
| Lessons | TeacherLessonsPanel.tsx, TeacherLessonRevisionEditor.tsx; teacher lesson/revision APIs | CRUD and revision/skills/release client functions exist | Confirm published/released read-only and pending mutation origin guards |
| Assessment | TeacherAssessmentItemsPanel.tsx, TeacherAssessmentRevisionEditor.tsx; assessment APIs | CRUD and revision/skills/release/publish/retire functions exist | Confirm exact version eligibility, lifecycle/status and error contract |
| Practice | TeacherPracticeActivitiesPanel.tsx, TeacherPracticeMembershipEditor.tsx | Existing tests name late A→B mutation, final active membership, dirty replacement | Map exact status transitions and backend collision semantics |
| Exam | TeacherExamTemplatesPanel.tsx, TeacherExamTemplateVersionEditor.tsx; exam APIs | Existing tests name late A→B mutation, 409 conflict, 422 errors, published history | Check all create/update/status/generation outcomes and selected-version racing |

## Verified test signposts (tests exist, NOT rerun)

From TeacherWorkspacePage.test.tsx: dirty-navigation cancel and accept; unavailable context navigation after 403/404; taxonomy conflict refetch.

From TeacherPracticeExamPanels.test.tsx: late Practice A mutation after selecting B; late Template A mutation after selecting B; late 404 after context unmount; mutable vs published history; 422 detail rendering; 409 conflict handling; generation success after unmount; final active Practice membership prohibition.

Presence of these test cases alone does not prove all S6 requirements or the controller transaction-time integrity contract.

## Remaining reconciliation register

- **S6-R001 BLOCKER:** Recover exact frozen Phase H Implementation Plan v1 S6 section and approved version; distinguish original S6 deliverables from the forward-package nonregression envelope.
- **S6-R002 OPEN:** Expand route inventory into exact HTTP method + full path + endpoint method, payload, status/error envelope and backend service source, then match frontend call and response type.
- **S6-R003 OPEN:** Panel-by-panel audit of 401/403/404/409/422, principal/assignment/resource switch with pending GET and mutation, semantic dirty guard, and unmount.
- **S6-R004 OPEN:** Link S6-facing scenarios to PostgreSQL transactional authorization and lock-order tests; record test file and assertions.
- **S6-R005 OPEN:** Candidate implementation HEAD must run targeted and full regression, typecheck/build and true PostgreSQL concurrency suites when explicitly authorized.

## Disposition

**API ROUTE INVENTORY — COMPLETE AT CONTROLLER-GROUP LEVEL.**
**REQUEST/RESPONSE AND LIFECYCLE MATRIX — INCOMPLETE.**
**ORIGINAL S6 PLAN — NOT RECOVERED.**
**S6-CONTRACT-001 — OPEN.**
**CODEX EXECUTION — NOT AUTHORIZED.**

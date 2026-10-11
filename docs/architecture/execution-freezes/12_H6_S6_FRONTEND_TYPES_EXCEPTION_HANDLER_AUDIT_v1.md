# EduCore — H6-S6 Frontend Types and Central Exception Handler Audit v1

Date: 2026-10-11
Baseline: 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d
Status: SOURCE REVIEW COMPLETED; FOLLOW-UP CONTRACT TESTS OPEN; EXECUTION READY = NO

## Evidence

Compared resources/frontend/teacher/types.ts and api.ts with TeacherWorkspaceReadController, TeacherCurriculumAuthoringController, TeacherLessonAssessmentAuthoringController, TeacherPracticeExamAuthoringController and their serializers. Inspected resources/frontend/api/client.ts, errors.ts, sessionEvents.ts, app/Http/Responses/ApiResponse.php and bootstrap/app.php. No runtime tests were executed.

## DTO alignment findings

| DTO | Backend serializer match |
|---|---|
| TeacherSubjectAssignment | Key names match including nested subject and timestamps |
| TeacherCurriculum / CurriculumVersion | Key names match |
| TeacherTopic / Skill / SkillPlacement / HomeTopic | Key names and nullable nested relations match |
| TeacherLesson / Revision / RevisionSkill | Key names match |
| TeacherAssessmentItem / Revision / RevisionSkill | Key names match |
| TeacherPracticeActivity / Membership | Key names match |
| TeacherExamTemplate / Version / Generation | Key names match |

This is structural comparison, not a runtime proof of every null, enum or nested JSON schema value.

## Finding S6-TYPE-001 — OPEN — Rules payload transport compatibility requires explicit test

Frontend TeacherRulesPayload accepts a JSON object or array. TeacherExamTemplateVersionEditor.parseRules accepts a parsed non-null object, including arrays; the backend templateVersionInput uses Laravel present and array validation. PHP request decoding may represent JSON objects as associative arrays: **do not claim object submissions are rejected from source shape alone**. Verify object, array, empty object, empty array, null and scalar via real feature tests and compare exact contract. No speculative replacement of the approved schema.

## Finding S6-ERR-001 — OPEN — Conflict blocker codes and validation messages conflated

Backend CurriculumVersionNotReady produces HTTP 409, code curriculum_version_not_ready and details containing blockers as string array. ApiResponse.error permits arbitrary details arrays. Frontend normalizeDetails in errors.ts retains arrays of strings, including blockers, so the blocker array is **not dropped**, but it is treated using the same Record<string,string[]> structure as validation field messages. Some panels flatten all details values and display them as strings; blocker codes may be displayed without appropriate interpretation. Audit actual callers and decide display policy without inventing a new API.

## Central exception / response mapping: verified source

| Status | Code | Origin |
|---|---|---|
| 401 | unauthenticated | AuthenticationException |
| 403 | forbidden | AuthorizationException / HTTP exception |
| 404 | not_found | ModelNotFoundException / NotFoundHttpException |
| 409 | concurrency_conflict | ConcurrencyConflict |
| 409 | integrity_conflict | IntegrityConstraintViolation |
| 409 | domain-specific code | TeacherAuthoringConflict controller catch |
| 409 | curriculum_version_not_ready | CurriculumVersionNotReady controller catch |
| 419 | csrf_token_mismatch | TokenMismatchException / HTTP exception |
| 422 | validation_failed | ValidationException, with field-to-string-array details |
| 405 | method_not_allowed | HTTP exception |
| 429 | too_many_requests | HTTP exception |
| 500 | internal_error | Generic Throwable for API routes |

ApiResponse.success wraps data under the data key; the frontend apiRequest unwraps data. ApiResponse.error wraps code/message and optional details under error. Axios uses credentials, XSRF-TOKEN cookie and X-XSRF-TOKEN header. The frontend interceptor emits runtime session expired for 401 and csrf for 419, excluding auth lifecycle URLs. Request ID is taken from the X-Request-ID response header.

## Open gates for S6-CONTRACT-001

- Recover exact approved Phase H S6 implementation plan.
- Run isolated Laravel feature tests for rules JSON shape behavior, HTTP statuses, blocker handling and requestId.
- Audit panels using flattened error details for machine-readable blocker codes versus human-readable field messages.
- Verify DTO enum/null assertions in backend feature tests and actual frontend fixture/test alignment.
- Complete per-action transactional authorization / lock ordering evidence on isolated PostgreSQL.
- Repeat tests at actual authorized candidate execution SHA.

**Disposition:** Source-level key/DTO alignment found; two focused follow-up items remain OPEN; no approved code changes, merge or deployment; S6 execution readiness NO.

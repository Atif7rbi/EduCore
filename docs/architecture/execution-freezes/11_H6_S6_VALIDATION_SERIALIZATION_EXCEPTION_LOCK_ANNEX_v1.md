# EduCore — H6-S6 Validation, Serialization, Exception & Lock Annex v1

**Gate:** S6-CONTRACT-001  
**Date:** 2026-10-11  
**Status:** SOURCE CONTRACTS RECONCILED AT CONTROLLER/SERVICE LEVEL; ORIGINAL S6 PLAN + RUNTIME TESTS STILL OPEN  
**Read-only baseline:** 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d  
**Document branch:** docs/educore-execution-contract-freezes-v1

## 1. Directly verified source files

- app/Http/Controllers/Api/Teacher/TeacherCurriculumAuthoringController.php
- app/Http/Controllers/Api/Teacher/TeacherLessonAssessmentAuthoringController.php
- app/Http/Controllers/Api/Teacher/TeacherPracticeExamAuthoringController.php
- app/Http/Controllers/Api/Teacher/TeacherWorkspaceReadController.php
- app/Http/Responses/ApiResponse.php
- app/Application/TeacherAuthoring/ManageTeacherCurriculumAuthoring.php
- app/Application/TeacherAuthoring/ManageTeacherLessonAssessmentAuthoring.php
- app/Application/TeacherAuthoring/ManageTeacherPracticeExamAuthoring.php
- app/Application/TeacherAuthoring/FilterActiveTeacherCurriculumRead.php
- app/Application/Exceptions/TeacherAuthoringConflict.php

Cross-reference 10_H6_S6_EXHAUSTIVE_API_MATRIX_v2.md for the 63 route signatures. No tests were executed in this documentation pass.

## 2. Response envelope (verified)

ApiResponse::success(data, status=200) returns JSON {"data":data} with supplied HTTP status. ApiResponse::error(code,message,status,details?) returns {"error":{"code":code,"message":message,"details":...}} with details present only when supplied. Controller mutation wrappers return 200 by default and 201 for specified creation operations. The frontend normalizer expects error.code, error.message, field-array details and X-Request-ID header if present. Some non-validation 409 errors use error.details as a domain blocker object: **do not assume all details values are field-error arrays**.

## 3. Nested payload validators (verified)

| Helper | Required/present fields | Nullable/optional fields | Value constraints |
|---|---|---|---|
| lessonRevisionInput | revision_number; primary_topic_id; content_payload; content_schema_version | — | revision_number integer >=1; primary_topic_id UUID; content_payload array; schema integer >=1 |
| assessmentRevisionInput | revision_number; difficulty; content_payload; content_schema_version; scoring_payload; scoring_schema_version | primary_topic_id nullable UUID | difficulty easy/medium/hard; revisions and schema versions integers >=1; payload arrays |
| templateVersionInput(create=true) | version_number; rules_payload (present); rules_schema_version | label nullable string max255 | version_number integer >=1; rules_payload array (empty allowed); schema integer >=1 |
| templateVersionInput(create=false) | rules_payload (present); rules_schema_version | label nullable string max255 | no version_number request field in update helper; rules_payload array; schema integer >=1 |

These are PHP request validation shape checks, not proof that the payload JSON schema or domain semantic validators accept every syntactically valid array. Refer to deferred DD-018 and approved assessment rules.

## 4. Serializer truth (verified directly)

| Group | Serialized response fields |
|---|---|
| Curriculum | id, subject_id, education_stage_id, teacher_subject_assignment_id, name |
| CurriculumVersion | id, curriculum_id, version_number, label, status |
| Topic | id, curriculum_version_id, name, display_order |
| SkillVersionPlacement | id, skill_id, curriculum_version_id, skill (conditional relation), home_topics (conditional relation) |
| SkillHomeTopic | id, placement_id, topic_id, curriculum_version_id, topic (conditional relation) |
| Lesson | id, curriculum_version_id, title, description, status, display_order, published_revision_id |
| LessonRevision | id, lesson_id, curriculum_version_id, revision_number, primary_topic_id, content_payload, content_schema_version, released_at |
| LessonRevisionSkill | id, lesson_revision_id, skill_version_placement_id, curriculum_version_id |
| AssessmentItem | id, curriculum_version_id, item_type, internal_label, status, published_revision_id |
| AssessmentItemRevision | id, assessment_item_id, curriculum_version_id, revision_number, primary_topic_id, difficulty, content_payload, content_schema_version, scoring_payload, scoring_schema_version, released_at |
| AssessmentRevisionSkill | id, assessment_item_revision_id, skill_version_placement_id, curriculum_version_id, role |
| Practice | id, curriculum_version_id, lesson_id, name, description, status |
| PracticeActivityItem | id, practice_activity_id, assessment_item_revision_id, assessment_item_id, curriculum_version_id, display_order |
| ExamTemplate | id, curriculum_version_id, name, description, status, published_version_id |
| ExamTemplateVersion | id, exam_template_id, curriculum_version_id, version_number, label, status, rules_payload, rules_schema_version |
| ExamGeneration | id, exam_template_version_id, curriculum_version_id, generated_at, items (assessment_item_revision_id, assessment_item_id) |

WorkspaceReadController also exposes assignment/catalog reads; exact response fields must be checked against frontend types before closing the whole contract.

## 5. Error / lifecycle mapping (source verified; do not extrapolate across endpoints)

- TeacherCurriculumAuthoringController catches TeacherAuthoringConflict → HTTP 409 with domain errorCode; additionally catches CurriculumVersionNotReady → 409 code curriculum_version_not_ready, with a blockers array in error.details. This differs in shape from field-oriented 422 details.
- TeacherLessonAssessmentAuthoringController catches TeacherAuthoringConflict → 409 with domain errorCode.
- TeacherPracticeExamAuthoringController catches TeacherAuthoringConflict → 409 with domain errorCode.
- Laravel request validation can produce 422; exact centralized rendering must be confirmed from exception handler and feature tests. Absent resource queries use firstOrFail; exact global HTTP handler/JSON envelope and 403-versus-404 behavior require explicit feature verification.
- Teacher content read scope filters active Teacher User, active TeacherSubjectAssignment and active Subject. The class also scopes ownership; this does not replace mutation-time checks.
- Lesson/assessment creation defaults to draft; Practice creation defaults archived; ExamTemplate creation defaults active; ExamTemplateVersion creation defaults draft.
- AssessmentItem mutations enforce draft where specified; revisions enforce unreleased status; published/released content history must not be rewritten.
- Practice update requires archived. Activating practice checks membership; modifications to active membership enforce additional guards; do not infer a universal archive/reactivate behavior without full service path review.
- ExamTemplate update and version creation require active template; version update requires draft. Version publish forbids retired state; retire requires published. Generation requires published template version and source eligibility checks.

## 6. Transaction / lock source observations

- ManageTeacherLessonAssessmentAuthoring uses a transaction runner for mutations, and obtains lockForUpdate on CurriculumVersion, Lesson/Assessment aggregate, relevant revisions, Topic and SkillVersionPlacement where needed. It uses explicit lifecycle authority methods.
- ManageTeacherPracticeExamAuthoring similarly uses transaction runner; obtains lockForUpdate on CurriculumVersion, PracticeActivity, ExamTemplate, ExamTemplateVersion and selected revisions/parent aggregates where applicable. Generation uses a separate authority callback to BuildExamGeneration.
- ManageTeacherCurriculumAuthoring uses transaction-wrapped operations, locking version, topics/placements and related rows in source-specific paths; deterministic sorted ID handling exists.
- FilterActiveTeacherCurriculumRead enforces active source context for reads, but mutation-time transactional ownership checks and authorization locking must be checked in the actual transaction runner/authority service before claiming end-to-end lock-order compliance.
- Controller source presence is not a substitute for two-connection PostgreSQL concurrency proof.

## 7. Remaining closure gates

**S6-R001 BLOCKING:** recover the exact original approved Phase H Implementation Plan v1 S6 subsection and reconcile deliverables word-for-word. It has not been located in the repository tree.
**S6-R002 PARTIAL:** full route/validation/serializer group mapping completed in the linked annexes, but exact per-route return codes, frontend TypeScript contract matching, centralized exception rendering and complete service semantic validation remain to be cross-checked.
**S6-R003 OPEN:** complete panel-by-panel late mutation, dirty navigation and error mapping matrix; existing tests cover many but not all combinations.
**S6-R004 OPEN:** inspect the transaction runner/ownership authorization lock sequence and test evidence for each affected aggregate under PostgreSQL.
**S6-R005 OPEN:** run nonzero real tests at the authorized execution candidate SHA. No test run in this documentation task.

## 8. Decision

The nested validators, concrete serializer field lists, 409 wrapper behavior, and service lifecycle/locking observations are recorded from current source. **No new lifecycle or status is authorized.** The 63-route inventory and this annex materially reduce execution uncertainty but do not replace the missing primary S6 plan or runtime proof.

**S6-CONTRACT-001: OPEN.  H6-S6 EXECUTION READY: NO.  CODEX / MERGE / DEPLOYMENT: NOT AUTHORIZED.**

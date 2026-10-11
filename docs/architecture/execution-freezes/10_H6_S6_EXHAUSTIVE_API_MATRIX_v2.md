# EduCore — H6-S6 Full API / Frontend Source Trace Matrix v2

**2026-10-11 | Baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`
**Status:** ALL ROUTES INVENTORIED; EXECUTION CONTRACTS PARTIALLY VERIFIED; S6-CONTRACT-001 OPEN

All paths are relative to Laravel `/api`; parent teacher middleware is `web`, `auth:web`, `active`, `teacher`. Columns are source-derived. Payload keys record directly visible controller validators only, NOT nested helper validation. Frontend function names are candidate mappings and must be reviewed wherever marked CHECK. Success formats and failure statuses require backend handler/feature-test verification; do not infer from route registration.

## TeacherWorkspaceReadController

| Method | Full path | Action | Validated request keys | Client mapping |
|---|---|---|---|---|
| GET | `/api/teacher/subject-assignments` | `subjectAssignments` | — | `fetchTeacherAssignments` |
| GET | `/api/teacher/education-stages` | `educationStages` | — | CHECK client mapping |
| GET | `/api/teacher/skills` | `skills` | — | `fetchTeacherSkills` |

## TeacherCurriculumAuthoringController

| Method | Full path | Action | Validated request keys | Client mapping |
|---|---|---|---|---|
| GET | `/api/teacher/subject-assignments/{assignmentId}/subject-assignments` | `curricula` | — | `fetchTeacherCurricula` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula` | `storeCurriculum` | name, education_stage_id, teacher_subject_assignment_id, subject_id | `CHECK client mapping` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}` | `updateCurriculum` | name, education_stage_id, teacher_subject_assignment_id, subject_id | `CHECK client mapping` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions` | `versions` | — | `fetchTeacherCurriculumVersions` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions` | `storeVersion` | version_number, label, status, curriculum_id | `CHECK client mapping` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}` | `updateVersion` | version_number, label, status, curriculum_id | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/publish` | `publishVersion` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/retire` | `retireVersion` | — | `CHECK client mapping` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/topics` | `topics` | — | `fetchTeacherTopics` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/topics` | `storeTopic` | name, display_order, curriculum_version_id | `createTeacherTopic` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/topics/{topicId}` | `updateTopic` | name, display_order, curriculum_version_id | `updateTeacherTopic` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/skill-placements` | `placements` | — | `fetchTeacherSkillPlacements` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/skill-placements` | `storePlacement` | skill_id, curriculum_version_id | `CHECK client mapping` |
| DELETE | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/skill-placements/{placementId}` | `destroyPlacement` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/skill-placements/{placementId}/home-topics` | `storeHomeTopic` | topic_id, curriculum_version_id | `createTeacherHomeTopic` |
| DELETE | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/skill-placements/{placementId}/home-topics/{homeTopicId}` | `destroyHomeTopic` | — | `deleteTeacherHomeTopic` |

## TeacherLessonAssessmentAuthoringController

| Method | Full path | Action | Validated request keys | Client mapping |
|---|---|---|---|---|
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons` | `lessons` | — | `fetchTeacherLessons` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons` | `storeLesson` | title, description, display_order, curriculum_version_id | `createTeacherLesson` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}` | `updateLesson` | title, description, display_order, curriculum_version_id, status, published_revision_id | `updateTeacherLesson` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/revisions` | `lessonRevisions` | — | `fetchTeacherLessonRevisions` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/revisions` | `storeLessonRevision` | helper:lessonRevisionInput | `createTeacherLessonRevision` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/revisions/{revisionId}` | `updateLessonRevision` | helper:lessonRevisionInput | `updateTeacherLessonRevision` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/revisions/{revisionId}/skills` | `lessonRevisionSkills` | — | `fetchTeacherLessonRevisionSkills` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/revisions/{revisionId}/skills` | `storeLessonRevisionSkill` | skill_version_placement_id | `createTeacherLessonRevisionSkill` |
| DELETE | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/revisions/{revisionId}/skills/{skillId}` | `destroyLessonRevisionSkill` | — | `deleteTeacherLessonRevisionSkill` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/revisions/{revisionId}/release` | `releaseLessonRevision` | — | `releaseTeacherLessonRevision` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/publish` | `publishLesson` | published_revision_id | `publishTeacherLesson` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/lessons/{lessonId}/unpublish` | `unpublishLesson` | — | `CHECK client mapping` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items` | `assessments` | — | `fetchTeacherAssessmentItems` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items` | `storeAssessment` | item_type, internal_label, curriculum_version_id | `CHECK client mapping` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}` | `updateAssessment` | item_type, internal_label, curriculum_version_id, status, published_revision_id | `CHECK client mapping` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/revisions` | `assessmentRevisions` | — | `fetchTeacherAssessmentRevisions` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/revisions` | `storeAssessmentRevision` | helper:assessmentRevisionInput | `createTeacherAssessmentRevision` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/revisions/{revisionId}` | `updateAssessmentRevision` | helper:assessmentRevisionInput | `updateTeacherAssessmentRevision` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/revisions/{revisionId}/skills` | `assessmentRevisionSkills` | — | `fetchTeacherAssessmentRevisionSkills` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/revisions/{revisionId}/skills` | `storeAssessmentRevisionSkill` | skill_version_placement_id, role | `createTeacherAssessmentRevisionSkill` |
| DELETE | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/revisions/{revisionId}/skills/{skillId}` | `destroyAssessmentRevisionSkill` | — | `deleteTeacherAssessmentRevisionSkill` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/revisions/{revisionId}/release` | `releaseAssessmentRevision` | — | `releaseTeacherAssessmentRevision` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/publish` | `publishAssessment` | published_revision_id | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/assessment-items/{assessmentItemId}/retire` | `retireAssessment` | — | `CHECK client mapping` |

## TeacherPracticeExamAuthoringController

| Method | Full path | Action | Validated request keys | Client mapping |
|---|---|---|---|---|
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities` | `practiceActivities` | — | `fetchTeacherPracticeActivities` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities/{practiceId}/items` | `practiceItems` | — | `fetchTeacherPracticeItems` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities` | `storePractice` | lesson_id, name, description | `CHECK client mapping` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities/{practiceId}` | `updatePractice` | lesson_id, name, description | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities/{practiceId}/activate` | `activatePractice` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities/{practiceId}/archive` | `archivePractice` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities/{practiceId}/items` | `addPracticeItems` | assessment_item_revision_ids, assessment_item_revision_ids.*, display_order | `addTeacherPracticeItems` |
| DELETE | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/practice-activities/{practiceId}/items/{membershipId}` | `removePracticeItem` | — | `deleteTeacherPracticeItem` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates` | `examTemplates` | — | `fetchTeacherExamTemplates` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/versions` | `templateVersions` | — | `fetchTeacherExamTemplateVersions` |
| GET | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/versions/{templateVersionId}` | `templateVersion` | — | `fetchTeacherExamTemplateVersion` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates` | `storeTemplate` | name, description | `CHECK client mapping` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}` | `updateTemplate` | name, description | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/activate` | `activateTemplate` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/archive` | `archiveTemplate` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/versions` | `storeTemplateVersion` | helper:templateVersionInput | `CHECK client mapping` |
| PUT | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/versions/{templateVersionId}` | `updateTemplateVersion` | helper:templateVersionInput | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/versions/{templateVersionId}/publish` | `publishTemplateVersion` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/versions/{templateVersionId}/retire` | `retireTemplateVersion` | — | `CHECK client mapping` |
| POST | `/api/teacher/subject-assignments/{assignmentId}/curricula/{curriculumId}/versions/{versionId}/exam-templates/{templateId}/versions/{templateVersionId}/generations` | `buildGeneration` | generator_version, seed, assessment_item_revision_ids, assessment_item_revision_ids.* | `CHECK client mapping` |

## Failure semantics requiring endpoint-specific confirmation

- `401`: unauthenticated/session handling; `403`: forbidden; `404`: absent or inaccessible resource; `409`: lifecycle/concurrency conflict; `422`: controller validation failure; `5xx`: non-success. These are expected UX categories, **not independently proven exact status for each route**.
- Required next inventory: `ApiResponse` response envelope, 201 return paths, serializer fields, controller helper validation, exception mapping, service transaction and lock protocol; align to frontend type definitions.
- Required next evidence: exact approved Phase H S6 plan, negative role/assignment/version lifecycle tests, 409/422 and async race tests; no tests were executed in this documentation pass.

**Disposition:** 63 Teacher controller routes inventoried (three direct context reads and sixty nested authoring routes). Error/payload nested rules and implementation acceptance NOT FULLY VERIFIED. No Codex start / merge / deploy authorization.
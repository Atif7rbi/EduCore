# EduCore Physical Schema Contract v1

Status: FROZEN
Version: 1.0
Project: EduCore

## Purpose
This is the canonical physical contract for the 30 EduCore v1 domain tables.

Implementation must not invent omitted:
- nullability
- defaults
- candidate keys
- composite FK shapes
- lifecycle values
- delete actions

## Global rules
- UUID primary keys.
- created_at TIMESTAMPTZ NOT NULL for domain tables.
- updated_at only where mutable.
- Unconstrained domain strings use TEXT.
- Closed states use TEXT + CHECK.
- Structured documents/configurations use JSONB.
- Historical FKs use RESTRICT / NO ACTION.
- No tenant_id in v1.
- No implicit MAX/latest.
- No protected historical CASCADE.
- Deferred Decisions remain unresolved until explicitly amended.

## Identity

### users
Target canonical domain shape:

id UUID PK
name TEXT NOT NULL
email TEXT NOT NULL
email_verified_at TIMESTAMPTZ NULL
password TEXT NOT NULL
status TEXT NOT NULL DEFAULT 'active'
remember_token current Laravel auth-compatible nullable representation
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK status IN ('active','disabled')
UNIQUE INDEX lower(email)

The already-applied baseline differs in string types and timestamp nullability.
A forward identity-normalization migration is REQUIRED; no destructive rewrite of migration history.

### learner_profiles
id UUID PK
user_id UUID NOT NULL UNIQUE
created_at TIMESTAMPTZ NOT NULL

FK user_id -> users(id) RESTRICT

## Curriculum

### subjects
id UUID PK
name TEXT NOT NULL UNIQUE
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

### curricula
id UUID PK
subject_id UUID NOT NULL
name TEXT NOT NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

FK subject_id -> subjects(id) RESTRICT

### curriculum_versions
id UUID PK
curriculum_id UUID NOT NULL
version_number INTEGER NOT NULL
label TEXT NOT NULL
status TEXT NOT NULL DEFAULT 'draft'
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK version_number >= 1
CHECK status IN ('draft','published','retired')
UNIQUE(curriculum_id,version_number)

FK curriculum_id -> curricula(id) RESTRICT

## Taxonomy

### topics
id UUID PK
curriculum_version_id UUID NOT NULL
name TEXT NOT NULL
display_order INTEGER NOT NULL DEFAULT 0
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK display_order >= 0
UNIQUE(id,curriculum_version_id)

FK curriculum_version_id -> curriculum_versions(id) RESTRICT

### skills
id UUID PK
name TEXT NOT NULL
description TEXT NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

No name uniqueness.

### skill_lineages
id UUID PK
source_skill_id UUID NOT NULL
target_skill_id UUID NOT NULL
lineage_type TEXT NOT NULL
created_at TIMESTAMPTZ NOT NULL

CHECK source_skill_id <> target_skill_id
CHECK lineage_type IN ('replaced_by','split_into','merged_into')
UNIQUE(source_skill_id,target_skill_id,lineage_type)

FK source_skill_id -> skills(id) RESTRICT
FK target_skill_id -> skills(id) RESTRICT

### skill_version_placements
id UUID PK
skill_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
created_at TIMESTAMPTZ NOT NULL

UNIQUE(skill_id,curriculum_version_id)
UNIQUE(id,curriculum_version_id)

FK skill_id -> skills(id) RESTRICT
FK curriculum_version_id -> curriculum_versions(id) RESTRICT

### skill_home_topics
id UUID PK
placement_id UUID NOT NULL
topic_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
created_at TIMESTAMPTZ NOT NULL

UNIQUE(placement_id,topic_id)

FK curriculum_version_id
  -> curriculum_versions(id) RESTRICT

FK (placement_id,curriculum_version_id)
  -> skill_version_placements(id,curriculum_version_id) RESTRICT

FK (topic_id,curriculum_version_id)
  -> topics(id,curriculum_version_id) RESTRICT

No UNIQUE(placement_id).

## Learning

### lessons
id UUID PK
curriculum_version_id UUID NOT NULL
title TEXT NOT NULL
description TEXT NULL
status TEXT NOT NULL DEFAULT 'draft'
display_order INTEGER NOT NULL DEFAULT 0
published_revision_id UUID NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK status IN ('draft','published','retired')
CHECK display_order >= 0
UNIQUE(id,curriculum_version_id)

FK curriculum_version_id -> curriculum_versions(id) RESTRICT

Current pointer:
(published_revision_id,id)
-> lesson_revisions(id,lesson_id)
RESTRICT

### lesson_revisions
id UUID PK
lesson_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
revision_number INTEGER NOT NULL
primary_topic_id UUID NOT NULL
content_payload JSONB NOT NULL
content_schema_version INTEGER NOT NULL
released_at TIMESTAMPTZ NULL
created_at TIMESTAMPTZ NOT NULL

CHECK revision_number >= 1
CHECK content_schema_version >= 1
CHECK jsonb_typeof(content_payload) = 'object'

UNIQUE(lesson_id,revision_number)
UNIQUE(id,lesson_id)
UNIQUE(id,curriculum_version_id)

FK (lesson_id,curriculum_version_id)
  -> lessons(id,curriculum_version_id) RESTRICT

FK (primary_topic_id,curriculum_version_id)
  -> topics(id,curriculum_version_id) RESTRICT

### lesson_revision_skills
id UUID PK
lesson_revision_id UUID NOT NULL
skill_version_placement_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
created_at TIMESTAMPTZ NOT NULL

UNIQUE(lesson_revision_id,skill_version_placement_id)

FK curriculum_version_id
  -> curriculum_versions(id) RESTRICT

FK (lesson_revision_id,curriculum_version_id)
  -> lesson_revisions(id,curriculum_version_id) RESTRICT

FK (skill_version_placement_id,curriculum_version_id)
  -> skill_version_placements(id,curriculum_version_id) RESTRICT

### lesson_progresses
id UUID PK
learner_profile_id UUID NOT NULL
lesson_revision_id UUID NOT NULL
status TEXT NOT NULL DEFAULT 'in_progress'
started_at TIMESTAMPTZ NOT NULL
completed_at TIMESTAMPTZ NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK status IN ('in_progress','completed')
CHECK status/completed_at consistency
CHECK completed_at IS NULL OR completed_at >= started_at

UNIQUE(learner_profile_id,lesson_revision_id)

FK learner_profile_id -> learner_profiles(id) RESTRICT
FK lesson_revision_id -> lesson_revisions(id) RESTRICT

Creation eligibility:
- LessonProgress may reference only a LessonRevision with released_at IS NOT NULL.
- DB-backed enforcement must serialize creation against the referenced revision lifecycle.

## Assessment

### assessment_items
id UUID PK
curriculum_version_id UUID NOT NULL
item_type TEXT NOT NULL
internal_label TEXT NULL
status TEXT NOT NULL DEFAULT 'draft'
published_revision_id UUID NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK status IN ('draft','published','retired')
UNIQUE(id,curriculum_version_id)

FK curriculum_version_id -> curriculum_versions(id) RESTRICT

Current pointer:
(published_revision_id,id)
-> assessment_item_revisions(id,assessment_item_id)
RESTRICT

No item_type CHECK until DD-015 resolves.

### assessment_item_revisions
id UUID PK
assessment_item_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
revision_number INTEGER NOT NULL
primary_topic_id UUID NULL
difficulty TEXT NOT NULL
content_payload JSONB NOT NULL
content_schema_version INTEGER NOT NULL
scoring_payload JSONB NOT NULL
scoring_schema_version INTEGER NOT NULL
released_at TIMESTAMPTZ NULL
created_at TIMESTAMPTZ NOT NULL

CHECK revision_number >= 1
CHECK difficulty IN ('easy','medium','hard')
CHECK content_schema_version >= 1
CHECK scoring_schema_version >= 1
CHECK jsonb_typeof(content_payload) = 'object'
CHECK jsonb_typeof(scoring_payload) = 'object'

UNIQUE(assessment_item_id,revision_number)
UNIQUE(id,assessment_item_id)
UNIQUE(id,curriculum_version_id)

FK (assessment_item_id,curriculum_version_id)
  -> assessment_items(id,curriculum_version_id) RESTRICT

Nullable Primary Topic FK:
(primary_topic_id,curriculum_version_id)
  -> topics(id,curriculum_version_id) RESTRICT

### assessment_item_revision_skills
id UUID PK
assessment_item_revision_id UUID NOT NULL
skill_version_placement_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
role TEXT NOT NULL
created_at TIMESTAMPTZ NOT NULL

CHECK role IN ('primary','supporting')
UNIQUE(assessment_item_revision_id,skill_version_placement_id)

FK curriculum_version_id
  -> curriculum_versions(id) RESTRICT

FK (assessment_item_revision_id,curriculum_version_id)
  -> assessment_item_revisions(id,curriculum_version_id) RESTRICT

FK (skill_version_placement_id,curriculum_version_id)
  -> skill_version_placements(id,curriculum_version_id) RESTRICT

## Practice

### practice_activities
id UUID PK
curriculum_version_id UUID NOT NULL
lesson_id UUID NULL
name TEXT NOT NULL
description TEXT NULL
status TEXT NOT NULL DEFAULT 'active'
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK status IN ('active','archived')
UNIQUE(id,curriculum_version_id)

FK curriculum_version_id
  -> curriculum_versions(id) RESTRICT

Nullable composite FK:
(lesson_id,curriculum_version_id)
  -> lessons(id,curriculum_version_id) RESTRICT

### practice_activity_items
id UUID PK
practice_activity_id UUID NOT NULL
assessment_item_revision_id UUID NOT NULL
assessment_item_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
display_order INTEGER NOT NULL
created_at TIMESTAMPTZ NOT NULL

CHECK display_order >= 0

UNIQUE(practice_activity_id,assessment_item_revision_id)
UNIQUE(practice_activity_id,assessment_item_id)
UNIQUE(practice_activity_id,display_order)

FK (practice_activity_id,curriculum_version_id)
  -> practice_activities(id,curriculum_version_id) RESTRICT

FK (assessment_item_revision_id,curriculum_version_id)
  -> assessment_item_revisions(id,curriculum_version_id) RESTRICT

FK (assessment_item_revision_id,assessment_item_id)
  -> assessment_item_revisions(id,assessment_item_id) RESTRICT

FK (assessment_item_id,curriculum_version_id)
  -> assessment_items(id,curriculum_version_id) RESTRICT

## Exam

### exam_templates
id UUID PK
curriculum_version_id UUID NOT NULL
name TEXT NOT NULL
description TEXT NULL
status TEXT NOT NULL DEFAULT 'active'
published_version_id UUID NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK status IN ('active','archived')
UNIQUE(id,curriculum_version_id)

FK curriculum_version_id
  -> curriculum_versions(id) RESTRICT

Current pointer:
(published_version_id,id)
-> exam_template_versions(id,exam_template_id)
RESTRICT

### exam_template_versions
id UUID PK
exam_template_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
version_number INTEGER NOT NULL
label TEXT NULL
status TEXT NOT NULL DEFAULT 'draft'
rules_payload JSONB NOT NULL
rules_schema_version INTEGER NOT NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK version_number >= 1
CHECK rules_schema_version >= 1
CHECK status IN ('draft','published','retired')
CHECK jsonb_typeof(rules_payload) = 'object'

UNIQUE(exam_template_id,version_number)
UNIQUE(id,exam_template_id)
UNIQUE(id,curriculum_version_id)

FK (exam_template_id,curriculum_version_id)
  -> exam_templates(id,curriculum_version_id) RESTRICT.

### exam_generations
id UUID PK
exam_template_version_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
rules_snapshot JSONB NOT NULL
rules_schema_version INTEGER NOT NULL
generator_version TEXT NOT NULL
seed TEXT NOT NULL
generated_at TIMESTAMPTZ NULL physically; MUST be non-NULL at successful generation COMMIT
created_at TIMESTAMPTZ NOT NULL

CHECK rules_schema_version >= 1
CHECK jsonb_typeof(rules_snapshot) = 'object'

UNIQUE(id,curriculum_version_id)

FK (exam_template_version_id,curriculum_version_id)
  -> exam_template_versions(id,curriculum_version_id) RESTRICT.

### exam_generation_items
id UUID PK
exam_generation_id UUID NOT NULL
assessment_item_revision_id UUID NOT NULL
assessment_item_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
selection_position INTEGER NOT NULL
created_at TIMESTAMPTZ NOT NULL

CHECK selection_position >= 0

UNIQUE(exam_generation_id,selection_position)
UNIQUE(exam_generation_id,assessment_item_revision_id)
UNIQUE(exam_generation_id,assessment_item_id)

CDA-003 candidate key:
UNIQUE(
 id,
 exam_generation_id,
 assessment_item_revision_id,
 assessment_item_id,
 curriculum_version_id
)

FK (exam_generation_id,curriculum_version_id)
  -> exam_generations(id,curriculum_version_id) RESTRICT

FK (assessment_item_revision_id,curriculum_version_id)
  -> assessment_item_revisions(id,curriculum_version_id) RESTRICT

FK (assessment_item_revision_id,assessment_item_id)
  -> assessment_item_revisions(id,assessment_item_id) RESTRICT

FK (assessment_item_id,curriculum_version_id)
  -> assessment_items(id,curriculum_version_id) RESTRICT

## Attempts

### attempts
id UUID PK
learner_profile_id UUID NOT NULL
exam_generation_id UUID NULL
practice_activity_id UUID NULL
curriculum_version_id UUID NOT NULL
status TEXT NOT NULL DEFAULT 'in_progress'
started_at TIMESTAMPTZ NULL physically; MUST be non-NULL at successful Attempt instantiation COMMIT
finalized_at TIMESTAMPTZ NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK status IN ('in_progress','submitted','abandoned')
CHECK exactly one source
CHECK status/finalized_at consistency
CHECK finalized_at IS NULL OR finalized_at >= started_at

UNIQUE(id,curriculum_version_id)

Candidate key:
UNIQUE(id,exam_generation_id,curriculum_version_id)

Partial UNIQUE(exam_generation_id)
WHERE exam_generation_id IS NOT NULL

FK learner_profile_id
  -> learner_profiles(id) RESTRICT

Nullable exam-source FK:
(exam_generation_id,curriculum_version_id)
  -> exam_generations(id,curriculum_version_id) RESTRICT

Nullable practice-source FK:
(practice_activity_id,curriculum_version_id)
  -> practice_activities(id,curriculum_version_id) RESTRICT

### attempt_items
id UUID PK
attempt_id UUID NOT NULL
assessment_item_revision_id UUID NOT NULL
assessment_item_id UUID NOT NULL
curriculum_version_id UUID NOT NULL
exam_generation_id UUID NULL
exam_generation_item_id UUID NULL
presentation_position INTEGER NOT NULL
presented_payload JSONB NOT NULL
presented_schema_version INTEGER NOT NULL
scoring_snapshot JSONB NOT NULL
scoring_schema_version INTEGER NOT NULL
primary_topic_id UUID NULL
created_at TIMESTAMPTZ NOT NULL

CHECK presentation_position >= 0
CHECK presented_schema_version >= 1
CHECK scoring_schema_version >= 1
CHECK jsonb_typeof(presented_payload) = 'object'
CHECK jsonb_typeof(scoring_snapshot) = 'object'

UNIQUE(attempt_id,presentation_position)
UNIQUE(attempt_id,assessment_item_revision_id)
UNIQUE(attempt_id,assessment_item_id)

CHECK exam_generation_id and exam_generation_item_id
are both NULL or both NOT NULL.

FK (attempt_id,curriculum_version_id)
  -> attempts(id,curriculum_version_id) RESTRICT

Exam-path provenance FK:
(attempt_id,exam_generation_id,curriculum_version_id)
  -> attempts(id,exam_generation_id,curriculum_version_id) RESTRICT

Exact GenerationItem provenance FK:
(exam_generation_item_id,
 exam_generation_id,
 assessment_item_revision_id,
 assessment_item_id,
 curriculum_version_id)
  -> exam_generation_items(
       id,
       exam_generation_id,
       assessment_item_revision_id,
       assessment_item_id,
       curriculum_version_id
     ) RESTRICT

FK (assessment_item_revision_id,curriculum_version_id)
  -> assessment_item_revisions(id,curriculum_version_id) RESTRICT

FK (assessment_item_revision_id,assessment_item_id)
  -> assessment_item_revisions(id,assessment_item_id) RESTRICT

FK (assessment_item_id,curriculum_version_id)
  -> assessment_items(id,curriculum_version_id) RESTRICT

Nullable Primary Topic FK:
(primary_topic_id,curriculum_version_id)
  -> topics(id,curriculum_version_id) RESTRICT

### attempt_item_classification_skills
id UUID PK
attempt_item_id UUID NOT NULL
skill_id UUID NOT NULL
role TEXT NOT NULL
created_at TIMESTAMPTZ NOT NULL

CHECK role IN ('primary','supporting')
UNIQUE(attempt_item_id,skill_id)

FK attempt_item_id -> attempt_items(id) RESTRICT
FK skill_id -> skills(id) RESTRICT

### attempt_responses
id UUID PK
attempt_item_id UUID NOT NULL UNIQUE
response_payload JSONB NULL
answer_change_count INTEGER NOT NULL DEFAULT 0
time_spent_ms BIGINT NOT NULL DEFAULT 0
original_is_correct BOOLEAN NULL
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK answer_change_count >= 0
CHECK time_spent_ms >= 0

FK attempt_item_id -> attempt_items(id) RESTRICT

No fixed JSON shape until DD-018 resolves.

### regrade_corrections
id UUID PK
attempt_response_id UUID NOT NULL
correction_number INTEGER NOT NULL
corrected_is_correct BOOLEAN NOT NULL
reason TEXT NOT NULL
corrected_at TIMESTAMPTZ NOT NULL
created_at TIMESTAMPTZ NOT NULL

CHECK correction_number >= 1
UNIQUE(attempt_response_id,correction_number)

FK attempt_response_id -> attempt_responses(id) RESTRICT

## Analytics

### evidence_scopes
id UUID PK
label TEXT NULL
description TEXT NULL
definition_payload JSONB NOT NULL
definition_schema_version INTEGER NOT NULL
status TEXT NOT NULL DEFAULT 'active'
created_at TIMESTAMPTZ NOT NULL
updated_at TIMESTAMPTZ NULL

CHECK definition_schema_version >= 1
CHECK jsonb_typeof(definition_payload) = 'object'
CHECK status IN ('active','retired')

### materialized_skill_performances
id UUID PK
learner_profile_id UUID NOT NULL
skill_id UUID NOT NULL
evidence_scope_id UUID NOT NULL
single_primary_correct_count BIGINT NOT NULL DEFAULT 0
single_primary_answered_count BIGINT NOT NULL DEFAULT 0
supporting_positive_count BIGINT NOT NULL DEFAULT 0
supporting_exposure_count BIGINT NOT NULL DEFAULT 0
last_rebuilt_at TIMESTAMPTZ NOT NULL
created_at TIMESTAMPTZ NOT NULL

UNIQUE(learner_profile_id,skill_id,evidence_scope_id)

CHECK all counters >= 0
CHECK single_primary_correct_count <= single_primary_answered_count
CHECK supporting_positive_count <= supporting_exposure_count

FK learner_profile_id -> learner_profiles(id) RESTRICT
FK skill_id -> skills(id) RESTRICT
FK evidence_scope_id -> evidence_scopes(id) RESTRICT

## Deferred-decision protection
This contract intentionally does not decide:
- Skill Home Topic final semantic cardinality.
- Assessment item_type closed set.
- PracticeActivity reactivation.
- ExamTemplate reactivation.
- Exact Lesson/Assessment/Response/Rules JSON schemas.
- Exact repetition policy.
- Analytics thresholds/default temporal windows/eligible Skill denominator.

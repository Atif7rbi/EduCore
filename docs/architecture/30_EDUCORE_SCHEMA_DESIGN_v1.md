# EduCore Schema Design v1

Status: FROZEN
Version: 1.0

## Schema categories
A Authoritative Transactional/Historical.
B Catalog/Governance.
C Historical Revision/Version State.
D Explicit Materialized Cache.
Only MaterializedSkillPerformance is Category D in v1.

## Conventions
PostgreSQL public schema.
Single data space; no tenant_id.
UUID domain PKs.
TEXT for unconstrained domain strings.
TIMESTAMPTZ.
Every domain table: created_at TIMESTAMPTZ NOT NULL.
updated_at only where mutable.
Closed states: TEXT + CHECK.
JSONB only for whole documents/configurations; required objects have no blanket {} default and carry positive schema version where needed.
No blanket soft delete.
Historical FKs RESTRICT/NO ACTION.

## Integrity carriers
Duplicated version/item/generation IDs may exist only to support composite referential integrity; they are derived from authoritative parents and are not independent truth.

## Current pointers
Lesson.published_revision_id, AssessmentItem.published_revision_id, ExamTemplate.published_version_id use same-parent composite FKs plus lifecycle triggers.

## Table inventory (30 domain tables)
users
learner_profiles
subjects
curricula
curriculum_versions
topics
skills
skill_lineages
skill_version_placements
skill_home_topics
lessons
lesson_revisions
lesson_revision_skills
lesson_progresses
assessment_items
assessment_item_revisions
assessment_item_revision_skills
practice_activities
practice_activity_items
exam_templates
exam_template_versions
exam_generations
exam_generation_items
attempts
attempt_items
attempt_item_classification_skills
attempt_responses
regrade_corrections
evidence_scopes
materialized_skill_performances

## Key reconciled table rules

subjects: name UNIQUE.

curriculum_versions: UNIQUE(curriculum_id,version_number), version>=1, status draft|published|retired.

topics: UNIQUE(id,curriculum_version_id), display_order>=0.

skill_lineages: source!=target; type replaced_by|split_into|merged_into; UNIQUE(source,target,type).

skill_version_placements: UNIQUE(skill_id,curriculum_version_id), UNIQUE(id,curriculum_version_id).

skill_home_topics: UNIQUE(placement_id,topic_id); no UNIQUE(placement_id).

lessons: UNIQUE(id,curriculum_version_id); status draft|published|retired.

lesson_revisions: primary_topic_id NOT NULL; UNIQUE(lesson_id,revision_number); UNIQUE(id,lesson_id); UNIQUE(id,curriculum_version_id); JSON content object; released_at nullable one-way.

lesson_progresses: UNIQUE(learner_profile_id,lesson_revision_id); status in_progress|completed.

assessment_items: UNIQUE(id,curriculum_version_id); item_type immutable; status draft|published|retired.

assessment_item_revisions: primary_topic_id NULLABLE; difficulty easy|medium|hard; content/scoring JSON objects; UNIQUE(item,revision_number), UNIQUE(id,item), UNIQUE(id,curriculum_version_id).

assessment_item_revision_skills: role primary|supporting; UNIQUE(revision,placement).

practice_activities: UNIQUE(id,curriculum_version_id); status active|archived.

practice_activity_items: UNIQUE(activity,revision), UNIQUE(activity,logical item), UNIQUE(activity,display_order).

exam_templates: UNIQUE(id,curriculum_version_id); status active|archived.

exam_template_versions: lifecycle draft|published|retired; UNIQUE(template,version_number), UNIQUE(id,template), UNIQUE(id,curriculum_version_id).

exam_generations: immutable successful generation; UNIQUE(id,curriculum_version_id).

exam_generation_items: UNIQUE(generation,position), UNIQUE(generation,revision), UNIQUE(generation,logical item), plus CDA-003 candidate key over id+generation+revision+item+version.

attempts: exact source XOR; status in_progress|submitted|abandoned; UNIQUE(id,curriculum_version_id); candidate key id+exam_generation+version; partial UNIQUE exam_generation_id.

attempt_items: immutable; unique attempt+position/revision/logical item; exact exam provenance composite FKs; presented/scoring JSON snapshots; nullable Primary Topic.

attempt_item_classification_skills: stable Skill identity; role primary|supporting; UNIQUE(attempt_item,skill); >=1 primary per item; CDA-006 exact source classification set.

attempt_responses: UNIQUE(attempt_item_id); nonnegative counters; mutable only in progress.

regrade_corrections: UNIQUE(response,correction_number); number>=1; immutable.

evidence_scopes: semantic definition immutable; active→retired.

materialized_skill_performances: UNIQUE(learner,skill,scope); Lifetime-only, bounded BIGINT counters, last_rebuilt_at NOT NULL, disposable.

## CDA-005
Deferred Attempt aggregate check proves exact source-set equality for both exam and practice instantiation.

## CDA-006
DB verifies relational Classification Snapshot equality (Primary Topic and Skill/role set) against released source revision.
JSON Presented/Scoring semantic transformation correctness stays application-side.

## No duplicate derived truth
No effective_is_correct, Topic performance table, Student Progress Snapshot, global mastery score, generic raw-evidence ledger.

## Enforcement hierarchy
Declarative constraints → deferred constraint triggers → normal triggers → application-only semantics.

## Known DB boundary
No claim of absolute post-commit child-append prevention for every immutable child collection without an aggregate-finalized marker. DB still protects completeness, referential integrity, UPDATE and DELETE; application service boundary controls append.

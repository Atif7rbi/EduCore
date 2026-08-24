# EduCore DDL Plan v1

Status: FROZEN
Version: 1.0

## Bootstrap policy
EduCore bootstrap is PostgreSQL-first.
SQLite is not supported for domain/integration schema verification.
Composer/bootstrap scripts must not automatically execute pending migrations.
Migration execution occurs only through the approved checkpoint workflow.

## Migration families
00_identity
10_curriculum
20_taxonomy
30_learning
40_assessment
50_practice
60_exam_generation
70_attempts
80_analytics
90_integrity
95_indexes

Apply family-by-family: create → syntax-check → migrate → PostgreSQL introspection → positive/negative probes → checkpoint close.

Physical DDL for every family must conform to 35_EDUCORE_PHYSICAL_SCHEMA_CONTRACT_v1.md.

## 00_identity
Baseline IMPLEMENTED + VERIFIED.

Before 10_curriculum, execute one forward identity-normalization migration:
- users.name VARCHAR -> TEXT;
- users.email VARCHAR -> TEXT;
- users.password VARCHAR -> TEXT;
- users.status VARCHAR -> TEXT;
- users.created_at -> TIMESTAMPTZ NOT NULL;
- users.updated_at remains TIMESTAMPTZ NULL;
- preserve chk_users_status and uq_users_email_ci;
- do not rewrite already-applied migration history.

Post-normalization PostgreSQL introspection and regression tests are required before closing Identity.

## 10_curriculum
subjects → curricula → curriculum_versions.
subjects.name UNIQUE.
CurriculumVersion version>=1 and lifecycle values draft|published|retired.

## 20_taxonomy
skills → topics → skill_lineages → skill_version_placements → skill_home_topics.
Use composite same-version FKs.
No UNIQUE(placement_id).

## 30_learning
lessons → lesson_revisions → add Lesson current-pointer FK → lesson_revision_skills → lesson_progresses.
LessonRevision.primary_topic_id NOT NULL.
Revision number positive and parent-scoped unique.

## 40_assessment
assessment_items → assessment_item_revisions → add current-pointer FK → assessment_item_revision_skills.
AssessmentRevision.primary_topic_id nullable.
Release requires >=1 primary Skill.

## 50_practice
practice_activities → practice_activity_items.
Same-version Lesson and Assessment provenance.
Active aggregate completeness deferred to COMMIT.

## 60_exam_generation
exam_templates → exam_template_versions → add current-pointer FK → exam_generations → exam_generation_items.
CDA-003 composite candidate key on generation items supports exact downstream Attempt provenance.

Historical Generation sealing:
- insert ExamGeneration with generated_at NULL inside the creation transaction;
- insert complete GenerationItems;
- set generated_at exactly once;
- deferred COMMIT validation requires generated_at non-NULL;
- after generated_at is set, GenerationItems reject INSERT/UPDATE/DELETE.

## 70_attempts
attempts → attempt_items → attempt_item_classification_skills → attempt_responses → regrade_corrections.

Attempt aggregate sealing:
- create Attempt with started_at NULL;
- create complete AttemptItems, Classification Snapshot rows, and Responses;
- set started_at exactly once before COMMIT;
- deferred validation requires started_at non-NULL;
- after started_at is set, historical AttemptItems and Classification Snapshot rows reject INSERT/UPDATE/DELETE.

Attempt CHECK = ExamGeneration XOR PracticeActivity.
Partial unique = at most one Attempt per ExamGeneration.

Exact exam provenance uses composite FKs through Attempt, Generation, GenerationItem, Revision, Item, CurriculumVersion.

CDA-005:
Exam AttemptItems = complete GenerationItems.

Practice source-set equality is enforced only during Attempt instantiation:
- lock/serialize the PracticeActivity membership source;
- capture the complete source set;
- create Attempt and AttemptItems in the same transaction;
- validate equality before COMMIT.

After COMMIT, historical AttemptItems are independent from later PracticeActivity mutations.
No persistent trigger may require old Practice Attempts to equal the current Activity membership.

Attempt finalization is one atomic transaction:
- lock the Attempt;
- compute/write original_is_correct for answered responses;
- transition Attempt from in_progress to submitted|abandoned;
- validate response/final-state invariants at transaction end.

Cross-row finalization invariants must permit the temporary intra-transaction state required by this choreography and validate the committed state using deferred enforcement where necessary.

CDA-006: deferred classification equality check.
AttemptItem Primary Topic NULL-safe equals source revision Primary Topic.
Historical (Skill,role) set exactly equals source revision classification after resolving placement→Skill.

## 80_analytics
evidence_scopes → materialized_skill_performances.
Lifetime-only cache, bounded BIGINT counts.

## 90_integrity
Separate trigger migrations for curriculum, learning, assessment, practice, exam, attempt, analytics.
Use DB::unprepared raw SQL for PostgreSQL trigger functions/constraint triggers.
Down: drop trigger then function.

Curriculum structural lock freezes Topics, placements/home-topic structure, logical Lesson membership, logical AssessmentItem membership once version is published/retired.
It does not itself prohibit new revisions of existing Lessons/Items or prospective Practice/Exam configuration.

CDA-007: TemplateVersion cannot retire while it remains ExamTemplate.published_version_id.

## 95_indexes
Approved explicit secondary indexes:
idx_curricula_subject_id
idx_topics_curriculum_version_order
idx_skill_lineages_target_skill
idx_skill_version_placements_curriculum_version
idx_skill_home_topics_topic
idx_lessons_curriculum_version_order
idx_lesson_revision_skills_placement
idx_assessment_items_curriculum_version
idx_assessment_item_revision_skills_placement
idx_practice_activities_curriculum_version
idx_practice_activities_lesson
idx_exam_templates_curriculum_version
idx_exam_generations_template_version
idx_attempts_practice_activity
idx_attempts_learner_started_at
idx_attempt_items_assessment_item
idx_attempt_item_classification_skills_skill

Reuse PK/UNIQUE indexes.
No blanket status/timestamp indexes.
No baseline JSONB GIN.

## Verification
Inspect actual tables, column types/nullability, constraints, FK delete actions, indexes, triggers.
Migration output alone is insufficient.

Negative probes include invalid lifecycle, cross-version FK, released mutation, empty aggregate, duplicate logical item, invalid source, source-set mismatch, wrong classification snapshot, invalid Regrade.

## Gate
Remaining families execute only after external Engineering Review and explicit APPROVED FOR IMPLEMENTATION.

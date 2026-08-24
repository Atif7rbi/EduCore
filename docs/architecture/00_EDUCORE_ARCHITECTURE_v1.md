# EduCore Architecture v1

Status: FROZEN
Version: 1.0
Review Mode: Validation / Contradiction Detection

## Purpose
EduCore is an educational learning and assessment platform, initially focused on Saudi Qudrat quantitative preparation, designed as a reusable product rather than a clone.

## Product domains
1. Identity & Access
2. Curriculum & Learning Content
3. Assessment
4. Progress & Analytics
5. Guidance
6. Teacher Supervision
7. Commercial & Entitlements
8. Content Delivery & Protection

Current core schema focuses on Identity, Curriculum, Taxonomy, Learning, Assessment, Practice, Exam Generation, Attempts, Regrade, and Analytics foundations.

## Historical truth
Historical facts must not depend on mutable current records. Exact revisions/versions are pinned.

Logical identity and version state are separate:
Lesson → LessonRevision
AssessmentItem → AssessmentItemRevision
ExamTemplate → ExamTemplateVersion

Current state is explicit through parent pointers, never MAX/latest:
Lesson.published_revision_id
AssessmentItem.published_revision_id
ExamTemplate.published_version_id

Published/released semantic history is protected.

## DB/Application boundary
Database: keys, uniqueness, same-version integrity, lifecycle legality, immutable history, provenance, aggregate completeness where cleanly enforceable.
Application: JSON semantic validation, authorization, pedagogical rules, generation/scoring algorithms, repetition, representativeness, interpretation, learner-context resolution.

## Identity
User = account/actor identity.
LearnerProfile = educational learner identity.
User 1 → 0..1 LearnerProfile.
Learner history references LearnerProfile.
Disablement does not delete learner history.

## Curriculum
Subject → Curriculum → CurriculumVersion.
CurriculumVersion lifecycle: draft → published → retired.
Published/retired structural membership is protected.

## Taxonomy
Topic is version-local.
Skill is stable logical identity across CurriculumVersions.
SkillVersionPlacement represents version-specific placement.
SkillLineage supports replaced_by, split_into, merged_into without analytical equivalence.
Home Topic is version-specific; physical envelope 0..N; final cardinality deferred.
Primary Topic is independent from Skill Home Topic.

## Learning
Lesson belongs to exactly one CurriculumVersion.
LessonRevision stores exact content state.
released_at is one-way; released semantics immutable.
LessonProgress pins exact LessonRevision and means exposure/completion, not mastery.

## Assessment
AssessmentItem is the single atomic assessable unit.
AssessmentItemRevision separates presentable content, server-side scoring semantics, and classification.
Difficulty: easy|medium|hard.
Primary Topic on AssessmentItemRevision is nullable.
Skill role: primary|supporting.
Multiple primary Skills allowed.
Every released revision requires >=1 primary Skill.

## Practice
PracticeActivity is prospective configuration, optionally linked to a same-version Lesson.
PracticeActivityItem pins exact AssessmentItemRevision.
Historical Attempts are independent from later PracticeActivityItem mutations.

## Exam
ExamTemplate → ExamTemplateVersion.
TemplateVersion lifecycle: draft → published → retired.
Rules are declarative data; generator is deterministic code.
ExamGeneration preserves TemplateVersion, rules snapshot/schema version, generator version, seed, generated_at.
ExamGenerationItem is exact selection truth.

## Attempt
Attempt source is exactly ExamGeneration XOR PracticeActivity.
Lifecycle: in_progress → submitted|abandoned.
Instantiation is atomic and must capture complete source item set.

Exam AttemptItem set = ExamGenerationItem set.
Practice AttemptItem set = PracticeActivityItem set at instantiation.

AttemptItem preserves exact revision, logical item, Presented Item Snapshot, Scoring Snapshot, and Classification Snapshot.

Relational Classification Snapshot must exactly match source released revision at instantiation.
Presented/scoring JSON semantic correctness remains application responsibility.

## Response/Regrade
Exactly one AttemptResponse per AttemptItem.
While in_progress original_is_correct is NULL.
At finalization: unanswered => response NULL and correctness NULL; answered => response non-NULL and correctness BOOLEAN.
After finalization response is immutable.

Regrade appends immutable RegradeCorrections.
Effective outcome = greatest correction_number if any, else original outcome.

## Measurement
Layer 1 Raw/Historical Evidence.
Layer 2 Derived Statements.
Layer 3 Interpretation.

Single-primary answered eligible items may provide individual Skill evidence.
Multi-primary correct = Composite Positive Evidence over the set.
Multi-primary incorrect = Ambiguous Composite Failure Evidence.
Supporting correct may yield Supporting Positive Evidence; incorrect yields no individual negative evidence.
No Supporting Accuracy.
Unanswered = no Skill scoring evidence.

EvidenceScope makes analytical context explicit.
MaterializedSkillPerformance key = LearnerProfile × Skill × EvidenceScope, Lifetime-only, disposable.
Exact repetition policy remains deferred.

## Topic and progress views
Skill-placement Topic View and Historical Item-topic Performance are distinct.
Student Progress Current-State View is live, contextual, not a Snapshot.
Learning Completion and Assessment Performance remain separate.
Insufficient Evidence != poor performance.
No global student score in v1.

## Data architecture
Laravel 12 + PostgreSQL.
UUID PKs, TEXT for unconstrained domain strings, TIMESTAMPTZ, JSONB whole documents, TEXT+CHECK states, composite FKs, RESTRICT historical relationships.
Single data space; no tenant_id.

## Change control
Frozen decisions change only through documented issue → amendment → impact analysis → approval → spec update → implementation.

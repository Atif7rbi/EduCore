# EduCore Domain Model v1

Status: FROZEN
Version: 1.0

## Identity
User 1 → 0..1 LearnerProfile.
User is account/actor identity.
LearnerProfile is educational learner identity and owns learner-history relationships.

## Curriculum
Subject 1 → N Curriculum.
Curriculum 1 → N CurriculumVersion.
CurriculumVersion lifecycle draft → published → retired.

## Taxonomy
Topic belongs to one CurriculumVersion and has no stable cross-version identity in v1.
Skill is stable logical identity.
SkillLineage records replaced_by|split_into|merged_into.
SkillVersionPlacement = unique Skill × CurriculumVersion.
SkillHomeTopic links placement to same-version Topic; physical cardinality 0..N and final semantic cardinality is deferred.

## Learning
Lesson belongs to one CurriculumVersion, cannot move, lifecycle draft→published→retired, and has explicit published_revision pointer.
LessonRevision belongs to one Lesson/version, has revision_number >=1, primary_topic required, content document/schema version, release provenance.
Released revision is immutable.
LessonRevisionSkill classifies revision to SkillVersionPlacement, without primary/supporting role.
LessonProgress = unique LearnerProfile × LessonRevision, lifecycle in_progress→completed, and represents completion only.

## Assessment
AssessmentItem is atomic logical assessable identity, belongs to one CurriculumVersion, and item_type is stable identity metadata.
AssessmentItemRevision contains exact content, exact scoring, nullable Primary Topic, difficulty, and release provenance.
AssessmentItemRevisionSkill has role primary|supporting; multiple primary allowed; release requires >=1 primary.

## Practice
PracticeActivity belongs to one CurriculumVersion and optionally a same-version logical Lesson.
Status active|archived; reactivation semantics deferred.
PracticeActivityItem pins exact AssessmentItemRevision.
Within one Activity: exact revision, logical item, and display position are unique.
Active aggregate requires >=1 released item.
Historical Attempt does not depend on later PracticeActivityItem state.

## Exam
ExamTemplate belongs to one CurriculumVersion and has active|archived status.
ExamTemplateVersion lifecycle draft→published→retired and contains declarative rules.
ExamGeneration is one successful immutable deterministic generation.
ExamGenerationItem stores exact selected AssessmentItemRevision/logical item/order.

## Attempt
Attempt belongs to LearnerProfile and exactly one source: ExamGeneration XOR PracticeActivity.
At most one Attempt per ExamGeneration.
Lifecycle in_progress→submitted|abandoned.
Source and learner identity are immutable.

At instantiation:
Exam source-set equality: AttemptItems exactly equal GenerationItems.
Practice source-set equality: AttemptItems exactly equal ActivityItems as observed in the instantiation transaction.

AttemptItem is immutable and stores exact revision, logical item, version, presentation order, presented/scoring snapshots, Primary Topic, and optional GenerationItem provenance.

Historical classification:
AttemptItemClassificationSkill references stable Skill identity and role primary|supporting.
Every AttemptItem must have >=1 primary Skill.
Historical Primary Topic and full (Skill,role) set must exactly equal source released revision classification.

## AttemptResponse
Exactly one per AttemptItem, created at instantiation.
Mutable only while parent Attempt is in_progress.
Final state semantics:
response NULL => original correctness NULL.
response non-NULL => original correctness BOOLEAN.
Finalized responses immutable.

## Regrade
AttemptResponse 1 → 0..N RegradeCorrection.
Correction only for finalized answered response.
correction_number defines logical order.
Effective outcome is derived, never stored as duplicated mutable truth.

## Analytics
EvidenceScope defines immutable analytical semantics with active→retired lifecycle.
MaterializedSkillPerformance is disposable Lifetime cache keyed LearnerProfile×Skill×EvidenceScope.

## Derived concepts not authoritative entities
EffectiveScoringOutcome, CompositeEvidence, TopicView, Layer3Interpretation, Student Progress Current-State persistence, GlobalStudentScore.

## Aggregate transactions
Release/publication, PracticeActivity mutation, ExamGeneration creation, Attempt instantiation/finalization, and Regrade insertion are transactional consistency boundaries.

## Historical deletion
Protected relationships use restrictive semantics. Privacy deletion/anonymization is deferred explicit workflow.

## Applicable CurriculumVersion
Externally resolved; never inferred from MAX/latest.

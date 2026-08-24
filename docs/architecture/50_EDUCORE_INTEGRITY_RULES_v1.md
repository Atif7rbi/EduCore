# EduCore Integrity Rules v1

Status: FROZEN
Version: 1.0

## Enforcement hierarchy
Declarative constraints first, then deferred constraint triggers, then normal triggers, then application-only semantics.

## CurriculumVersion
Lifecycle draft→published→retired only.
When not draft, structural membership frozen:
Topic, SkillVersionPlacement, SkillHomeTopic structure, logical Lesson membership, logical AssessmentItem membership.
This lock does not itself block new revisions of existing logical content or prospective Practice/Exam configuration.

## Lesson
Lifecycle draft→published→retired.
Published requires same-Lesson released current revision.
Lesson cannot change CurriculumVersion.
LessonRevision release NULL→timestamp only.
Released revision and LessonRevisionSkills immutable.
LessonRevision Primary Topic required.
LessonProgress may be created only against a released LessonRevision.
LessonProgress.lesson_revision_id is immutable after creation.
Creation locks the referenced LessonRevision and verifies released_at IS NOT NULL before INSERT.
LessonProgress in_progress→completed only, with completed_at consistency.

## Assessment
AssessmentItem item_type and CurriculumVersion immutable.
Lifecycle draft→published→retired.
Published requires same-item released current revision.
AssessmentRevision release is one-way; released semantic fields/classification immutable.
Release requires >=1 primary Skill; multiple primary allowed.
AssessmentRevision Primary Topic nullable.

## Practice
PracticeActivity CurriculumVersion immutable; optional Lesson may change prospectively if same-version.
active|archived direction deferred.
At COMMIT active activity requires >=1 item and all pinned revisions released.
Duplicate logical/revision/order rejected.

## Exam
TemplateVersion lifecycle draft→published→retired.
Published semantic rules immutable.
Template current pointer must target same-template published version.

CDA-007: published→retired rejected while version remains current pointer.

Generation creation requires published TemplateVersion.
Generation is assembled with generated_at NULL.
At COMMIT generation requires >=1 item, all selected revisions released, and generated_at non-NULL.
generated_at transitions NULL→timestamp exactly once and is the aggregate seal.
After sealing, GenerationItems reject INSERT/UPDATE/DELETE.

## Attempt
Exact source XOR via CHECK.
Source/version composite FKs.
At most one Attempt per Generation.
Lifecycle in_progress→submitted|abandoned.
Learner/source/version immutable.
started_at is NULL only during atomic instantiation, then transitions NULL→timestamp exactly once and seals the Attempt aggregate.
After sealing, AttemptItems and AttemptItemClassificationSkills reject INSERT/UPDATE/DELETE.
finalized_at required only for final state and >= started_at.

At COMMIT Attempt must:
- have >=1 AttemptItem;
- give each AttemptItem exactly one Response;
- give each AttemptItem >=1 historical primary Skill;
- satisfy CDA-005 exact source-set equality;
- satisfy CDA-006 exact relational Classification Snapshot equality.

AttemptItem immutable immediately.
Exam provenance pair both null or both non-null.
Composite FKs prove exact GenerationItem provenance.

## CDA-005
Exam: AttemptItems exactly equal GenerationItems, no missing/extra.

Practice equality is a creation-time transactional invariant only:
- serialize/lock PracticeActivity membership;
- capture the source set;
- create the Attempt and complete AttemptItem set atomically;
- validate equality before COMMIT.

After successful COMMIT, AttemptItems become independent historical truth.
Later PracticeActivityItem changes must not cause historical Attempts to fail integrity checks.

## CDA-006
AttemptItem.primary_topic_id IS NOT DISTINCT FROM source AssessmentItemRevision.primary_topic_id.
Historical (skill_id,role) set exactly equals source revision classification after placement→Skill resolution.
No missing, extra, role-changed rows.

## JSON snapshot boundary
DB checks presence, JSON object type, schema version, immutability.
Application owns semantic correctness of presentation/scoring transformation.

## AttemptResponse
Exactly one per AttemptItem.
While ordinary attempt interaction is in_progress, original correctness remains NULL.

Finalization is one atomic transaction:
1. lock the Attempt;
2. score all answered Responses and write original_is_correct;
3. leave unanswered payload/correctness NULL;
4. transition Attempt in_progress → submitted|abandoned;
5. validate all final Response invariants at transaction end;
6. COMMIT.

Cross-row enforcement must therefore be deferred where immediate enforcement would reject this legitimate temporary intra-transaction state.

Committed final state:
NULL response ↔ NULL correctness.
non-NULL response ↔ BOOLEAN correctness.

After finalization Response is immutable.

## Regrade
Only finalized answered Response.
Application locks parent Response and allocates next correction number.
DB enforces positive unique numbering and sequencing guard where implemented.
Corrections immutable.
Effective outcome derived from max correction_number.

## EvidenceScope
Definition immutable.
Metadata mutable.
Lifecycle active→retired.

## Materialized cache
Freely rebuildable/mutable.
0<=correct<=answered.
0<=supporting_positive<=supporting_exposure.
No authoritative entity depends on it.

## DB does not decide
Repetition policy, representativeness, current learner CurriculumVersion, Primary Topic inference, full JSON semantics, semantic-vs-editorial identity judgment, Layer3 thresholds.

## Concurrency
DB-backed parent locking is mandatory for every competing mutation path of a protected aggregate.

Required shared lock targets:
- CurriculumVersion row: structural child mutation and publish/retire.
- LessonRevision / AssessmentItemRevision row: classification mutation and release.
- LessonRevision row: LessonProgress creation and release/mutation lifecycle checks.
- PracticeActivity row: membership mutation, active-completeness checks, and Practice Attempt instantiation.
- ExamGeneration row: GenerationItem mutation and generation sealing.
- Attempt row: AttemptItem/classification construction, aggregate sealing, and finalization.
- Response row: Regrade numbering.
- analytics rebuild key: serialize or safely upsert.

A deferred constraint trigger validates transaction-end state but is not a substitute for serialization against concurrent write-skew.
Every competing path must acquire the same parent lock before inspecting or mutating child state.
When an operation requires multiple locks, acquire them in deterministic parent-to-child order.

## Tests
Required negative families cover lifecycle reversal, cross-version corruption, released mutation, unreleased LessonProgress creation, aggregate incompleteness, exact source mismatch, exact classification mismatch, invalid finalization, invalid Regrade, invalid analytics counters.

Required concurrency coverage includes two-session write-skew probes for every parent/child aggregate protected by the mandatory locking protocol.

## Historical sealing
Released Lesson/Assessment revision classifications are sealed by released_at.
GenerationItems are sealed by ExamGeneration.generated_at.
AttemptItems and historical Classification Snapshot rows are sealed by Attempt.started_at.
Post-commit historical child append is therefore rejected at DB level for these core historical aggregates.

## No silent downgrade
If an intended DB invariant proves impractical, stop and submit an amendment; do not silently weaken it.

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
At COMMIT generation requires >=1 item and all selected revisions released.
Generation and committed GenerationItems are historical; UPDATE/DELETE blocked.
Post-commit append boundary remains application-controlled.

## Attempt
Exact source XOR via CHECK.
Source/version composite FKs.
At most one Attempt per Generation.
Lifecycle in_progress→submitted|abandoned.
Learner/source/version/started_at immutable.
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
Lock parent for revision/version allocation and pointer switch.
Lock Attempt for finalization.
Lock Response for Regrade numbering.
Serialize PracticeActivity source during Attempt instantiation.
Serialize/safely upsert analytics rebuild key.

## Tests
Required negative families cover lifecycle reversal, cross-version corruption, released mutation, aggregate incompleteness, exact source mismatch, exact classification mismatch, invalid finalization, invalid Regrade, invalid analytics counters.

## Known limitation
Absolute DB-only post-commit append prevention is not claimed for every immutable child collection without artificial finalized markers.

## No silent downgrade
If an intended DB invariant proves impractical, stop and submit an amendment; do not silently weaken it.

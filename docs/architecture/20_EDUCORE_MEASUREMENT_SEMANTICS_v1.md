# EduCore Measurement Semantics v1

Status: FROZEN
Version: 1.0

## Layers
Layer 1 = Raw/Historical Evidence.
Layer 2 = Derived Statements.
Layer 3 = Interpretation.

LessonProgress is Learning Completion, not mastery or Assessment Performance.

## Effective outcome
If RegradeCorrections exist, use corrected_is_correct from greatest correction_number; otherwise original_is_correct.

## Answered/unanswered
Final answered: response_payload non-NULL and original_is_correct BOOLEAN.
Final unanswered: both NULL.
Unanswered contributes no Skill scoring evidence.
A final NULL response does not prove no interaction; no full interaction log exists in v1.

## Single-primary evidence
Exactly one primary Skill.
Eligible answered item contributes single_primary_answered_count.
If effectively correct, contributes single_primary_correct_count.
Accuracy = correct/answered only when denominator >0.
Denominator 0 = undefined/no evidence, not 0%.
Evidence count must accompany accuracy.

## Multi-primary
Correct => Composite Positive Evidence over complete primary Skill set.
Incorrect => Ambiguous Composite Failure Evidence over complete primary Skill set.
Never automatically distribute as individual Skill correctness/failure.

## Supporting
Supporting participation may yield exposure.
If effectively correct may also yield positive evidence.
Incorrect supporting participation yields no individual negative evidence.
No Supporting Accuracy metric.

## Historical classification
Analytics uses AttemptItem historical Primary Topic and historical Skill classifications, never current taxonomy.
Primary Topic is distinct from Skill Home Topic and must not be inferred from it.

## Preservation vs eligibility
Raw historical evidence is preserved for submitted and abandoned attempts.
Preservation != inclusion in every projection.
Eligibility = Evidence × Projection Purpose.
No global is_valid_for_analytics flag.

Submitted != universally representative.
Abandoned evidence is preserved; no abandonment reason is inferred.
No arbitrary completion threshold.

## EvidenceScope
Explicit analytical sampling/configuration identity.
Semantic definition immutable; semantic change creates new identity.
Temporal window is not part of baseline scope identity.

## Temporal boundary
Baseline MaterializedSkillPerformance is Lifetime-only.
Narrower windows require source evidence or future compatible materialization; never infer from Lifetime aggregates.

## Repetition
Same logical AssessmentItem across revisions remains a repetition relationship.
New revision does not automatically restore independence.
Distinct logical items are not automatically repetitions.
Exact repetition policy is DEFERRED and blocks final production longitudinal analytics algorithm, not storage schema.

## MaterializedSkillPerformance
Key LearnerProfile×Skill×EvidenceScope.
Stores eligible analytical counts after scope, representativeness, repetition, and effective-outcome rules:
- single_primary_correct_count
- single_primary_answered_count
- supporting_positive_count
- supporting_exposure_count

0 <= correct <= answered.
0 <= supporting_positive <= supporting_exposure.
Sparse/disposable/rebuildable.
No Composite Evidence projected into individual counters.

## Regrade recovery
Use targeted rebuild from source truth rather than blind +1/-1 arithmetic.

## Topic views
Skill-placement Topic View = stable Skill evidence interpreted through requested CurriculumVersion placement.
Historical Item-topic Performance = AttemptItem historical Primary Topic.
They are distinct.

## Layer 3
Requires metric, scope/set, temporal boundary, sufficiency rule, directional rule.
Order: measure → sufficiency → direction.
Individual Skill sufficiency uses eligible single-primary answered evidence.
Supporting/composite signals do not independently establish individual strong/weak.
Insufficient Evidence != poor performance.

## Student Progress Current-State View
Live contextual projection requiring learner, applicable CurriculumVersion, EvidenceScope/permitted set, temporal boundary, Learning Completion, Assessment Performance, sufficiency, and interpretation rules.
No universal global score.
Coverage denominator must be explicit; eligible Skill population is deferred.
Applicable CurriculumVersion is externally resolved.

## Deferred measurement decisions
Exact repetition policy, scope JSON schema, default temporal boundary, sufficiency thresholds, directional thresholds, eligible Skill population, abandoned-evidence projection policy, cross-item similarity, historical Layer3 rule persistence.

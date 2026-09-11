# EduCore Engineering Review Guide v1

Status: FROZEN
Version: 1.0

## Goal
Review whether frozen EduCore architecture can be implemented correctly, safely, and consistently. Do not reopen decisions merely because a reviewer prefers another style.

## Valid findings
Architecture/domain/measurement contradiction; referential or historical integrity hole; invalid cardinality/lifecycle; concurrency defect; impossible trigger/constraint; PostgreSQL incompatibility; migration/data-loss risk; material performance flaw; implementation-impacting ambiguity.

## Severity
BLOCKER: stop implementation.
MAJOR: substantial correctness/integrity/migration/concurrency/operational risk.
MINOR: non-critical issue.
QUESTION: clarification required.

## Finding template
Finding ID:
Severity:
Document:
Section:
Current Rule:
Issue:
Why It Matters:
Concrete Failure Scenario:
Proposed Resolution:
Migration Impact:
Historical Data Impact:
Testing Impact:

## Mandatory review areas
Architecture: historical truth, identity/revision separation, no duplicate projection truth.
Identity: User vs LearnerProfile.
Curriculum: lifecycle, structural lock, no MAX/latest.
Taxonomy: version-local Topics, stable Skills, Home Topic deferred cardinality.
Learning: Lesson one-version ownership, required LessonRevision Primary Topic, release immutability.
Assessment: unified AssessmentItem, nullable Assessment Primary Topic, >=1 primary Skill, multi-primary allowed.
Practice: prospective configuration, exact revisions, active completeness, history independence.
Exam: rules versioning, deterministic provenance, exact GenerationItems, current-version retirement guard.
Attempt: XOR source, exact GenerationItem provenance, exact source-set equality, exact classification equality, exactly one Response, >=1 primary Skill.
Regrade: immutable corrections, logical numbering, derived effective outcome.
Measurement: single/multi/supporting semantics, no Supporting Accuracy, abandoned preservation, scope context, repetition deferred, Lifetime cache, sufficiency-before-direction.
Schema: verify 35_EDUCORE_PHYSICAL_SCHEMA_CONTRACT_v1.md fully specifies columns, nullability, defaults, candidate keys, composite FKs, delete actions, XOR checks, and requires no conversational context.
PostgreSQL: verify compatibility with 10.23.
Laravel: verify actual generated PostgreSQL DDL rather than assuming helper semantics.
Concurrency: revision numbers, pointer switches, generation, Attempt instantiation/finalization, Regrade, analytics rebuild.
Deferred leakage: no silent decision of DD items.
Performance: require plausible/measured query evidence; no blanket indexing.

## Historical reconstruction test
Must answer from historical truth:
What exact item was shown?
How was it scored originally?
What Topic/Skills classified it then?
Which generation selected it?
Which rules/version/seed generated it?
Was it regraded?
What is effective outcome now?
Can analytics be rebuilt?

## Amendment template
Amendment ID:
Affected Documents:
Affected Sections:
Current Frozen Rule:
Technical Problem:
Proposed Rule:
Alternatives Considered:
Why Current Rule Cannot Stand:
Historical Data Impact:
Migration Impact:
Application Impact:
Analytics Impact:
Backward Compatibility:
Testing Required:
Severity:

## Approval
APPROVED FOR IMPLEMENTATION requires:
- no unresolved BLOCKER;
- MAJOR findings resolved or explicitly accepted;
- Cross-Document Audit pass;
- PostgreSQL compatibility;
- coherent migration sequence;
- no accidental Deferred Decision resolution.

## Drift
If implementation discovers a spec problem:
stop affected checkpoint → document → amendment → approve → update docs → update implementation.

Document approval does not replace actual migration verification and negative probes.

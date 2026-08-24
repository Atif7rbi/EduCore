# EduCore Architecture Pack — Internal Audit Record v1

Status: FROZEN
Version: 1.0

## Final result
Architecture: PASS
Domain Model: PASS
Measurement Semantics: PASS
Schema Design: PASS
DDL Plan: PASS
Integrity Model: PASS
Deferred Decisions: PASS
Migration Ordering: PASS
PostgreSQL Feasibility: PASS
Deferred-Decision Leakage: PASS

Unresolved Internal BLOCKER: 0
Unresolved Internal MAJOR: 0

Status: INTERNALLY APPROVED FOR ENGINEERING REVIEW.
Not yet APPROVED FOR IMPLEMENTATION.

## Amendments
CDA-001: Domain created_at is TIMESTAMPTZ NOT NULL.
CDA-002: Identity implementation normalization acknowledged.
CDA-003: Exact Exam Attempt provenance via composite candidate keys/FKs.
CDA-004: Every committed AttemptItem has >=1 historical primary Skill.
CDA-005: Attempt source-set exactly matches Generation/Practice source at instantiation.
CDA-006: Historical Primary Topic and (Skill,role) set exactly match source released revision.
CDA-007: A TemplateVersion cannot retire while still current published pointer.

## Reconciliations
subjects.name UNIQUE.
lesson_revisions.primary_topic_id NOT NULL.
assessment_item_revisions.primary_topic_id nullable.
Curriculum structural lock does not prohibit revisions of existing logical content or prospective Practice/Exam configuration by itself.
Relational classification exactness is DB-verifiable; Presented/Scoring JSON semantic generation remains application responsibility.

## Next gate
External engineering review using 70_EDUCORE_ENGINEERING_REVIEW_GUIDE_v1.md.
Remaining migrations must not execute until findings are resolved and status is explicitly APPROVED FOR IMPLEMENTATION.


## Engineering Review Pass #1
ER-001 BLOCKER: condensed documentation lacked a complete canonical physical schema contract.

ERA-001 remediation:
35_EDUCORE_PHYSICAL_SCHEMA_CONTRACT_v1.md added as the authoritative physical contract.

ER-001 remains pending until committed and verified by Engineering Review Pass #2.

ER-002 MAJOR resolved:
Attempt finalization is explicitly one atomic transaction with deferred end-of-transaction validation for cross-row final-state invariants.

ER-005 MAJOR resolved:
Practice source-set equality is explicitly a creation-time transactional invariant only; historical Attempts are independent from subsequent PracticeActivity membership changes.


ER-003 MAJOR resolved:
Historical aggregate sealing is DB-backed without new columns.
ExamGeneration.generated_at and Attempt.started_at act as one-way transaction seals.
Core historical child collections reject post-seal INSERT/UPDATE/DELETE.


ER-004 MAJOR resolved at specification level:
User identity drift will be corrected by a required forward normalization migration before 10_curriculum.
Already-applied migration history remains immutable.
Physical closure requires migration + PostgreSQL introspection + regression tests.

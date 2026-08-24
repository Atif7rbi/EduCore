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

ER-001 CLOSED by Engineering Review Pass #2.

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


ER-006 MAJOR resolved:
Project bootstrap is PostgreSQL-first.
SQLite is not a supported domain/integration database.
Implicit migration execution is removed from Composer bootstrap/setup paths.


ER-007 MINOR resolved:
Identity schema regression coverage committed and verified on PostgreSQL:
5 tests passed / 8 assertions.


## Engineering Review Pass #2 Remediation

ER-008 BLOCKER resolved at specification level:
Canonical Physical Schema Contract now states the exact composite FK column mappings for all previously abbreviated relationships.

ER-009 MINOR resolved:
Architecture README reading-order numbering corrected.

ER-010 MINOR resolved:
Internal Audit now records ER-007 closure.


## Engineering Review Pass #2 Final Result

Architecture: PASS
Domain Model: PASS
Measurement Semantics: PASS
Physical Schema Contract: PASS
DDL Plan: PASS
Integrity Model: PASS
Migration Ordering: PASS
PostgreSQL Feasibility: PASS
Deferred-Decision Leakage: PASS
Historical Reconstruction: PASS

Unresolved BLOCKER: 0
Unresolved MAJOR: 0

Architecture Pack status:
READY FOR EXTERNAL ENGINEERING REVIEW.

Not yet APPROVED FOR IMPLEMENTATION.


## External Engineering Review — Claude Pass #1

Reviewed target:
efed277cc634dc16809f2e39713f8e77ea6ae187

Findings:
- BLOCKER-01: phpunit.xml defaulted Feature/Integration tests to SQLite despite PostgreSQL-only policy.
- MAJOR-01: AssessmentItemRevision nullable Primary Topic composite FK shape was abbreviated.
- MINOR-01: reference trigger SQL recommendation; deferred to implementation checkpoint.
- MINOR-02: PostgreSQL 10.23 is EOL; upgrade remains required before long-term production exposure.
- MINOR-03: Attempt finalization ordering requires explicit regression coverage during 70_attempts implementation.
- NIT-01: five-column provenance FK maintainability note.

Remediation:
- PHPUnit test runtime now requires PostgreSQL.
- Local .env.testing targets an isolated PostgreSQL test database and is git-ignored.
- TestCase fails fast when Feature/Integration tests resolve to a non-pgsql driver.
- AssessmentItemRevision Primary Topic composite FK is now stated explicitly.
- PostgreSQL verification completed successfully: 7 tests passed / 10 assertions.

External BLOCKER-01: RESOLVED.
External MAJOR-01: RESOLVED.

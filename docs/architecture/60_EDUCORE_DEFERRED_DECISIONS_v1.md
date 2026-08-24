# EduCore Deferred Decisions v1

Status: FROZEN
Version: 1.0

A deferred decision is intentionally unresolved, not a documentation gap. No engineer may silently choose a default.

## DD-001 Skill Home Topic cardinality
Final 0..1 vs 0..N semantics deferred. Physical schema permits 0..N. Reopen when canonical Home Topic behavior is required.

## DD-002 Exact repetition policy
Same logical AssessmentItem across revisions remains repetition-related. Exact first/latest/best/weighted/etc. policy deferred.
Does not block schema/history. Blocks final production longitudinal Skill materialization.

## DD-003 Practice behavior after AssessmentItem retirement
Historical Attempts unaffected. Prospective Activity behavior deferred until retirement workflow.

## DD-004 Default consumer temporal boundary
Materialized cache is Lifetime-only. Default Student Progress view window deferred.

## DD-005 Evidence sufficiency thresholds
No numeric threshold frozen. Blocks final Layer3 labels only.

## DD-006 Directional interpretation thresholds
Strong/Developing/Needs Attention thresholds/labels deferred.

## DD-007 Eligible Skill population for coverage
Coverage denominator deferred; must be explicit.

## DD-008 Learner→applicable CurriculumVersion assignment
Enrollment/cohort/program/etc. model deferred. Never infer MAX/latest.

## DD-009 Full role/permission model
User actor identity and LearnerProfile learner identity are frozen; complete Student/Teacher/Admin authorization model deferred.

## DD-010 Teacher supervision domain
Classroom/cohort/enrollment/assignment/supervision entities deferred.

## DD-011 Privacy deletion/anonymization
Historical RESTRICT remains. Explicit privacy workflow deferred.

## DD-012 Multi-tenancy
No tenant_id in v1. Multi-organization model deferred.

## DD-013 Commercial/entitlements
Plans/subscriptions/trials/access model deferred.

## DD-014 Content delivery/protection details
High-level private/signed/controlled delivery frozen; provider/viewer/watermark/expiry details deferred.

## DD-015 Assessment item type set
Exact closed item_type set deferred until first real content implementation.

## DD-016 Exam rules JSON schema
Rules declarative and versioned; exact schema deferred until generator implementation.

## DD-017 Lesson content JSON schema
JSONB object + content_schema_version frozen; block schema deferred until authoring/rendering.

## DD-018 Assessment content/scoring/response JSON schemas
Presentation/scoring separation frozen; exact structures deferred per item type.

## DD-019 Generator version format
Field required; canonical format deferred.

## DD-020 Seed representation
Seed stored as TEXT; canonical format deferred.

## DD-021 PracticeActivity reactivation
Whether archived→active is official workflow deferred.

## DD-022 ExamTemplate active/archive direction
Whether archived→active allowed deferred.

## DD-023 Historical Layer3 rule persistence
Persist exact past interpretation rules only if future product requires historical displayed-label reconstruction.

## DD-024 Temporal analytics materialization
Non-Lifetime caches deferred until workload justifies.

## DD-025 Topic analytics materialization
Topic materialization deferred until measured need.

## DD-026 Cross-item semantic similarity
Distinct logical items not automatically repetitions; item-family/near-duplicate model deferred.

## DD-027 Adaptive learning
Post-v1. Must preserve exact provenance for selected assessment content.

## DD-028 AI Tutor
Post-v1. Must not rewrite authoritative curriculum/scoring/classification/learner answers.

## DD-029 Generic actor audit/event model
No blanket audit table. Reopen on formal actor-audit requirement.

## DD-030 User profile/privacy identity fields
Phone/school/region/guardian/etc. deferred until real requirement.

## DD-031 Identity type/timestamp normalization
Implementation follow-up. Already-applied Laravel Auth schema may need forward normalization before production baseline.

## DD-032 Database upgrade
Current PostgreSQL 10.23 accepted for development. Upgrade to supported release recommended before long-term production.

## Rule
When a deferred decision becomes necessary:
STOP → identify DD ID → propose options → resolve → amend specs → continue.

# EduCore Architecture Pack

Status: FROZEN
Version: 1.0
Project: EduCore

## Reading order
1. 00_EDUCORE_ARCHITECTURE_v1.md
2. 10_EDUCORE_DOMAIN_MODEL_v1.md
3. 20_EDUCORE_MEASUREMENT_SEMANTICS_v1.md
4. 30_EDUCORE_SCHEMA_DESIGN_v1.md
5. 35_EDUCORE_PHYSICAL_SCHEMA_CONTRACT_v1.md
6. 40_EDUCORE_DDL_PLAN_v1.md
7. 50_EDUCORE_INTEGRITY_RULES_v1.md
8. 60_EDUCORE_DEFERRED_DECISIONS_v1.md
9. 70_EDUCORE_ENGINEERING_REVIEW_GUIDE_v1.md
10. 90_INTERNAL_AUDIT_RECORD_v1.md

## Authority
Architecture → Domain Model → Measurement Semantics → Schema Design → Physical Schema Contract → DDL Plan → Integrity Rules → Deferred Decisions → Engineering Review Guide.

## Status vocabulary
FROZEN = approved current decision.
IMPLEMENTED = physically applied.
DEFERRED = intentionally unresolved.
SUPERSEDED = replaced by approved later decision.

## Current state
Documentation Drafting: COMPLETE
Cross-DDL Audit: PASS
Cross-Document Final Audit: PASS
External Engineering Review: PENDING
Implementation Approval: PENDING

00_identity: BASELINE IMPLEMENTED + VERIFIED; forward normalization REQUIRED before 10_curriculum.
10_curriculum through 95_indexes: READY, not yet approved for execution.

EduCore v1 contains 30 domain tables. Laravel infrastructure tables are separate.

## Core principles
- Explicit historical truth.
- Logical identity separated from revision/version state.
- No implicit MAX/latest current-state resolution.
- Exact revision/version provenance.
- Published/released history protected.
- DB protects structural truth; application owns semantic algorithms.
- Derived analytics are recomputable.
- No speculative tenant_id.
- No blanket soft deletes or blanket JSONB GIN indexes.

## Amendment register
CDA-001 created_at is TIMESTAMPTZ NOT NULL.
CDA-002 Identity implementation normalization acknowledged.
CDA-003 Exact Exam Attempt provenance.
CDA-004 Historical primary Skill completeness.
CDA-005 Exact Attempt source-set completeness.
CDA-006 Exact Classification Snapshot validation.
CDA-007 Current ExamTemplateVersion retirement guard.

Final reconciliation:
- subjects.name UNIQUE.
- lesson_revisions.primary_topic_id NOT NULL.
- assessment_item_revisions.primary_topic_id nullable.

This pack is internally approved for engineering review, not yet approved for implementation.

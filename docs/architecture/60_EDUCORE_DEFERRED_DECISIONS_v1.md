# EduCore Deferred Decisions v1

Status: FROZEN
Version: 1.0

A deferred decision is intentionally unresolved, not a documentation gap. No engineer may silently choose a default.

CDA-009 is authoritative where it explicitly supersedes DD-008, DD-009, and DD-010. Those entries are reconciled below; broader deferred scope remains deferred.

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

## DD-008 Learner→applicable CurriculumVersion assignment — RESOLVED IN PART BY CDA-009
For v1 current learner access, the relationship is resolved through LearnerProfile → StudentEnrollment → TeacherSubjectAssignment → Teacher-owned Curriculum, together with the complete Phase F authorization conjunction defined by CDA-009.

An active StudentEnrollment alone is not sufficient evidence of effective learner access.

Cohort, classroom, program, organization, and other applicability models remain deferred. Never infer MAX/latest.

## DD-009 Full role/permission model — RESOLVED FOR v1

Resolved before Application Phase A4.

### User role

Each User has exactly one effective v1 role:

- `student`
- `teacher`
- `admin`

Physical representation:

- `users.role TEXT NOT NULL`
- database CHECK restricts the value to the three roles above.

No generic RBAC tables are introduced in v1:

- no `roles`
- no `permissions`
- no `user_roles`
- no `role_permissions`

Single-role v1 is intentional. If a later real product requirement needs simultaneous roles or finer capabilities, the authorization abstraction must permit the storage model to evolve without rewriting controllers.

### Learner identity

User actor identity and LearnerProfile learner identity remain distinct.

CDA-009 supersedes the earlier optional-participation rule for Student users.

For a product-valid v1 Student:

`User(role = student) 1 → exactly 1 LearnerProfile`

A Student without exactly one LearnerProfile is not a valid steady-state product condition. Such malformed, transitional, or deliberately constructed test states must fail closed and must not gain learner capability.

Before Phase G relies on Student total participation, the exact-data preflight defined by CDA-009 is mandatory. It may use only exact User/LearnerProfile keys and must not perform implicit creation, merging, reassignment, or attribute-based identity matching.

Teacher or Admin users likewise do not become learners merely because they are authenticated.

### Student boundary

Student may use learner-facing capabilities authorized to their LearnerProfile, including owned Attempts and Responses.

Student may not perform:

- management mutations
- trusted scoring
- regrade operations

Learner ownership remains mandatory.

### Teacher boundary

Teacher is a recognized authenticated actor role in v1.

Teacher receives no implicit Admin-equivalent management authority.

CDA-009 resolves the TeacherSubjectAssignment and StudentEnrollment subset of Teacher supervision for v1. The owning active Teacher retains routine enrollment accept/decline authority and Teacher-owned Curriculum authoring authority within the separately frozen ownership model.

Broader classroom, cohort, organization, and supervision capabilities remain deferred.

### Admin boundary

Admin may perform only operations explicitly assigned to the Admin boundary.

CDA-009 supersedes the earlier generic grant of Admin curriculum/content lifecycle authority for Teacher-owned Curriculum.

For Teacher-owned Curriculum and its educational-content descendants:

- Admin read/inspection is allowed;
- Admin authoring is not allowed;
- Admin editing is not allowed;
- Admin publishing/lifecycle mutation is not allowed;
- Admin may not substitute for the owning Teacher.

Historical ownerless Curriculum may retain separately approved compatibility behavior, but that compatibility is not a normal path for new Phase G authoring.

Admin retains the already-approved operational authority over TeacherSubjectAssignment lifecycle and StudentEnrollment deactivation. Exceptional Admin relationship-correction authority is preserved by CDA-009, while its dedicated corrective UI/API remains outside Phase G v1.

Regrade and other management operations remain governed by their separately approved boundaries.

Adding `users.role` alone does not activate capabilities.

The management boundary remains fail-closed and must enforce active-status and role authorization.

### User status enforcement

Canonical User status remains:

- `active`
- `disabled`

`disabled` must be enforced on every authenticated request, not only during login.

A session created while a User was active must not continue granting authenticated API access after that User becomes disabled.

### Authentication v1

Laravel `web` / session authentication is the v1 authentication mechanism.

Sanctum or other token authentication is not introduced until an actual external/mobile API requirement exists.

End-to-end session behavior must be verified with a real login → session/cookie → authenticated request integration test; `actingAs()` alone is not sufficient evidence of the browser authentication contract.

### Authorization consumption rule

Role checks must use one centralized authorization abstraction.

Controllers and application services must not accumulate scattered raw checks such as:

`$user->role === 'admin'`

This keeps a future transition to capability-based or multi-role authorization localized.

### Resolution effect

DD-009 is resolved sufficiently for v1 implementation as amended by CDA-009.

Student total participation in LearnerProfile, immutable single-role behavior, Teacher provisioning boundaries, TeacherSubjectAssignment, StudentEnrollment authority, and the Teacher-owned Curriculum Admin read-only boundary are governed by CDA-009.

## DD-010 Teacher supervision domain — RESOLVED IN PART BY CDA-009
TeacherSubjectAssignment and StudentEnrollment are resolved v1 domain relationships and are no longer deferred.

Classroom, cohort, organization, broader supervision, and other relationships not explicitly resolved by CDA-009 remain deferred.

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

## DD-031 Identity type/timestamp normalization — RESOLVED
Resolved by Engineering Review ER-004.

A forward migration is REQUIRED before 10_curriculum.
Canonical User domain types/timestamps are defined in 35_EDUCORE_PHYSICAL_SCHEMA_CONTRACT_v1.md.
Already-applied migration history must not be rewritten.

## DD-032 Database upgrade
Current PostgreSQL 10.23 accepted for development. Upgrade to supported release recommended before long-term production.

## Rule
When a deferred decision becomes necessary:
STOP → identify DD ID → propose options → resolve → amend specs → continue.

## DD-033 GradeLevel classification

CDA-008 establishes EducationStage but intentionally defers
GradeLevel.

The reserved conceptual hierarchy is:

EducationStage → GradeLevel.

Exact grade identity, naming, localization, and education-system
semantics require a later approved amendment.

## DD-034 University academic hierarchy

University education is not modeled as another school EducationStage
by CDA-008.

Institution, College, Program/Major, Academic Level, Course, and
related university structure remain deferred.

A future university Subject may use the canonical Subject catalog when
semantically appropriate, but university hierarchy requires a separate
architecture decision.

## DD-035 Subject catalog administration

CDA-008 defines Subject as the canonical catalog entity and provides
the initial six canonical Subjects.

Product-facing arbitrary custom Subject creation, catalog-definition
CRUD, automatic legacy canonicalization, and organization-specific
Subject activation remain deferred.

Organization-specific activation also depends on the separately
deferred multi-organization model in DD-012.

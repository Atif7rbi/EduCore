# CDA-008 — Canonical Subjects and Education Stages

Status: FROZEN

Specification reconciliation: COMPLETE

Project: EduCore

Scope: Curriculum classification and administrator-facing Subject
management.

This amendment changes the frozen EduCore v1 architecture only where
explicitly stated below.

Implementation is not authorized by this document alone.

## 1. Problem

EduCore currently models:

Subject → Curriculum → CurriculumVersion.

The original v1 product workflow permits Subject names to be managed
as free text.

The product now requires:

- a controlled initial set of canonical academic Subjects;
- stable visual identity for canonical Subjects;
- classification of Curricula by school EducationStage;
- future filtering by Subject and EducationStage;
- future expansion to additional school or university Subjects without
  encoding the initial catalog as a closed database enum.

The solution must preserve existing identities, historical truth,
PostgreSQL integrity, the single-data-space architecture, and future
extensibility.

## 2. Tenancy boundary

EduCore v1 remains a single data space.

CDA-008 introduces no tenant_id, organization_id, or
organization-level Subject ownership.

A future organization-specific Subject activation model requires a
separate approved architecture amendment.

## 3. Subject is the canonical catalog entity

No separate SubjectCatalogItem entity is introduced in v1.

Subject itself is the canonical academic-subject identity and catalog
entity.

The authoritative relationship remains:

Subject 1 → N Curriculum.

Curriculum 1 → N CurriculumVersion.

This avoids an unnecessary one-to-one catalog wrapper in the current
single-data-space architecture.

## 4. Canonical Subject identity

Subject gains canonical catalog metadata conceptually equivalent to:

- code
- icon_key
- thumbnail_key
- sort_order
- status

code is the stable machine identity of a canonical Subject.

Canonical Subjects have a non-null code.

Legacy or non-canonical Subjects may have code = NULL.

code is unique when present.

Multiple legacy rows with code = NULL remain legal.

code is not a localized display label and is not derived from a
mutable Subject name.

Subject.code is immutable after INSERT through ordinary runtime
mutation.

This prohibition includes NULL-to-value, value-to-NULL, and
value-to-value changes.

A legacy Subject may receive canonical classification only through an
explicitly reviewed remediation operation.

name remains the human-readable Subject name.

Existing Subject IDs and the existing subjects.name field are
preserved.

For a canonical Subject with non-null code, name is platform-owned
catalog metadata and is not mutable through ordinary Subject edit
operations.

Legacy/non-canonical Subjects with code = NULL may retain compatibility
name-edit behavior.

Normal v1 administrator UX does not offer arbitrary creation or
renaming of canonical Subjects.

icon_key and thumbnail_key are stable asset keys, not storage-provider
or CDN URLs.

Visual identity is sourced from Subject and reused by downstream
product surfaces rather than copied onto Curriculum, Lesson,
AssessmentItem, PracticeActivity, ExamTemplate, or historical records.

Legacy Subjects are not required to have canonical visual metadata.

sort_order provides deterministic product presentation ordering.

Subject status has the canonical values active and inactive.

Inactive means unavailable for normal new-content selection.

Inactive does not delete, rewrite, or invalidate existing historical
relationships.

## 5. Initial canonical Subject catalog

EduCore v1 initially provides exactly these six canonical Subjects:

- mathematics — الرياضيات
- physics — الفيزياء
- biology — الأحياء
- chemistry — الكيمياء
- english_language — اللغة الإنجليزية
- arabic_language — اللغة العربية

This is initial reference data, not a closed enum.

Future approved Subjects may be added without changing the schema
solely to extend the catalog.

Possible later additions include specialized school or university
Subjects whose exact taxonomy will be decided separately.

## 6. Existing and legacy Subjects

Existing Subject rows retain their IDs.

Existing Curriculum rows retain their subject_id.

CDA-008 explicitly prohibits automatic name-based canonicalization.

The system must not automatically convert a legacy Subject into a
canonical Subject merely because normalized names appear equal.

Legacy Subjects remain valid with code = NULL unless an explicit,
reviewed remediation operation classifies them later.

If installation of a canonical Subject conflicts with an existing
unique Subject name or code, migration must not silently rename,
merge, delete, or infer equivalence.

A collision is a stop condition requiring explicit remediation.

## 7. EducationStage

CDA-008 introduces the canonical reference entity EducationStage.

Conceptual fields are:

- id
- code
- name
- sort_order
- status
- created_at
- updated_at

EducationStage.code is stable and unique.

EducationStage.code is immutable after INSERT through ordinary runtime
mutation.

EducationStage status has the canonical values active and inactive.

The initial EducationStage v1 reference data is:

- primary — المرحلة الابتدائية
- middle — المرحلة المتوسطة
- secondary — المرحلة الثانوية

This is extensible reference data, not a closed enum.

## 8. Curriculum stage classification

Curriculum gains nullable education_stage_id.

education_stage_id references EducationStage through a restrictive
foreign key.

The field is nullable because not every present or future Curriculum
must belong to the school EducationStage hierarchy.

Examples include Qudrat-oriented Curricula, specialized independent
programs, and possible future university content.

EducationStage is part of Curriculum classification identity.

The EducationStage classification selected when a new Curriculum is
created is immutable through ordinary edit operations.

Ordinary editing must not perform:

- NULL → EducationStage;
- EducationStage → NULL;
- EducationStage A → EducationStage B.

A later correction or legacy classification requires a specifically
authorized remediation workflow with historical-impact review.

This prevents current-state edits from silently changing the inferred
stage meaning of existing CurriculumVersions and downstream historical
learning and assessment records.

Existing Curricula migrate with education_stage_id = NULL.

No automatic classification from Curriculum or Subject names is
permitted.

## 9. Derived downstream classification

Version-bound educational content derives Subject and EducationStage
through its authoritative relationships:

domain object
→ CurriculumVersion
→ Curriculum
→ Subject / EducationStage.

Lesson, AssessmentItem, PracticeActivity, ExamTemplate, and downstream
records must not receive duplicate authoritative subject_id or
education_stage_id solely for filtering convenience when those values
are already determined by authoritative relationships.

Any future denormalized search or index projection must be explicitly
non-authoritative and recomputable.

## 10. Curriculum counts

Per-Subject and per-EducationStage Curriculum counts are derived data.

CDA-008 does not authorize authoritative fields such as
subjects.curricula_count or education_stages.curricula_count.

Counts are computed from Curriculum relationships or from explicitly
disposable projections if justified later.

## 11. Administrator UX contract

Normal EduCore v1 Subject management presents canonical Subjects
already available in the system.

The administrator does not manually type the names of the six initial
canonical Subjects.

The Subject surface may display canonical name, canonical icon,
canonical thumbnail, number of Curricula, and Curriculum counts by
EducationStage.

Selecting a Subject opens its Curricula.

Creating a Curriculum may request an optional EducationStage.

The existing arbitrary Subject creation UX is removed from the normal
v1 product workflow.

Existing compatibility APIs are not automatically removed by this
architecture amendment.

API deprecation or removal requires explicit implementation scope and
regression coverage.

## 12. Canonical Subject read contract

A product-facing canonical Subject read model may expose:

- id
- code
- name
- icon_key
- thumbnail_key
- sort_order
- status
- curricula_count
- stage_counts

curricula_count and stage_counts are derived values.

Legacy Subjects with code = NULL are excluded from the normal
canonical catalog listing unless a specifically named compatibility or
administrative workflow requests them.

## 13. GradeLevel

GradeLevel classification is intentionally deferred.

The architecture reserves the conceptual hierarchy:

EducationStage → GradeLevel.

CDA-008 does not create GradeLevel.

Exact grade naming, localization, and education-system semantics
require a later amendment.

## 14. University classification

University education must not be forced into the school EducationStage
model.

Future university content may require concepts such as Institution,
College, Program or Major, Academic Level, and Course.

Those concepts are outside CDA-008.

A university Subject may be added to the canonical Subject catalog if
semantically appropriate.

University academic structure requires a separate architecture
decision.

## 15. Historical-truth impact

CDA-008 does not replace existing Subject or Curriculum identities.

No existing Subject ID or Curriculum ID is replaced.

No existing Curriculum is moved automatically.

No historical Attempt, Practice, Exam, Lesson, Assessment, or
CurriculumVersion provenance is rewritten.

Curriculum EducationStage immutability prevents later ordinary edits
from retrospectively changing derived stage classification of
version-bound historical records.

## 16. PostgreSQL integrity requirements

Physical schema reconciliation must provide at minimum:

- canonical Subject code uniqueness;
- EducationStage primary-key integrity;
- EducationStage code uniqueness;
- restrictive Curriculum → EducationStage foreign-key integrity;
- legal active/inactive lifecycle values;
- deterministic sort-order constraints;
- compatibility with legacy Subject and Curriculum rows.

PostgreSQL remains authoritative for structural integrity.

SQLite remains unsupported for domain-schema verification.

## 17. Migration requirements

Implementation must use forward migrations.

Implementation must not:

- drop and recreate subjects;
- drop and recreate curricula;
- rewrite Subject IDs;
- rewrite Curriculum IDs;
- move existing Curricula;
- infer canonical Subject mappings from names;
- infer EducationStage mappings from names.

Before production migration, a read-only preflight must verify that the
six canonical Subject names and codes can be installed without
ambiguous collision.

A collision is a stop condition, not permission for automatic repair.

Existing Curricula must initially retain education_stage_id = NULL.

Canonical reference identities used by application logic must resolve
through stable unique codes rather than environment-specific generated
identifiers.

## 18. Deferred decisions

CDA-008 explicitly defers:

- organization or tenant Subject activation;
- arbitrary product-facing custom Subject creation;
- GradeLevel;
- university hierarchy;
- localization tables beyond current names;
- remote or CDN asset management;
- administrator CRUD for canonical catalog definition;
- automatic legacy canonicalization;
- automatic legacy EducationStage classification;
- denormalized search projections.

## 19. Supersession and reconciliation

CDA-008 amends only frozen v1 decisions that imply unrestricted
free-text product-facing Subject configuration or that provide no
EducationStage classification for Curriculum.

All unrelated architecture, historical-truth, measurement,
assessment, attempt, publication, and PostgreSQL integrity decisions
remain unchanged.

Required specification reconciliation includes at least:

- 00_EDUCORE_ARCHITECTURE_v1.md
- 10_EDUCORE_DOMAIN_MODEL_v1.md
- 30_EDUCORE_SCHEMA_DESIGN_v1.md
- 35_EDUCORE_PHYSICAL_SCHEMA_CONTRACT_v1.md
- 40_EDUCORE_DDL_PLAN_v1.md
- 50_EDUCORE_INTEGRITY_RULES_v1.md
- 60_EDUCORE_DEFERRED_DECISIONS_v1.md
- README.md

Specification reconciliation for CDA-008 is complete.

Implementation may proceed only through the separately gated forward
migration, test-database verification, and reviewed production
preflight defined by this amendment.

## 20. Frozen decision summary

Subject is the canonical academic-subject catalog entity.

The initial canonical Subjects are Mathematics, Physics, Biology,
Chemistry, English Language, and Arabic Language.

Subject.code is stable canonical machine identity and is nullable for
legacy or non-canonical rows.

EducationStage is an independent extensible reference identity.

The initial EducationStages are Primary, Middle, and Secondary.

Curriculum.education_stage_id is nullable and uses a restrictive
foreign key.

Curriculum EducationStage classification is immutable through ordinary
editing, including NULL-to-value assignment after creation.

Downstream Subject and EducationStage classification is derived through
CurriculumVersion and Curriculum rather than duplicated as
authoritative convenience fields.

Existing data is preserved.

Automatic name-based mapping is prohibited.

No tenant or organization model is introduced.

GradeLevel and university hierarchy remain deferred.

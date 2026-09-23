# CDA-009 — Teacher / Student Access Model v1 Reconciliation

Status: FROZEN
Version: 1.0
Project: EduCore
Architecture base: `916447d7719f7944e5d2c7e5c551fb09134f0fa6`

## Purpose

CDA-009 reconciles the original EduCore Architecture Pack with the later approved Teacher / Student Access Model v1, Phase E Curriculum Ownership v1, and Phase F Transactional Learner Authorization v1.

This amendment resolves the architecture contradictions identified during the Phase G Admin Console v1 architecture review.

This amendment is an architecture decision only. It does not authorize Product Phase G implementation, deployment, or data mutation.

## Authority and precedence

CDA-009 is authoritative for the clauses it explicitly supersedes below.

When a clause in a previously FROZEN document conflicts with a rule explicitly superseded by CDA-009, CDA-009 controls.

All clauses not explicitly superseded remain governed by the existing Architecture Pack and prior approved amendments.

CDA-009 explicitly supersedes or narrows:

1. `00_EDUCORE_ARCHITECTURE_v1.md` — the Identity statement `User 1 → 0..1 LearnerProfile` for a User whose role is `student`.
2. `60_EDUCORE_DEFERRED_DECISIONS_v1.md` DD-008 — only to the extent that v1 current learner access is now resolved through TeacherSubjectAssignment, StudentEnrollment, Curriculum ownership, and Phase F authorization. Cohort/program/other applicability models remain deferred.
3. DD-009 Learner identity — the allowance for a product-valid `student` User to exist without exactly one LearnerProfile.
4. DD-009 Admin boundary — any prior grant of Admin authoring/lifecycle authority over Teacher-owned Curriculum or its descendants.
5. DD-009 Resolution effect and DD-010 — only to the extent that the TeacherSubjectAssignment and StudentEnrollment subset of Teacher supervision is now resolved. Classroom, cohort, organization, and broader supervision concepts remain deferred.

## 1. User role and learner identity

The v1 User roles remain exactly:

- `student`
- `teacher`
- `admin`

Roles are immutable after provisioning through normal runtime behavior.

User actor identity and LearnerProfile educational identity remain distinct UUID identities.

For a product-valid v1 Student:

`User(role = student) 1 → exactly 1 LearnerProfile`

A Student User without a LearnerProfile is not a valid steady-state product condition.

Existing fail-closed authorization behavior for a missing LearnerProfile remains required so malformed, transitional, or deliberately constructed test states do not gain learner capability.

Teacher and Admin users do not gain learner capability merely by authentication or role.

## 2. Mandatory Student/LearnerProfile preflight

Before Phase G implementation assumes Student total participation, the exact target data set must be preflighted.

The preflight must identify every `users.id` where:

- `users.role = 'student'`; and
- there is not exactly one LearnerProfile whose `learner_profiles.user_id = users.id`.

The preflight must use exact keys only.

It must not infer identity from:

- name;
- email similarity;
- display values;
- ordering;
- latest-row selection;
- any other attribute matching.

If any violating row exists:

`STOP → report exact violating User IDs/count → approve explicit remediation → remediate → rerun preflight`

No implicit LearnerProfile creation, merge, deletion, or reassignment is permitted.

Tests may intentionally construct a Student without a LearnerProfile to prove fail-closed behavior; that does not redefine the valid production model.

## 3. TeacherSubjectAssignment

TeacherSubjectAssignment is the authoritative v1 Teacher ↔ Subject relationship.

Canonical relationship:

`active TEACHER User → TeacherSubjectAssignment → canonical Subject`

Lifecycle:

- `active`
- `inactive`

Admin may:

- establish an assignment;
- deactivate an assignment;
- reactivate an assignment.

Normal assignment establishment/reactivation requires:

- active Admin actor;
- active User whose immutable role is `teacher`;
- active canonical Subject;
- authoritative operation replay/transition semantics;
- the established PostgreSQL lock order and integrity rules.

Normal product flows must not create canonical Subject identity or convert another User role into Teacher.

## 4. StudentEnrollment

StudentEnrollment is the authoritative v1 learner relationship to a TeacherSubjectAssignment.

Canonical relationship:

`LearnerProfile → StudentEnrollment → TeacherSubjectAssignment → Teacher + Subject`

Lifecycle states remain:

- `pending`
- `active`
- `inactive`

Routine authority remains:

- active Student owner requests or rejoins through the supported student lifecycle;
- owning active Teacher accepts or declines;
- owning active Teacher or active Admin may deactivate where allowed by the frozen lifecycle.

Routine Admin UI/API must not accept or decline on behalf of the Teacher.

### Exceptional Admin correction authority

The previously approved governance authority is preserved.

An Admin may exceptionally correct a wrong Student ↔ TeacherSubjectAssignment relationship by:

1. deactivating the incorrect relationship; and
2. establishing or reactivating the correct relationship under a dedicated corrective/governance operation.

This is not the routine enrollment lifecycle and must not reuse Teacher acceptance/decline semantics.

The dedicated Admin correction UI/API is deferred outside Phase G v1.

Its absence from the Phase G product surface does not supersede or revoke the architectural correction authority.

No generic Admin enrollment creation endpoint is authorized by Phase G.

## 5. Curriculum ownership and Admin authority

Teacher-owned Curriculum is owned by exactly one TeacherSubjectAssignment through:

`curricula.teacher_subject_assignment_id`

For Teacher-owned Curriculum and its educational-content descendants:

- Admin read/inspection: allowed;
- Admin authoring: not allowed;
- Admin editing: not allowed;
- Admin publishing/lifecycle mutation: not allowed;
- Admin substitution for the owning Teacher: not allowed.

Backend authorization remains authoritative even when the frontend removes or suppresses write affordances.

Historical Curriculum with:

`teacher_subject_assignment_id = NULL`

is explicitly classified as:

`legacy_ownerless`

Legacy-ownerless compatibility exists only to preserve historical truth and previously approved compatibility. It must not become a normal path for new Curriculum creation or Phase G Admin authoring.

Teacher authoring belongs to Product Phase H.

## 6. Phase F current learner authorization

Current learner access is not inferred from Curriculum publication or StudentEnrollment status alone.

For a current learner-authorized Curriculum, the effective Phase F grant requires the complete conjunction:

1. exact authenticated User owns the locked LearnerProfile;
2. User role is `student`;
3. User status is `active`;
4. StudentEnrollment belongs to that LearnerProfile;
5. StudentEnrollment status is `active`;
6. TeacherSubjectAssignment status is `active`;
7. StudentEnrollment.teacher_subject_assignment_id equals the Curriculum's teacher_subject_assignment_id;
8. the requested current Curriculum/CurriculumVersion/resource satisfies the applicable Phase F current-resource rules.

Authoritative mutation paths must revalidate the grant transactionally under the established PostgreSQL lock protocol.

Historical finalized Attempt reads retain the separately approved Phase F historical-read boundary.

## 7. Teacher provisioning

Teacher provisioning must be a dedicated operation, not generic User-role CRUD.

Canonical operation:

`ProvisionTeacher`

The provisioning transaction must:

1. lock/revalidate the authenticated actor as an active Admin;
2. normalize the supplied email identity before use;
3. reject if the case-insensitive email identity already belongs to any User;
4. create exactly one User with immutable `role = teacher`;
5. create the User with explicit initial `status = disabled`;
6. persist only a cryptographically strong, generated bootstrap credential hash that is never displayed, returned, emailed, logged, or shared as a usable password;
7. commit atomically or create no Teacher User.

Application-level identity checks are advisory for UX. PostgreSQL case-insensitive uniqueness remains final authority:

`UNIQUE (LOWER(users.email))`

No existing User may be converted to Teacher.

No shared/default password is permitted.

### Credential establishment and activation

A newly provisioned Teacher remains disabled and therefore cannot authenticate into protected product capabilities.

After provisioning, the system may issue or resend a single-use, expiring credential-establishment/password-reset token using the approved password broker/delivery mechanism.

Delivery failure must fail closed with respect to access: the Teacher account remains disabled.

A valid setup token plus an acceptable new password is required before activation.

Credential establishment must atomically:

- validate the single-use token;
- replace the bootstrap password hash with the Teacher-chosen password hash;
- set `status = active`;
- rotate authentication/session material as required;
- invalidate existing sessions if any.

Activation must never occur merely because an invitation was generated or sent.

## 8. Canonical Student resource identity

For Phase G Admin Student resources, the canonical route identity is the User UUID.

Use:

`/api/admin/students/{studentUserId}`

where:

`studentUserId = users.id`

Do not use an ambiguous `studentId` that could mean either User or LearnerProfile.

Every Student Admin read model must expose both:

- `user_id`
- `learner_profile_id`

These identifiers are not interchangeable.

StudentEnrollment provenance and filtering must use exact:

`student_enrollments.learner_profile_id`

No enrollment lookup may substitute `users.id`, name, email, or attribute matching for `learner_profile_id`.

## 9. Admin Curriculum read provenance

Admin Curriculum/content inspection must expose ownership provenance sufficient to distinguish Teacher-owned from historical ownerless content.

For each Curriculum read model, include or make directly resolvable:

- `teacher_subject_assignment_id`;
- `ownership_kind` = `teacher_owned` or `legacy_ownerless`;
- owning `teacher_user_id` when Teacher-owned;
- Teacher display identity required by the Admin UI;
- TeacherSubjectAssignment status when Teacher-owned;
- canonical Subject identity.

If `teacher_subject_assignment_id IS NULL`, the record must be classified explicitly as `legacy_ownerless`.

It must not be presented as Teacher-owned or as evidence of a current Teacher assignment.

## 10. Admin Dashboard metric truth

Operational metrics must state their semantics explicitly.

The following are raw lifecycle-state counts unless their names explicitly say otherwise:

- active TeacherSubjectAssignments;
- pending StudentEnrollments;
- active StudentEnrollments;
- inactive StudentEnrollments.

A raw `active` enrollment count is not an effective learner-access count.

Teacher-owned Curriculum metrics must require:

`curricula.teacher_subject_assignment_id IS NOT NULL`

Teacher-owned and legacy-ownerless content must not be silently conflated.

If published CurriculumVersion metrics are shown, the read model must either:

- publish separate Teacher-owned and legacy-ownerless counts; or
- name and document the exact population included.

Recommended explicit metrics are:

- `teacher_owned_curricula`;
- `published_teacher_owned_curriculum_versions`;
- `legacy_ownerless_curricula`;
- `published_legacy_ownerless_curriculum_versions`.

Phase G v1 does not require an effective-access dashboard metric.

If a future metric claims effective learner access, it must implement the complete Phase F authorization conjunction rather than derive access from a single lifecycle status.

## 11. Phase G / Phase H boundary

Phase G Admin Console v1 is an operational administration product.

Phase G may expose:

- Teacher provisioning;
- Teacher read/detail;
- Student read/detail;
- TeacherSubjectAssignment operational lifecycle;
- StudentEnrollment operational reads and already-authorized routine deactivation;
- read-only inspection of Teacher-owned Curriculum/content;
- operational dashboard metrics with explicit provenance/semantics.

Phase G must not introduce:

- Teacher authoring;
- generic role mutation;
- canonical Subject creation/mutation;
- Admin impersonation;
- routine Admin enrollment acceptance/decline;
- a generic Admin enrollment-creation workflow;
- learner authorization semantics weaker than Phase F.

Teacher Curriculum/content authoring remains Product Phase H scope.

## 12. Reconciliation effect

With CDA-009 applied:

- Student total participation in LearnerProfile is resolved for v1;
- the exact-data preflight is mandatory before Phase G relies on that invariant;
- TeacherSubjectAssignment is no longer deferred;
- StudentEnrollment is no longer deferred;
- the routine Student/Teacher enrollment authority is explicit;
- exceptional Admin relationship-correction authority is preserved but its dedicated UI/API is deferred outside Phase G;
- Teacher-owned Curriculum is read-only to Admin;
- Phase F current-access semantics are authoritative;
- Teacher provisioning has an explicit fail-closed credential lifecycle;
- Admin Student route identity is unambiguous;
- Admin Curriculum provenance and dashboard metric semantics are explicit.

All broader classroom, cohort, organization, commercial, entitlement, and multi-tenancy questions remain governed by their existing deferred decisions.

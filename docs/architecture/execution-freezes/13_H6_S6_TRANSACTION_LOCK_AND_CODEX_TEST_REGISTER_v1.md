# EduCore — H6-S6 Transactional Authorization, PostgreSQL Lock Order & Codex Test Register v1

**Review:** S6-R004 source audit and targeted execution-test handoff  
**Date:** 2026-10-11  
**Evidence SHA:** 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d  
**Status:** SOURCE LOCK PROTOCOL VERIFIED / RUNTIME CONCURRENCY TEST EXECUTION NOT VERIFIED / S6-CONTRACT-001 OPEN  
**Mode:** Documentation only. No implementation, migrations, tests against production, merge or deployment.

## 1. Audited source

- app/Application/Support/TransactionManager.php
- app/Application/TeacherAuthoring/LockActiveTeacherCurriculumAuthority.php
- app/Application/TeacherAuthoring/LockedTeacherCurriculumAuthority.php
- app/Application/TeacherAuthoring/ManageTeacherCurriculumAuthoring.php
- app/Application/TeacherAuthoring/ManageTeacherLessonAssessmentAuthoring.php
- app/Application/TeacherAuthoring/ManageTeacherPracticeExamAuthoring.php
- app/Application/TeacherAuthoring/FilterActiveTeacherCurriculumRead.php
- Existing concurrency tests and tests/Support/phase_h5_concurrency_worker.php.

## 2. Verified transaction contract

TransactionManager::run wraps its callback in Laravel DB::transaction(attempts: 1) and translates QueryException or PDOException via PostgresExceptionTranslator. It does **not** silently retry the callback or provide its own additional idempotency contract. A caller must not assume an automatic deadlock retry.

LockActiveTeacherCurriculumAuthority::execute expressly checks DB::transactionLevel() >= 1 and fails otherwise. Its initial assignmentIdentity lookup is explicitly non-authoritative and unlocked. Authoritative row locks, in exact order, are:

1. Teacher User — lockForUpdate.
2. Subject — lockForUpdate.
3. TeacherSubjectAssignment — lockForUpdate.
4. Curriculum — lockForUpdate.

After locking, it revalidates Teacher role/status, canonical active/available Subject, active exact TeacherSubjectAssignment ownership and matching Curriculum assignment/Subject, returning LockedTeacherCurriculumAuthority. Invalid authority raises ModelNotFoundException, not access through stale preflight.

## 3. Extension locks, by aggregate family

| Family | Verified service path | Mandatory order for future implementation/testing |
|---|---|---|
| Curriculum/taxonomy | ManageTeacherCurriculumAuthoring::lockVersion invokes authority.execute, then locks CurriculumVersion; topic, placement and home topic operations lock relevant resource rows | Preserve authority order before CurriculumVersion, then aggregate children according to existing service; sorted normalized IDs where required |
| Lesson/assessment | ManageTeacherLessonAssessmentAuthoring::lockVersion invokes authority.execute; locks CurriculumVersion, then Lesson/Assessment, revision, Topic/SkillVersionPlacement as applicable | Never start with a child lock before authoritative actor/source locks; historical released revision immutability remains enforced |
| Practice/exam | ManageTeacherPracticeExamAuthoring::version invokes authority.execute and locks CurriculumVersion; subsequent Practice/Template/Version and selected revisions are locked in service-specific order | Preserve source-specific parent-to-child locks and sorted revision IDs; do not generalize an incorrect one-size-fits-all lock sequence |
| Exam generation | Controller BuildExamGeneration takes lockGenerationAuthority callback; source template/version/revision eligibility is revalidated | Check order within generation transaction and multi-process preconditions; do not trust an earlier unlocked candidate list |
| Active Teacher reads | FilterActiveTeacherCurriculumRead filters exact Teacher/assignment/subject/current ownership | Read eligibility is not replacement for transaction-time mutation authorization |

**Boundary:** This table records observed source protocol; it is not a formal proof that every competing write path, middleware workflow, PostgreSQL trigger and cross-domain caller follows the same lock discipline. That requires multi-process execution evidence.

## 4. Existing named test suites (source found, not executed here)

| Existing test file | Relevant scenarios |
|---|---|
| tests/Feature/LockActiveTeacherCurriculumAuthorityTest.php | active exact ownership; foreign Teacher; disabled Teacher; inactive assignment; ownerless, noncanonical/inactive Subject; filtered reads |
| tests/Feature/TransactionManagerTest.php | transaction wrapper and exception translation |
| tests/Feature/Concurrency/TeacherAuthoringAuthorityConcurrencyTest.php | disabled Teacher stale Practice mutation; inactive assignment stale Practice mutation; reversed revision input order without deadlock |
| tests/Feature/Concurrency/TeacherAuthoringLifecycleConcurrencyTest.php | Lesson publish/unpublish; Lesson publish/revision; Assessment publish/retire; Assessment publish/revision |
| tests/Feature/Concurrency/TeacherExamTemplateConcurrencyTest.php | Template version update/publish repeated; publish new version/retire old noncurrent version |
| tests/Feature/Concurrency/TeacherExamGenerationConcurrencyTest.php | Template publish/generation; retired template/curriculum; disabled Teacher; shared-source generation vs attempt; uncommitted generation/attempt |
| tests/Feature/Concurrency/TeacherPracticeAttemptConcurrencyTest.php | Practice add/remove serialization; preserve active last membership; teacher mutation vs attempt frozen snapshot |
| tests/Feature/Concurrency/ParentLockProtocolTest.php | Parent locking protocol regression |
| tests/Feature/Concurrency/PhaseH5WorkerProtocolTest.php | Worker protocol conformance |
| tests/Feature/Concurrency/PostgresProcessBarrierTest.php | Process barrier utility |
| tests/Feature/TeacherCurriculumAuthoringApiTest.php | Teacher Curriculum authoring endpoint checks |
| tests/Feature/TeacherLessonAssessmentAuthoringApiTest.php | Lesson/assessment authoring endpoint checks |
| tests/Feature/TeacherPracticeExamAuthoringApiTest.php | Practice/exam authoring endpoint checks |
| tests/Feature/TeacherWorkspaceReadApiTest.php | Workspace contextual read checks |

## 5. Codex execution test register — mandatory before S6 closure

| ID | Evidence requirement | Primary test source |
|---|---|---|
| S6-PG-01 | Mutations outside DB transaction reject authority use | LockActiveTeacherCurriculumAuthorityTest + focused test if missing |
| S6-PG-02 | Actor/User lock precedes Subject → Assignment → Curriculum, no stale grant | LockActiveTeacherCurriculumAuthorityTest; TeacherAuthoringAuthorityConcurrencyTest |
| S6-PG-03 | Disabled Teacher / inactive assignment concurrently with pending write cannot commit | TeacherAuthoringAuthorityConcurrencyTest |
| S6-PG-04 | Opposite revision-ID order does not deadlock (repeat trials) | TeacherAuthoringAuthorityConcurrencyTest |
| S6-PG-05 | Lesson and Assessment lifecycle races serialize, sealed revision unchanged | TeacherAuthoringLifecycleConcurrencyTest |
| S6-PG-06 | Template publish/update/retire races serialize and final current pointers consistent | TeacherExamTemplateConcurrencyTest |
| S6-PG-07 | Generation locks/revalidates active Teacher, source status, exact revision set | TeacherExamGenerationConcurrencyTest |
| S6-PG-08 | Practice membership races preserve nonempty active aggregate and immutable Attempt snapshot | TeacherPracticeAttemptConcurrencyTest |
| S6-PG-09 | HTTP 401/403/404/409/419/422 envelope matches API and frontend; no IDOR | API feature suites, bootstrap/app.php and frontend API tests |
| S6-PG-10 | Test DB isolation: assert PostgreSQL database identity distinct from production before any test | environment preflight (must capture logs) |
| S6-PG-11 | Nonzero executed tests; true separate OS processes/connections, barrier and blocked-session evidence | above concurrency suites and worker protocol |
| S6-PG-12 | Full suite, frontend typecheck/build, client async/dirty-state regression, CI after authorized push | approved execution candidate HEAD |

### Suggested *read-only* command preflight for future authorized Codex run

- git rev-parse HEAD; git status --short --branch
- php artisan about and inspect resolved test DB connection without disclosing credentials
- Confirm PostgreSQL driver, dedicated test DB and no production URL/DATABASE_URL; verify database identity before running any migration or tests
- php artisan route:list --path=api/teacher
- php artisan test --filter=LockActiveTeacherCurriculumAuthorityTest
- php artisan test --filter=TeacherAuthoringAuthorityConcurrencyTest
- php artisan test --filter=TeacherAuthoringLifecycleConcurrencyTest
- php artisan test --filter=TeacherExamTemplateConcurrencyTest
- php artisan test --filter=TeacherExamGenerationConcurrencyTest
- php artisan test --filter=TeacherPracticeAttemptConcurrencyTest
- Run Phase H feature suites and frontend tests/typecheck/build via repository canonical scripts after environment isolation proof

These are **future instructions**, not commands executed by this audit. Test filtering must be checked for nonzero discoveries and separate-connection proofs; PASS without executed assertions is invalid.

## 6. Findings / open work

**S6-LOCK-001 — VERIFIED SOURCE RULE:** The canonical Teacher authority lock order is Teacher User → Subject → Assignment → Curriculum and requires an existing transaction. Treat any competing path that inverts that order as an integrity escalation, not as a normal UX fix.

**S6-LOCK-002 — EVIDENCE GATE OPEN:** Full cross-service path trace and actual multi-process PostgreSQL run have not been performed during documentation review. Existing named tests indicate coverage exists, not that all assertions pass at the eventual execution HEAD.

**S6-LOCK-003 — REVIEW REQUIRED:** Per-service child ordering and revision-ID sorting should be checked against every mutation path and source lifecycle operation, especially generation callbacks and locked source eligibility. Do not infer universal lock order solely from a controller.

**S6-R001 — BLOCKER:** The original approved Phase H Implementation Plan S6 text remains unavailable in the current repository tree; recover before EXECUTION READY.

**S6-R002/R003 — OPEN:** Exact response/validation follow-up tests and panel async/error coverage remain pending as recorded in preceding annexes.

## 7. Approval and authority

This annex freezes no new product behavior. It records the existing authority-lock contract, enumerates executable tests and stops at the current evidence boundary. **S6-CONTRACT-001 OPEN; EXECUTION READY = NO; Codex implementation, merge and deployment remain unauthorized.**

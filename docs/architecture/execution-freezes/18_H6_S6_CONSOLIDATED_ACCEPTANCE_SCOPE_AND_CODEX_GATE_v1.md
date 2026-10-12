# EduCore — H6-S6 Consolidated Acceptance Scope, Evidence & Codex Gate v1

**ID:** H6-S6-CAS-001  
**Date:** 2026-10-12  
**Repository:** Atif7rbi/EduCore  
**Implementation baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Documentation branch:** `docs/educore-execution-contract-freezes-v1`  
**Status:** **BOUNDED ACCEPTANCE CANDIDATE — DOCUMENTED / RUNTIME UNVERIFIED / NO EXECUTION AUTHORIZATION**  
**Permission:** Documentation changes on this branch only; no application edits, tests against production, implementation branch creation, PR #9 editing, merge or deployment.

## 1. Scope provenance and decision precedence

The earlier approved H6 frontend slice breakdown is: **S1 Foundation → S2 Context → S3 Taxonomy → S4 Lessons/Assessment → S5 Practice/Exams → S6 cross-cutting Lifecycle, History and Error UX → S7 integration acceptance**. This subdivision is recorded in the project's prior execution decisions; it does **not** appear as an identically named S6 subsection in the original broad Phase H Implementation Plan. The recovered Phase H plan governs inherited scope and the canonical browser portals `/portal/teacher` and `/portal/student`. Source documents: `14_PHASE_H_ORIGINAL_PLAN_RECOVERY_AND_S6_RECONCILIATION_v1.md` and `forward-package-v1/02_H6_S6_ARCHITECTURE_SCOPE_FREEZE_v1.md`.

The exact earlier standalone H6-S6 instruction/acceptance artifact was not retrieved as a versioned primary file. Accordingly this packet **reconstructs** its bounded scope from the recorded S1–S7 slice decision, forward-package S6 freeze, and code/tests; it does not assert a verbatim recovered original S6 acceptance document. Authoritative full S6 acceptance still requires explicit user ratification or retrieval of a primary original.

Precedence: frozen architecture pack → CDA-008/CDA-009 explicit amendments → approved Phase H plan and corrections → later approved H6 slices and their release gates → current code as implemented truth → conditional S6 documentation. Deferred DD decisions remain STOP conditions, never Codex discretion.

## 2. Baseline and evidence discipline

PR #9 is currently **OPEN/DRAFT** at SHA `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`. Its **description** still says S1–S4 and 'S5–S7 remain unimplemented', while the same HEAD and historical H6-S5 preflight/source inventory contain S5 Practice/Exam panels and tests. **Finding DOC-PR9-001: STALE PR DESCRIPTION**; PR body is not authoritative implementation inventory. Do not modify the PR without explicit permission.

Source audit: 63 teacher routes; 12 teacher workspace modules including shell/context and ten panel/editor modules; seven UI test files inventoried; backend transactional authority source and concurrency suites named. The reviewed historical source assertions are **not** current executed PASS evidence. No test count has been manufactured.

## 3. Frozen no-redesign constraints

- Preserve ownership: active Teacher User, canonical eligible Subject, active TeacherSubjectAssignment, exact Teacher-owned Curriculum and version.
- PostgreSQL authority order: Teacher User → Subject → TeacherSubjectAssignment → Curriculum → operation-specific version and children, all locked/revalidated inside mutation transaction.
- `User.status = active|disabled`; TeacherSubjectAssignment may be active/inactive; never use User inactive.
- Backend `/api/teacher/*` is authoritative; React role guards and disabled buttons are UX only.
- Released revisions, published/retired history, Attempt snapshots and ExamGeneration provenance are not retroactively rewritten.
- Do not reinterpret HTTP 409 lifecycle conflict as 403/404 access denial; refresh authority with correct context.
- Active Practice membership mutation preserves at least one eligible released exact-version revision. Do not impose archived-only membership mutation.
- No backend endpoints, schema changes, new lifecycle statuses, Admin authoring, learner redesign, generic RBAC, global score, unresolved JSON schema assumptions, merge or deployment.

## 4. Executable H6-S6 acceptance contract candidate

| ID | Requirement | Positive case | Negative / race case | Proof expected |
|---|---|---|---|---|
| S6-AC-01 | Teacher context exact ownership | active actor and exact assignment/curriculum/version render correct controls | disabled Teacher, inactive assignment, ineligible Subject, wrong version fail closed | backend feature+PG concurrency / context UI |
| S6-AC-02 | Lifecycle action eligibility | draft edit; legal release/publish/retire/archive controls follow backend truth | published/released/retired stale controls cannot commit illegal mutation | focused per-panel frontend+API |
| S6-AC-03 | Historical read-only UX | existing published/released/retired entities remain inspectable where authorized | no edit/delete that rewrites historical record; no global mutation | UI and backend regression |
| S6-AC-04 | Authentication and lost authority | authorized session continues; role-appropriate portal route | 401 and 419 global session handling; 403/404 clear context and stop editing, no stale success | API/session/context tests |
| S6-AC-05 | Conflict recovery | 409 refreshes authoritative resource/version while preserving valid user draft | no false success, no wrong-principal refresh or override of immutable state | controlled conflict tests |
| S6-AC-06 | Field validation | 422 renders field errors and retains semantic draft | malformed payload not silently sent, error details not misinterpreted | client+backend payload tests |
| S6-AC-07 | Unsaved-change protection | clean resource switch without warning, confirmed discard transitions | cancel route/tab/context/resource replacement retains draft | parameterized panel+workspace tests |
| S6-AC-08 | Stale asynchronous responses | GETs abort or identity-check; pending write A does not affect selected B | actor, assignment, curriculum, resource and unmount races | deferred promise tests, mutation identity key |
| S6-AC-09 | Exact source scope in Practice/Exam | membership order and immediate generation result correspond to submitted versions | active last-item removal; late mutation; generation after revocation | UI + PG race suites |
| S6-AC-10 | Cross-role non-regression | Teacher /portal/teacher, Student /portal/student, Admin /admin; /app only legacy | no teacher/admin mutation privilege escalation or cross-role cache leak | routing/auth integration tests |
| S6-AC-11 | No unapproved backend change | no new route/schema/service semantics | attempted expansion causes STOP and architecture escalation | reviewed diff and route/schema checks |
| S6-AC-12 | Test evidence and handoff | targeted and full tests green on eligible candidate HEAD | no zero-discovered/skipped-as-pass; no production test DB | nonzero command outputs, blocked-PID proof, CI, reviewer signoff |

These are **candidate acceptance criteria synthesized from recorded architecture and approved scope envelopes**, not proof that each case is absent or needs new code. Tests first; remediate only a demonstrated failing behavior within frozen contract.

## 5. Panel-by-panel action matrix

| Module | Already evidenced by S1–S5 source/tests | S6 investigation needed |
|---|---|---|
| TeacherWorkspacePage | aggregate dirty guard; navigation accept/cancel; 403/404 exit | 401/419 and global principal switch with pending mutations |
| TeacherContextSelector | parent-bound scoped URLs, revoked assignment, aborted stale GET, principal-scoped query | full authority loss during editing and delayed write |
| TeacherTopicsPanel | draft CRUD, read-only published, 422 retention, 409 refresh | late A→B write/context switch and session lost |
| TeacherSkillPlacementsPanel | same-version placement/home topics, provenance, read-only, conflict recovery | concurrent remove/add on context switch, 403/419 |
| TeacherLessonsPanel | draft create; historical read only; parent reconciliation | action eligibility with in-flight publish/unpublish and stale selection |
| TeacherLessonRevisionEditor | JSON guard and released history | late skill/revision mutation, server validation/403-404 |
| TeacherAssessmentItemsPanel | draft create; publish/retire changes on refetch | pending lifecycle write after principal/resource switch |
| TeacherAssessmentRevisionEditor | JSON guard and released read only | pending revision/skill mutation vs release and 422 errors |
| TeacherPracticeActivitiesPanel | archived create; A→B late mutation isolation; edit dirty guard | multi-mutation order and 401/419 in draft |
| TeacherPracticeMembershipEditor | membership order; active final-item guard; dirty selection | real PG last-item race and late mutation UI reconciliation |
| TeacherExamTemplatesPanel | A→B late mutation isolation; dirty creation/editor switches | template state changes across context and 401/419 |
| TeacherExamTemplateVersionEditor | draft JSON guard; 422, 409, published history, late generation unmount | rules_payload transport test; blocker-code display, generation eligibility under revoked grant |

**No confirmed software bug** was established by the read-only panel inspection. Missing test combinations are VERIFY (`V`), not automatically G1/G2 defects. Confirmed defects require exact failed assertion/response/state pair.

## 6. Two narrowly bounded contract investigations

**S6-TYPE-001:** Frontend `TeacherRulesPayload` accepts object or array; Laravel Controller uses `present|array` semantics. JSON-to-PHP decoding makes object rejection **unproven**. Feature-test `{}`, `[]`, nonempty object/array, scalar, null, and semantic rules constraints. Changing TS/backend semantics requires evidence and compatible frozen contract.

**S6-ERR-001:** 409 `curriculum_version_not_ready` emits `{blockers:string[]}`; frontend normalization retains these string arrays but some panels flatten and render them like 422 human field error messages. Verify actual UI wording and state recovery; avoid inventing error envelope.

## 7. Codex test execution manifest — conditional future run

**Mandatory safety preflight:** user-granted branch/base, clean worktree, verified isolated **PostgreSQL** database name/connection separate from `/home/sewaellf/EduCore` production tree; no PHPUnit/migrations against production. Observe intended test DB in resolved Laravel configuration before any mutating test.

| Sequence | Existing suite/test | Expected evidence |
|---|---|---|
| T01 | LockActiveTeacherCurriculumAuthorityTest; TransactionManagerTest | active authority, role/Subject/assignment revocation, transaction required, single-attempt behavior |
| T02 | TeacherWorkspaceReadApiTest; TeacherCurriculumAuthoringApiTest | exact parent/ownership, HTTP status/envelope |
| T03 | TeacherLessonAssessmentAuthoringApiTest; TeacherPracticeExamAuthoringApiTest | lifecycle, no historical mutation, 409/422 |
| T04 | TeacherAuthoringAuthorityConcurrencyTest | disabled Teacher, inactive assignment and opposite same-type revision order |
| T05 | TeacherAuthoringLifecycleConcurrencyTest | Lesson publish/unpublish, Assessment publish/retire parallel |
| T06 | TeacherExamTemplateConcurrencyTest; TeacherExamGenerationConcurrencyTest | source/version/generation interlock |
| T07 | TeacherPracticeAttemptConcurrencyTest; ParentLockProtocolTest | active last membership and frozen Attempt snapshot |
| T08 | PostgresProcessBarrierTest; PhaseH5WorkerProtocolTest | distinct process/backend PID, `wait_event_type=Lock`, `pg_blocking_pids`, exact expected blocker; no fixed sleep |
| T09 | TeacherWorkspacePage.test.tsx; TeacherContextSelector.test.tsx | dirty guard, context role/source switch, 401/419/403/404 |
| T10 | TeacherTopicsPanel.test.tsx; TeacherSkillPlacementsPanel.test.tsx | taxonomy 409/422, read-only, late A→B |
| T11 | TeacherLessonAssessmentPanels.test.tsx; TeacherRevisionEditors.test.tsx | lifecycle/immutable errors and mutation interleavings |
| T12 | TeacherPracticeExamPanels.test.tsx; TeacherPracticeExamApi.test.ts | practice/exam late response, rule payload, conflicts |
| T13 | full PHP suite, Pint, frontend npm test/typecheck/build | nonzero counts; complete regression |
| T14 | CI at exact permitted SHA and independent full-diff review | no blocker/major; arch decisions separately recorded |

Suggested direct commands only **after** environment/branch authority: `php artisan test --filter=TeacherAuthoringAuthorityConcurrencyTest`, corresponding other targeted filters, `php artisan test`, `./vendor/bin/pint --test`, `npm test`, `npm run typecheck`, `npm run build`. These were **not executed** during documentation. Test counts and pipeline state must be gathered, not assumed.

## 8. PR #9 reconciliation

PR #9 description currently claims S1–S4 only. At the checked HEAD, source inventory contains S5 Teacher Practice/Exam panels and tests. This may be **stale PR narrative**, not a defect in commits. Before merging, separately authorize updating the draft PR description to reflect verified slices and test runs. Do not silently edit PR or promote to Ready for review.

## 9. Scope-approval options and no-go rules

**A — Approve bounded S6 acceptance definition only:** user explicitly ratifies S6-AC-01..12 as the derivative slice criteria, preserving sources and no code authority. This closes *scope provenance by ratification*, not runtime gates.

**B — Authorize test-only preflight later:** independently specify exact branch, SHA, isolated DB, read-only/test actions and output. No code patch without authorization. This can discover G1/G2 defects before implementation starts.

**C — Authorize bounded code remediation later:** after criteria frozen and failing evidence, grant per-branch edits, commit/push/PR permissions explicitly; no merge/deploy; STOP on any new API/DB/domain decision.

**At present none of A/B/C has been separately authorized by this document.** The current user requested documentation/consolidation and this packet does not override that boundary.

## 10. Formal readiness ledger

| Gate | Decision today | Blocking next evidence |
|---|---|---|
| Original Phase H plan H6 and H-IP remediation | RECOVERED / DOCUMENTED | none for inheritance |
| Later H6-S6 acceptance text | RECONSTRUCTED CANDIDATE, NOT VERBATIM RECOVERED | user ratification or primary slice artifact |
| S1–S5 source implementation preservation | VERIFIED AT SOURCE BASELINE | regression at candidate HEAD |
| API inventory | 63 SOURCE-IDENTIFIED | per-route integration assertion / current SHA |
| Error and JSON transport | 2 OPEN INVESTIGATIONS | targeted tests T02/T03/T12 |
| Frontend race/dirty UX | COMPONENT INVENTORY COMPLETE | controlled negative tests T09–T12 |
| PG locks | ORDER VERIFIED IN SOURCE | true multi-process execution T04–T08 |
| Full execution | NOT AUTHORIZED | explicit start and Git rights |
| Independent review / merge / deployment | NOT AUTHORIZED | complete evidence and separate user approval |

**Final:** H6-S6 SOURCE-BASED ACCEPTANCE AND CODEX TEST PACKET PREPARED. `S6-CONTRACT-001` OPEN PENDING RATIFICATION AND EXECUTED PROOF. `EXECUTION READY = NO`. NO APP CODE/PR/MAIN/PRODUCTION MODIFICATION.

# EduCore — H6-S6 Decision-Ready Test-Only Authorization Packet v1

**Date:** 2026-10-12
**Decision:** PREPARED — REQUIRES EXPLICIT USER APPROVAL
**Repository:** Atif7rbi/EduCore
**Implementation evidence SHA:** 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d
**Documentation branch:** docs/educore-execution-contract-freezes-v1
**PR:** #9 (OPEN/DRAFT; no change authorized)
**No implementation or deployment authorized by this document.**

## 1. Verified historical GitHub CI evidence

GitHub Actions run ID: **38107332135**, workflow **EduCore CI**, completed with conclusion **success**, associated with the reviewed implementation SHA.

Its jobs and successful steps:
- **Frontend validation — success:** Install npm dependencies, Run frontend tests, Run TypeScript validation, Build frontend assets.
- **Pint changed PHP — success:** Check changed PHP files with Pint.
- **Backend PostgreSQL — success:** Verify disposable PostgreSQL identity and UTC session; Run complete migration set; Run complete backend suite serially.

These are verifiable CI outcomes, not self-reported local execution. They prove only the test steps that CI ran **at this implementation SHA**. CI success is NOT proof that later H6-S6 scope-specific negative cases already exist or that new H6-S6 work has been executed. In particular:
1. Pint step covers **changed PHP**, not necessarily all PHP.
2. Backend suite serial run does not by itself substitute for reviewing exact real multiprocess blocker PID assertions in relevant test code/logs.
3. The listed CI steps do not prove dedicated new H6-S6 cross-context late-write/error tests were introduced.
4. Test counts/skips and PostgreSQL concurrency coverage should be extracted from logs before final acceptance; do not invent numbers.

## 2. Reconciled actionability

Existing CI confirms a strong baseline suitable for proposing a controlled **test-only investigation** rather than asking Codex to rewrite the workspace.

H6-S6 scope is candidate-derived from the approved overall Phase H H6 plan, the later S1..S7 execution decisions, and forward-package-v1/02. Original standalone literal H6-S6 acceptance text is **not independently recovered**. The 12 acceptance cases in 18_H6_S6_CONSOLIDATED_ACCEPTANCE_SCOPE_AND_CODEX_GATE_v1.md are **proposed for explicit user ratification**, not silently approved.

## 3. Precise bounded test-only phase: H6-S6-T0

**Objective:** Verify the remaining S6-specific risk hypotheses and identify any actual failed behavior, without applying code changes.

**Allowed only AFTER user authorization:**
1. Read-only inspect the current implementation branch and check HEAD/clean status.
2. Inspect the full approved H6-S6 handoff and 12 acceptance cases; report scope ambiguity.
3. Run frontend tests with existing repository npm scripts: `npm test`, `npm run typecheck`, `npm run build`.
4. Reproduce/inspect focused 409/422 rules-payload/error details and 401/419/session tests with the existing test infrastructure.
5. Execute backend tests only in confirmed isolated disposable/nonproduction PostgreSQL, never production database or production tree.
6. Re-run existing concurrency test suites using distinct PostgreSQL backend sessions and blocking PID evidence.
7. Produce a traceability matrix: case ID → exact test path/name → outcome/count → raw job or log reference.
8. Classify outcomes G0 (already working), V (missing test proof), G1 (proven frontend defect), G2 (proven integration mismatch), G3 (missing approved workflow) or G4 (architecture STOP).

**Not allowed in this test-only phase:** write any app code, modify test files, create migration/schema/endpoint, commit, push, PR edit, merge, deploy or change production config. If the tool/workflow inherently requires a temporary isolated clone/install/build, confirm exact path and safety first. No changes to tracked implementation sources.

## 4. Safety and stopping checks

Before tests: confirm exact implementation branch/HEAD, clean tree, environment and resolved connection driver/database identity, dedicated PostgreSQL test DB, no production credentials, and no use of the production working tree for PHPUnit. Historical safe backend test clone: /home/sewaellf/EduCore-test-d4ux with sewaellf_educore_test; **revalidate actual current state before use**, never assume the old clone/DB remains isolated.

Stop immediately if:
- expected HEAD changed or worktree has unknown modifications;
- resolved database is production or not provably disposable;
- tests require production migrations;
- a source conflict requires new API/DB/permission/role/lifecycle semantics;
- a test run cannot identify nonzero tests;
- intended Git changes exceed this authorization.

## 5. Named existing test families

- Transaction/ownership: TransactionManagerTest, LockActiveTeacherCurriculumAuthorityTest.
- API: TeacherWorkspaceReadApiTest, TeacherCurriculumAuthoringApiTest, TeacherLessonAssessmentAuthoringApiTest, TeacherPracticeExamAuthoringApiTest.
- PostgreSQL races: TeacherAuthoringAuthorityConcurrencyTest, TeacherAuthoringLifecycleConcurrencyTest, TeacherExamTemplateConcurrencyTest, TeacherExamGenerationConcurrencyTest, TeacherPracticeAttemptConcurrencyTest, ParentLockProtocolTest, PostgresProcessBarrierTest, PhaseH5WorkerProtocolTest.
- Frontend: TeacherWorkspacePage.test.tsx, TeacherContextSelector.test.tsx, TeacherTopicsPanel.test.tsx, TeacherSkillPlacementsPanel.test.tsx, TeacherLessonAssessmentPanels.test.tsx, TeacherRevisionEditors.test.tsx, TeacherPracticeExamPanels.test.tsx, TeacherPracticeExamApi.test.ts; associated auth/session tests identified by source search.

All suites must report their actual executed assertions/tests; do not claim S6 negative case coverage merely because a suite has a similar name.

## 6. Test evidence table to complete during authorized run

| Case ID | Existing test evidence | Missing case | Result | Executed count | Evidence |
|---|---|---|---|---|---|
| S6-AC-01..S6-AC-12 | inspect exact named assertions | list | NOT RUN FOR S6-T0 | — | — |
| S6-TYPE-001 | JSON object/array Laravel behavior | confirm shape cases | NOT RUN FOR S6-T0 | — | — |
| S6-ERR-001 | 409 blocker codes vs 422 field validation | confirm visible UI | NOT RUN FOR S6-T0 | — | — |
| FG-003 | actor/context/resource delayed mutation | enumerate missing combinations | NOT RUN FOR S6-T0 | — | — |
| FG-004 | 401/419 session during dirty editor | confirm fail-closed | NOT RUN FOR S6-T0 | — | — |

## 7. Proposed user decisions (must be separate and explicit)

**Decision A — Criteria ratification:** Approve the 12 candidate criteria S6-AC-01..12 in document 18 as the bounded H6-S6 slice acceptance definition, without introducing new backend/DB product behavior.

**Decision B — Test-only authority:** Authorize H6-S6-T0, limited to safe read-only inspection and test execution on a verified isolated nonproduction environment at explicitly checked implementation HEAD. No tracked code edits, branch promotion, commits, push, PR changes, merge or deployment.

**Decision C — Remediation authority:** NOT REQUESTED at this stage. Only after T0 evidence proves defects and explicit user approval.

User may approve A alone, B alone, both, or neither. Neither approval is implied by a generic continuation instruction.

## 8. End-of-run acceptance for H6-S6-T0

Required output:
1. Baseline/ref, branch, clean worktree and environment identity.
2. Exact executed test command, pass/fail/skipped and nonzero count for each family.
3. PostgreSQL database safety proof and blocker PID evidence for critical races.
4. S6-AC-01..12 mapping with named assertions, status and missing coverage.
5. S6-TYPE-001 and S6-ERR-001 measured results.
6. G0/V/G1/G2/G3/G4 classification with minimal suggested changes, no speculative reimplementation.
7. Current CI reference and candidate delta comparison.
8. Decision-ready STOP / GO-for-separate-remediation recommendation.
9. Confirmation that Git application tree, PR #9, main and production remain unchanged.

**Current final state:** HISTORICAL CI AT BASELINE = PASS. H6-S6-T0 NOT EXECUTED. ACCEPTANCE CRITERIA NOT YET RATIFIED. NO CODEX EXECUTION AUTHORIZED.

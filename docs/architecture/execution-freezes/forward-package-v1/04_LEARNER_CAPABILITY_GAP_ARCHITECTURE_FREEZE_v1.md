# EduCore — Learner Capability, Gap & Architecture Freeze

**Document ID:** LGA-001  
**Version:** 1.0 · **Date:** 2026-10-11  
**Repository:** `Atif7rbi/EduCore` · **Evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Decision authority:** User-authorized architecture preparation and approval.  
**Approval class:** APPROVED POLICY / CONDITIONAL EXECUTION FREEZE. Inherited FROZEN rules retain precedence. An unresolved contract must not be presented as verified or executable.  
**Execution authorization:** NONE. No Codex run, implementation, branch, commit, push, PR change, merge or deployment.

## Core correction
Learner functionality is **not greenfield**. At the reviewed HEAD, `resources/frontend/app/router.tsx` already exposes routes for discovery, curriculum version, lessons, practice, attempts, exams, results and progress; corresponding files and tests exist under `resources/frontend/learner/`. Presence does not prove end-to-end readiness.

## Mandatory inventory checklist
| Capability | Existing frontend evidence | Validation required |
|---|---|---|
| Curriculum discovery | `CurriculumDiscoveryPage.tsx` | Exact grant visibility, empty/denied states |
| Curriculum version | `CurriculumVersionPage.tsx` | Version identity, teacher owner and lifecycle |
| Lesson | `LessonPage.tsx` | Released revision, progress semantics |
| Practice | `PracticeActivityPage.tsx` | Eligibility and frozen source item set |
| Attempt | `AttemptPage.tsx` | Ownership, response edit only in_progress, submit/abandon |
| Exam | `ExamsPage.tsx` | Eligible generations, no invented endpoint |
| Results | `ResultsPage.tsx` | Immutable original outcome plus effective corrections |
| Progress | `ProgressPage.tsx` | Layer1/2 separation, no unsupported Layer3 labels |

## Verified inherited rules
- Effective learner access requires entire Phase F chain, not simply `active` enrollment.
- Read services never confer mutation rights.
- `Attempt` pins exact source item revisions and immutable presented/scoring/classification snapshot.
- Results use effective regrade corrections; do not rewrite original responses or finalization.
- No global score, no numeric mastery/interpretation thresholds; `DD-002`, `DD-004`–`DD-007` remain deferred where relevant.

## Gap classification
- **G0 Already implemented+tested:** evidence proves full expected contract; no new code.
- **G1 UX defect:** frontend presentation/routing/loading/accessibility issue with unchanged contract.
- **G2 Integration defect:** client/server contract mismatch; fix only after authoritative API reconciliation.
- **G3 Missing approved workflow:** implementation permitted only if frozen scope already requires it.
- **G4 Architectural decision:** STOP and produce amendment with options and consequences.

## Audit/test matrix
`LGA-01` unauthorized and disabled learner access; `LGA-02` teacher assignment/enrollment revocation; `LGA-03` wrong curriculum/version; `LGA-04` immutable attempt/response lifecycle; `LGA-05` duplicate submission/replay; `LGA-06` historic results on source retirement; `LGA-07` principal change/async races; `LGA-08` zero-data/error states; `LGA-09` responsive/accessibility basic journeys; `LGA-10` API/DB auth under PostgreSQL.

## Execution release gate
Produce a **file-by-file, endpoint-by-endpoint, test-by-test gap register**, cite the exact deficiency and assign G0–G4. Only then create a separately approved small remediation package for proven gaps. Never authorize Codex to reimplement the learner experience wholesale.

**Disposition:** `LEARNER GAP AUDIT POLICY APPROVED / REMEDIATION SCOPE NOT YET DEFINED / NO START`.
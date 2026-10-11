# EduCore — H6-S7 Cross-Feature Integration & Acceptance Freeze

**Document ID:** H6-S7-AF  
**Version:** 1.0 · **Date:** 2026-10-11  
**Repository:** `Atif7rbi/EduCore` · **Evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Decision authority:** User-authorized architecture preparation and approval.  
**Approval class:** APPROVED POLICY / CONDITIONAL EXECUTION FREEZE. Inherited FROZEN rules retain precedence. An unresolved contract must not be presented as verified or executable.  
**Execution authorization:** NONE. No Codex run, implementation, branch, commit, push, PR change, merge or deployment.

## Objective
Prove the existing teacher authoring workflow functions coherently **from assignment context to publish/usable artifacts**, without bypassing ownership, lifecycle or historical truth. S7 is a verification/integration gate, not a blanket new-feature phase.

## Entry gates
- H6-S1..S5 merged into the work branch (not necessarily main) and S6 independently approved and implemented.
- All targeted tests, typecheck and build successful at the candidate HEAD.
- Actual API and schema contracts mapped to every tested journey.

## Required end-to-end cases
| ID | Scenario | Pass criterion |
|---|---|---|
| S7-01 | Admin provisions Teacher, canonical Subject assignment; Teacher selects context | Exact role/assignment ownership, no content authorship by Admin |
| S7-02 | Teacher establishes permitted curriculum/version/taxonomy | Same-version identities, no cross-context leakage |
| S7-03 | Teacher authors LessonRevision and follows actual lifecycle | Correct source pinning and immutable released fact |
| S7-04 | Teacher authors/releases AssessmentItemRevision with required skills | Active canonical eligibility, no illegal edit of release |
| S7-05 | Teacher configures PracticeActivity membership from exact released revisions | Membership ordered; active last-item guard; history safe |
| S7-06 | Teacher configures ExamTemplateVersion and generation | Only supported lifecycle, deterministic generation provenance |
| S7-07 | Concurrent context loss/status change while reading/mutating | 403/404/409 cleanup and no stale writes |
| S7-08 | Student without full Phase F grant requests teacher-owned content | Denied; active enrollment alone insufficient |
| S7-09 | Cross-principal navigation after pending requests | No cached authorization/data bleed |
| S7-10 | Run PostgreSQL integrity/concurrency suites relevant to touched contracts | Nonzero tests; real separate connections/processes where applicable |

## Frozen exclusions
No new student capabilities, scoring algorithm change, analytics thresholds, interpretation model, database redesign, feature scope expansion or production deployment.

## Evidence pack required
- Test names and actual nonzero counts for each gate case, failures/repairs and final results.
- Typecheck, production build, frontend suite, backend PostgreSQL suites.
- Complete patch, source inventory, stable baseline and clean-worktree declaration.
- Independent review of whole H6 delta, open findings and explicitly recorded accept/reject.

## Stop conditions
New API behavior, undisclosed deferred decision, inconsistent S6 closure, deficient authorization source, material testability gap or proven integrity regression.

**Disposition:** `S7 ACCEPTANCE GATE APPROVED / EXECUTION DEPENDS ON S6 CLOSURE / NO START`.
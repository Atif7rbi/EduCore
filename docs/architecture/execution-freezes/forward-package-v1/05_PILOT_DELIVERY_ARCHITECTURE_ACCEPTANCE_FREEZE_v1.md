# EduCore — Pilot Delivery Architecture & Acceptance Freeze

**Document ID:** PDA-001  
**Version:** 1.0 · **Date:** 2026-10-11  
**Repository:** `Atif7rbi/EduCore` · **Evidence baseline:** `65551c0d6d15ffdf13df74b05d62c6662ceb5a6d`  
**Decision authority:** User-authorized architecture preparation and approval.  
**Approval class:** APPROVED POLICY / CONDITIONAL EXECUTION FREEZE. Inherited FROZEN rules retain precedence. An unresolved contract must not be presented as verified or executable.  
**Execution authorization:** NONE. No Codex run, implementation, branch, commit, push, PR change, merge or deployment.

## Target
Define a narrowly controlled **educational pilot** covering a real Admin → Teacher → Student learning loop, without representing full-scale production readiness as proven.

## Pilot acceptance journey
1. Active Admin provisions active Teacher and exact canonical Subject assignment.
2. Student registration creates product-valid Student + exactly one LearnerProfile; enrollment granted through approved request/Teacher acceptance lifecycle.
3. Teacher authors and publishes an eligible CurriculumVersion, lesson revision, released assessment content, practice activity and exam template/version using current supported operations.
4. Student obtains access only via exact active Phase F chain; unrelated, revoked or disabled actors fail closed.
5. Student completes lesson, practice and exam attempt with exact snapshot/provenance; submits response once and sees authoritative result.
6. Results/progress remain correct across content lifecycle and authorized regrade, without inventing mastery scores or thresholds.
7. Operator validates health, backup/restore, role segregation, support/debug logs and scoped incident rollback.

## Product inputs that must be frozen separately before pilot start
- Named test roles/accounts and quantity of participants (use test identities, not personal secrets).
- Canonical Subject and exact CurriculumVersion(s); lesson/content/assessment sample set.
- Browser/device matrix and accessibility minimum; response-time and error-rate acceptance thresholds.
- Environment URL, secure session transport, PostgreSQL version, hosting ownership and backup/RPO/RTO requirements.
- Operational escalation owner, support handling and test data retention.

## Pilot gates
| ID | Acceptance | Evidence |
|---|---|---|
| PIL-01 | One fully functioning role-separated learning journey | Recorded UAT with exact actor/context |
| PIL-02 | Invalid grants fail closed under concurrent lifecycle changes | DB integration and e2e auth tests |
| PIL-03 | Exact-version truth survives publish/retire/regrade | Historical regression tests |
| PIL-04 | No unresolved blocker/major findings | Independent review ledger |
| PIL-05 | Frontend/backend full CI at approved SHA | Commit and CI links |
| PIL-06 | Tested database backup and restore | Recovery evidence |
| PIL-07 | Target-runtime readiness gates pass | `production:database-check`, `production:smoke-check`, `production:readiness-audit` |
| PIL-08 | External HTTPS and session smoke validated | Operational evidence |
| PIL-09 | Explicit sign-off and restricted pilot authorization | User decision |

## Strict production distinction
`docs/production/PRODUCTION_READINESS_AUDIT.md` enumerates ten target-deployment prerequisites. CI success or backend implementation readiness alone **does not** authorize deployment. `DD-011` privacy deletion, `DD-013` commercial entitlements, `DD-014` delivery/protection and other deferred matters must be evaluated based on pilot scope; do not silently resolve.

## Deliberate exclusions unless independently approved
Subscription/payment enforcement, multi-tenancy, organization/classrooms, AI tutor, adaptive learning, global proficiency label, advanced analytics materialization and any unapproved content-protection provider.

**Disposition:** `PILOT MINIMUM ACCEPTANCE POLICY APPROVED / PRODUCT AND TARGET-ENVIRONMENT DETAILS CONDITIONAL / NO DEPLOYMENT`.
# EduCore — Pilot Product Configuration & Readiness Freeze v1

Decision: ECF-PILOT-001
Status: APPROVED — readiness gates; pilot-specific operational values remain OPEN
Date: 2026-10-11
Authority: EduCore Architecture Pack and docs/production/PRODUCTION_READINESS_AUDIT.md
Evidence baseline: Atif7rbi/EduCore @ 65551c0d6d15ffdf13df74b05d62c6662ceb5a6d

## Goal
Define restricted real-user Pilot acceptance without equating existence of frontend pages or successful CI with full operational readiness.

## Pilot end-to-end journey
P-01: Active Admin provisions a Teacher by the dedicated approved workflow and establishes active TeacherSubjectAssignment on canonical Subject.
P-02: Teacher authors owned CurriculumVersion, lessons, released assessment revisions, Practice and ExamTemplateVersions through approved lifecycle.
P-03: Student with exactly one LearnerProfile requests enrollment, owning Teacher accepts/declines, and authorized deactivation follows existing contract.
P-04: Student sees only content authorized by complete Phase F transactional grant; constructs, answers and submits/abandons legitimate practice/exam attempts.
P-05: Correct results, historical corrections if separately authorized, and progress are rendered without fictional global scores or inferred Layer 3 thresholds.
P-06: Disable/revocation, enrollment/assignment change, concurrent writes, lost access, empty and API error states fail closed.
P-07: Admin oversight is read-only for Teacher-owned content, with correct ownership provenance.
These are acceptance targets, not assertions that all steps already work end-to-end.

## Data constraints
Use isolated pilot users and clearly identified content. Reuse canonical Subject identities; no arbitrary Subject creation, role mutation, synthetic learner link, new ownerless curricula, or rewriting historical Attempts. Deletion/cleanup must respect RESTRICT and authorized lifecycle; no destructive production cleanup.

## Configuration worksheet — OPEN, not silently defaulted
Must be separately approved before Pilot: target environment/URL and responsible operator; dates/duration; participants/count and roles; canonical subject/stage scope; content/question coverage; supported browsers/devices; performance/load targets; accessibility criteria; telemetry/alert contacts; severity/escalation and support; backup retention and recovery objectives; privacy notice/data-handling/legal requirements; UAT owner and acceptance signoff. Do not invent numbers or dates.

## Mandatory release gates
P-T01: approved SHA, clean tree, complete relevant CI and regression.
P-T02: real-browser Admin → Teacher → Student → Attempt → Result E2E including 401/403/404/409/422 and negative IDOR/grant coverage.
P-T03: PostgreSQL concurrency/transaction-time integrity and historical immutability on isolated test database.
P-T04: production APP_ENV, APP_DEBUG=false, HTTPS and approved PostgreSQL/session/cookie/cache/queue settings verified on the actual target.
P-T05: verified backup plus documented restore rehearsal; migration safety and rollback plan.
P-T06: production:database-check, production:smoke-check, production:readiness-audit and external HTTPS health verification PASS.
P-T07: security, cross-role, CSRF and session-revocation checks; observation and incident-response owners.
P-T08: accepted pilot performance/accessibility targets met and product-owner UAT signoff; no unresolved critical blocker.
P-T09: separate explicit deployment authorization.

## Deferred decisions remain deferred
Do not invent DD-002 repetition, DD-005/006 skill interpretation, DD-011 privacy deletion, DD-013 subscriptions, DD-014 content protection implementation, or other deferred scope. If required for safe Pilot, STOP and approve a distinct amendment.

## Decision and state
PILOT ARCHITECTURE GATES APPROVED; PILOT CONFIGURATION VALUES OPEN; PILOT EXECUTION/DEPLOYMENT READY = NO until verified target acceptance and separate user authorization. This document does not authorize code, data, merge or deployment changes.

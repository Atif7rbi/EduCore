# EduCore — Final Production Readiness Audit

## Purpose

This document records the final backend MVP production-readiness criteria.

The distinction is explicit:

    Implementation Ready

means the backend implementation, contracts, migrations, tests, security controls, observability and deployment tooling are complete.

    Deployment Ready

means the target runtime environment also satisfies every production requirement.

A backend can be implementation-ready while a specific server remains deployment-blocked.

---

## A8 readiness gates

### A8.1 Environment

Required production configuration includes:

- APP_ENV=production
- APP_DEBUG=false
- HTTPS application URL
- PostgreSQL
- database or Redis sessions
- encrypted sessions
- secure cookies
- HttpOnly cookies
- SameSite lax or strict
- database or Redis cache
- database or Redis queue

Verification:

    php artisan production:readiness-audit

---

### A8.2 Database

Production requires:

    PostgreSQL 10.23 or newer

EduCore does not pin a maximum PostgreSQL version.
Newer PostgreSQL versions remain eligible subject to
the normal database, integrity, concurrency and regression
verification suite.

The target database must contain:

- migrations
- users
- learner_profiles
- cache
- cache_locks
- jobs
- job_batches
- failed_jobs

Database backup and restore procedures are defined in:

    docs/production/DATABASE_BACKUP_RESTORE.md

---

### A8.3 Security

Implemented baseline:

- login throttling
- email normalization in throttle key
- session regeneration after login
- session invalidation on logout
- secure production cookie contract
- encrypted production session contract
- X-Content-Type-Options
- X-Frame-Options
- Referrer-Policy
- Permissions-Policy
- HSTS for secure production requests

CSP remains intentionally deferred until the final frontend asset and delivery model is known.

---

### A8.4 Observability

Implemented baseline:

- generated Request ID for each HTTP request
- X-Request-ID response header
- Request ID injected into logging context
- safe API 500 contract
- preservation of 401, 404, 409 and 422 semantics
- runtime exception details not exposed to API clients

---

### A8.5 Deployment

Deployment and rollback procedure is defined in:

    docs/production/DEPLOYMENT_RUNBOOK.md

Runtime verification command:

    php artisan production:smoke-check

Database verification command:

    php artisan production:database-check

Final readiness command:

    php artisan production:readiness-audit

---

## PostgreSQL compatibility baseline

The current EduCore environment runs:

    PostgreSQL 10.23

This version is the minimum production compatibility baseline
verified by the EduCore implementation and regression suite.

EduCore does not impose an artificial maximum PostgreSQL version.
A newer PostgreSQL release remains eligible when the normal
database, integrity, concurrency and regression verification
suite passes against that runtime.

---

## Final production authorization rule

Production deployment is authorized only when all of the following are true:

1. approved Git commit SHA
2. clean working tree
3. full automated test suite passes
4. PostgreSQL 10.23 or newer
5. production environment contract passes
6. verified database backup exists
7. production:database-check succeeds
8. production:smoke-check succeeds
9. production:readiness-audit succeeds
10. external HTTPS health verification succeeds

Until all ten conditions are met:

    Deployment Ready = NO

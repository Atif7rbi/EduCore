# EduCore — Production Deployment & Rollback Runbook

## Scope

This runbook defines the baseline deployment procedure for the EduCore backend.

It does not authorize a production deployment by itself.

A deployment must target a production-approved environment and database.

---

## Production prerequisites

Before first production deployment, confirm:

- PHP 8.2 or newer
- Composer installed
- PostgreSQL 14 or newer
- HTTPS enabled
- application document root points to Laravel public directory
- production environment variables configured
- APP_DEBUG=false
- production APP_KEY exists and is protected
- database credentials are not stored in Git
- storage and bootstrap/cache are writable
- session, queue and cache production settings satisfy the production contract
- a verified database backup exists before schema changes

Run:

    php artisan production:database-check
    php artisan production:smoke-check

Both must succeed in the production environment.

---

## Deployment source

Deploy only from an explicitly approved Git commit SHA.

Before deployment:

    git fetch origin
    git status --short --branch
    git rev-parse HEAD

The working tree must be clean.

Do not deploy from an uncommitted working tree.

---

## Recommended deployment sequence

1. Record current deployed commit SHA.
2. Fetch the approved source.
3. Create and verify a fresh database backup.
4. Enable maintenance mode when required.
5. Install production Composer dependencies.
6. Rebuild Laravel optimized caches.
7. Review migration status.
8. Run production migrations.
9. Restart long-running workers if present.
10. Run database readiness checks.
11. Run application smoke checks.
12. Verify public health endpoint over HTTPS.
13. Exit maintenance mode.
14. Perform authenticated application smoke verification.
15. Record deployed SHA and deployment result.

Commands:

    composer install \
      --no-dev \
      --prefer-dist \
      --optimize-autoloader \
      --no-interaction

    php artisan optimize:clear

    php artisan migrate:status

    php artisan migrate --force

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    php artisan production:database-check
    php artisan production:smoke-check

If queue workers are running:

    php artisan queue:restart

When maintenance mode is used:

    php artisan down

and after successful verification:

    php artisan up

---

## External health verification

Verify the deployed HTTPS endpoint externally:

    curl \
      --fail \
      --silent \
      --show-error \
      --https-only \
      https://YOUR_EDUCORE_DOMAIN/api/health

Expected JSON shape:

    {
      "data": {
        "status": "ok"
      }
    }

The response must also include:

    X-Request-ID
    X-Content-Type-Options: nosniff
    X-Frame-Options: DENY

Production HTTPS responses should include HSTS after trusted proxy and HTTPS detection are verified.

---

## Migration discipline

Never run these commands against production:

    php artisan migrate:fresh
    php artisan migrate:refresh
    php artisan db:wipe

Do not automatically run:

    php artisan migrate:rollback

during an incident.

Schema rollback can destroy or invalidate data.

Prefer:

    application rollback when schema remains backward-compatible

or:

    forward-fix when schema has already received production writes

Database restoration is a separate disaster-recovery decision.

---

## Application rollback

Before deploying, record:

    PREVIOUS_SHA=<current deployed commit>

If rollback is safe and no incompatible schema transition occurred:

    git checkout "$PREVIOUS_SHA"

    composer install \
      --no-dev \
      --prefer-dist \
      --optimize-autoloader \
      --no-interaction

    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    php artisan production:smoke-check

Do not perform application rollback blindly if the newer application already wrote data using a non-backward-compatible schema.

---

## Failed deployment response

If deployment verification fails:

1. Keep or restore maintenance mode if users could be affected.
2. Record the failing commit SHA.
3. Record the failing command.
4. Capture the Request ID for HTTP failures when available.
5. Inspect application logs.
6. Determine whether migrations already ran.
7. Determine whether production writes occurred after migration.
8. Decide between application rollback, forward-fix, or database recovery.
9. Never erase production data simply to make the deployment pass.

---

## Deployment completion record

Record at minimum:

    deployed_sha
    previous_sha
    deployed_at_utc
    database_backup_reference
    migrations_applied
    database_check_result
    smoke_check_result
    external_health_result
    rollback_required
    operator

A deployment is complete only after post-deployment verification succeeds.

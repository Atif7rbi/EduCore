# EduCore — Production Database Backup & Restore Contract

## Scope

EduCore production uses PostgreSQL only.

The backup target is the complete PostgreSQL application database, including:

- Laravel infrastructure tables
- All EduCore domain tables
- Constraints
- Indexes
- Functions
- Triggers
- Sequences or identity metadata where applicable
- Migration history

A backup is not considered operationally valid until restore has been tested.

---

## Supported production baseline

Minimum supported PostgreSQL major version:

    14

Development environments may temporarily use older PostgreSQL versions, but they are not production-approved.

Before deployment run:

    php artisan production:database-check
    php artisan migrate:status

A production deployment must not proceed blindly when pending migrations are unexpected.

---

## Logical backup

Use PostgreSQL custom format:

    pg_dump \
      --format=custom \
      --no-owner \
      --no-acl \
      --file=/secure/backup/location/educore-YYYYMMDD-HHMMSS.dump \
      "$DATABASE_URL"

If DATABASE_URL is not used, supply connection parameters through protected environment or PostgreSQL credential mechanisms.

Do not embed database passwords directly in shell history or committed scripts.

---

## Backup verification

Verify archive readability:

    pg_restore \
      --list \
      /secure/backup/location/educore-YYYYMMDD-HHMMSS.dump \
      >/dev/null

The command must exit successfully.

A file existing on disk is not sufficient proof that a usable backup exists.

---

## Restore drill

Restore into a separate disposable PostgreSQL database.

Example flow:

    createdb educore_restore_check

    pg_restore \
      --no-owner \
      --no-acl \
      --dbname=educore_restore_check \
      /secure/backup/location/educore-YYYYMMDD-HHMMSS.dump

Then run application-level verification against the restored database:

    php artisan migrate:status
    php artisan production:database-check

The restore database must never replace production during a verification drill.

Drop the disposable database only after verification is complete.

---

## Pre-deployment database sequence

Recommended order:

1. Confirm application commit SHA
2. Run production:database-check
3. Review migrate:status
4. Create fresh database backup
5. Verify backup archive readability
6. Put application into maintenance mode when required by the migration
7. Run migrations using --force
8. Clear or rebuild Laravel caches as required
9. Run production smoke checks
10. Exit maintenance mode

Migration command:

    php artisan migrate --force

Never use the following against production:

    migrate:fresh
    migrate:refresh
    db:wipe

---

## Rollback rule

Database rollback is not equivalent to application rollback.

Before any production migration, determine whether the migration is:

    Backward-compatible

or:

    Requires coordinated application/database rollout

Do not automatically run:

    php artisan migrate:rollback

during an incident.

A rollback may destroy data or violate assumptions introduced by later writes.

The safe recovery decision must consider:

- Deployed application SHA
- Migrations already applied
- Whether new writes occurred
- Whether schema changes were destructive
- Available verified backup
- Forward-fix feasibility

---

## Backup retention baseline

Until a dedicated infrastructure policy replaces this baseline:

    Daily backups: 7 days
    Weekly backups: 4 weeks
    Pre-deployment backup: retain through the deployment verification window

At least one backup copy must be stored outside the application web root.

Production credentials and backup archives must never be committed to Git.

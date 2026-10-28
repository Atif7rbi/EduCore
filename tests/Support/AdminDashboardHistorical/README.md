# Historical Dashboard regression harness

This harness proves that a Curriculum published under EduCore's historical
30-migration schema remains a valid legacy-ownerless record after the official
current migrations are applied.

It is intentionally restricted to the dedicated GitHub Actions job named
`backend-historical-regression`. Running it against a local development, test,
retained HF2, hosted, or otherwise pre-existing PostgreSQL database is
prohibited.

## Ephemeral CI boundary

The dedicated job runs on a fresh GitHub-hosted Ubuntu 24.04 virtual machine
with its own PostgreSQL 16 service container. The service has no persistent
volume and publishes PostgreSQL only to the job VM's loopback address. GitHub
Actions disposes of both the VM filesystem and the service container when the
job finishes or is cancelled.

The service starts one database named `educore_historical_regression`. A
bounded bootstrap step connects with the service-local bootstrap superuser,
verifies PostgreSQL 16, UTC, and an empty `public` schema, then creates the
restricted login role `educore_historical_app`. That role is explicitly
`NOSUPERUSER`, `NOCREATEDB`, and `NOCREATEROLE` and becomes the database owner.

Bootstrap credentials are scoped only to that workflow step. The historical
runner receives only the restricted application credentials and cannot create,
drop, or terminate PostgreSQL resources.

## Source and filesystem isolation

The workflow checks out complete Git history with credentials disabled. The
runner verifies:

- the exact historical SHA
  `e3f42f3b17b8f3e38881710289fac2944a3e89bd`;
- the approved current baseline
  `0494a0666d7beebe225d7687a29d29f2ea519a4d`;
- the active checkout equals `GITHUB_SHA` and the workflow-provided expected
  revision;
- production migrations have not changed from the approved baseline; and
- the historical fixture source matches its approved SHA-256 before it is
  adapted.

Generated files are confined to a new mode-0700 directory below
`RUNNER_TEMP`. The current submitted source is the active checkout. Historical
source is an ordinary no-hardlink clone detached at the exact historical SHA;
the harness does not use Git worktrees or global worktree metadata.

## Authoritative sequence

The runner performs this sequence against the one initially empty database:

1. Verify the exact database, restricted role, PostgreSQL 16, UTC session, and
   empty application schema.
2. Apply the authentic historical migration set and require exactly 30 ledger
   entries.
3. Verify the historical test source hash and adapt only the approved fixture
   construction block.
4. Use the historical authenticated Admin HTTP workflow to create the
   ownerless Curriculum and its version, content, practice, and exam records.
5. Publish the CurriculumVersion through the canonical historical publication
   workflow.
6. Verify the published ownerless historical state.
7. Use the active submitted source to apply the official forward migrations
   and require exactly 32 ledger entries.
8. Verify preserved NULL ownership and published status, the enabled current
   ownership trigger, and rejection of a new ownerless Curriculum insert.
9. Call the actual authenticated production Dashboard endpoint and verify the
   legacy/teacher-owned publication split, readiness values, operations, UTC
   snapshot, read-only transaction behavior, and absence of read-path writes.

## Runtime requirements

The dedicated job provides:

- PHP 8.5;
- Composer 2;
- `pdo_pgsql`, `pgsql`, `mbstring`, `xml`, `dom`, `fileinfo`, `intl`, `pcntl`,
  and `posix` PHP extensions;
- the PostgreSQL command-line client;
- complete Git history; and
- installed Composer dependencies in the active checkout.

## Failure and disposal

The script uses strict shell error handling and bounded connection, migration,
Composer, and PHPUnit timeouts. Any identity, source, migration, lifecycle, or
assertion failure exits nonzero and fails the dedicated CI job.

The previous shared-server manifest, proof-key, HMAC, recovery, resource-drop,
session-termination, and worktree-cleanup system has been removed. The harness
does not execute `DROP DATABASE`, `DROP ROLE`, `pg_terminate_backend`,
`git worktree prune`, or recursive teardown. Environment-level disposal by
GitHub Actions is the only cleanup authority.

No local PostgreSQL administrator access is required or accepted. In
particular, the harness never connects to `educore_local_test`,
`educore_local_dev`, or the retained `educore_hf2_7858004e39aa` evidence
database.

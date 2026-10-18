# EduCore — H6 Hosted Upgrade Attempt: Evidence, Incidents, Closure & Release Handoff

**Record date:** 2026-10-19  
**Repository:** `Atif7rbi/EduCore`  
**Status:** `CLOSED_FOR_CURRENT_ENGINEERING_CYCLE` — **NOT** production-certified or deployed  
**Authority:** H6 implementation/merge and hosted test work are separate authorizations. No production database modification, production upgrade, or deployment was authorized or performed in this cycle.  
**Evidence provenance:** Operator-provided Codex reports and execution manifests captured during the October 2026 rehearsal. This document records reported findings; it is not an independent re-execution of the host commands.

> **Read this before any future production release.** The project will resume feature development on **local Ubuntu only**. GitHub is the version-control/CI gate; Namecheap is the eventual release destination, **not** a daily development/test environment. Do not start another hosted testing cycle merely because this record exists.

## 1. Executive summary / exact stopping point

The approved H6 Teacher Workspace implementation was merged into GitHub `main` and its CI passed. The Namecheap instance remained at an older commit. Engineers investigated whether the five forward-only PostgreSQL migrations separating the hosted baseline from the approved H6 target could execute on the provider's **PostgreSQL 10.23**, rather than the newer PostgreSQL used in CI.

**Proven:**
- Production had **27 recorded migrations** at the export snapshot; its application checkout was at `1d7e058561d73c80ed7aa67bd87400ad536e692b`.
- Exact approved H6 target: `d3210a9a61e2a61c3983392435ac7bd6f4f1f235`; a manually triggered CI run (`38999206111`) passed the three reported checks (frontend, PostgreSQL 16 backend, Pint).
- On a distinct hosted **test** database, a consistent schema-only production export plus **only** `public.migrations` data was restored successfully to the 27-migration state.
- Against this *empty-of-application-data* restored baseline, all five H6 migrations ran successfully on PostgreSQL 10.23, yielding **32** migrations and expected objects.
- The isolated frontend built successfully; the hosted read-only suite had **85 distinct tests passing** after a Vite artifact remediation.
- The three committed-write TransactionManager tests passed with scoped cleanup.
- The first RefreshDatabase canary passed (4 tests). The next 13 files ran 81 successful PHPUnit methods; 12 files passed their immediate post-file gates. The 13th file, `AdminPracticeActivityApiTest.php`, passed 8/8 tests but its *first* immediate gate failed without naming the predicate; a later read-only audit passed.
- The gate's **observability defect** was corrected in a privately patched execution wrapper after independent review. **One authorized rerun** of file 13 passed 8/8 tests and **all 23/23 immediate predicates**.
- **173 distinct PHPUnit test methods** had passing results on the host: 85 read-only + 3 transaction + 4 canary + 81 from E2. The file-13 rerun is **not double-counted**. This is **not** 820/820 acceptance.

**Not proven / release risks:**
- The failed *historical predicate* remains `UNKNOWN`; wrapper logging defect is established, but a hypothesized session race was never demonstrated.
- **41 additional RefreshDatabase files (386 methods), 22 destructive-reset files (146 methods), and 16 concurrency/worker files (115 methods)** were **not** executed on the host. Counts are from the frozen 108-file/820-method inventory; no result may be inferred for them.
- The rehearsal used production **schema** and migrations metadata, **not production application rows**; it does not prove data-dependent production migration compatibility, production runtime behavior, or readiness to cut over.
- PostgreSQL **10.23 is end-of-life** (PG10 support ended 2022-11-10); successful migration DDL does not remove this security/operations risk.
- No fresh production restore drill, full deployment artifact validation, release rollback exercise, or production cutover was completed.
- Production database `sewaellf_educore` and hosted application remained unchanged throughout this effort.

**Pause boundary:** `H6_HOST_FINAL_CLOSURE_STAGE2=PASS`; `HOSTED_VALIDATION=CLOSED_FOR_CURRENT_ENGINEERING_CYCLE`; no more Namecheap regressions authorized. Next active work: local Ubuntu development and product roadmap, not this release gate.

## 2. Systems, commits and isolation boundaries

| Component | Recorded value | Role / caution |
|---|---|---|
| GitHub repository | `Atif7rbi/EduCore` | Source of record |
| Approved H6 merged `main` SHA | `d3210a9a61e2a61c3983392435ac7bd6f4f1f235` | Historical target; **re-resolve new release SHA before future deployment** |
| H6 GitHub CI | workflow_dispatch run `38999206111`, three successful checks | CI evidence, not production readiness |
| Hosted SSH | `premium52.web-hosting.com` / `sewaellf` | Namecheap shared host; connection alias `codex-ssh` |
| Public site | `https://edu.sewarsky.online/admin` | Old production instance, **not** upgraded |
| Hosted production source | `/home/sewaellf/EduCore` | `main`, HEAD `1d7e058561d73c80ed7aa67bd87400ad536e692b`, clean |
| Hosted pre-existing test checkout | `/home/sewaellf/EduCore-test-d4ux` | `feat/admin-console-v1`, HEAD `80558d90bb756792fb727c5de0201d6de94d395d`, clean; deliberately **not** target checkout |
| Production database | `sewaellf_educore` | Protected; PostgreSQL 10.23; database user `sewaellf_educore_Admin` |
| Test database | `sewaellf_educore_test` | Sole authorized mutating rehearsal target; PostgreSQL 10.23 |
| Test role | `sewaellf_educore_Codex` | Test database credential; account-specific grants described below |
| Test password file | `/home/sewaellf/.pgpass` | Mode 0600, owner `sewaellf`; **never print or commit it** |
| Approved H6 hosted source checkout | `/home/sewaellf/.educore-rehearsal/h6-g4-target-pTB2ZTOv/source` | Isolated detached checkout at target SHA |
| Patched test execution overlay | `/home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/source-overlay` | Original H6 source plus **test-only** compatibility patch |
| Main local Ubuntu workspace | `/home/s4d/gitHub/online/EduCore-codex` | **Sole active development environment after closure** |

The hosted public entrypoint references `/home/sewaellf/EduCore/public/index.php`; deployment or swapping the source directory therefore affects production. No such change was made.

The delta from the old hosted checkout to the historical approved target was reviewed as **45 commits / 247 changed files / 57,460 additions / 3,410 deletions**, including the five new forward-only migrations. These counts describe that *historical comparison*, not necessarily today's `main`.

### Five targeted migrations, in exact order

1. `2026_09_14_000001_enforce_user_role_immutability.php`
2. `2026_09_14_001000_create_teacher_subject_assignment_tables.php`
3. `2026_09_14_002000_create_student_enrollment_tables.php`
4. `2026_09_16_000000_add_curriculum_ownership.php`
5. `2026_09_23_000000_create_teacher_account_provisionings_table.php`

Recorded effects: five new tables, two new sequences, `curricula.teacher_subject_assignment_id`, nine PL/pgSQL functions and 11 non-internal triggers (including four deferred history-consistency triggers). There were no unexpected catalog removals in the successful incremental rehearsal. Several migrations are deliberately forward-only: **do not rely on `migrate:rollback` for recovery**.

## 3. Chronological gate ledger (what actually happened)

| Gate | Outcome | Durable result / issue |
|---|---|---|
| Initial H6 engineering/CI | PASS | Approved SHA merged; frontend, backend PG16 and Pint checks passed. No hosted deployment. |
| Hosted delta and PG10 preflight | PARTIAL | PG10.23 EOL; source constructs appeared PG10-compatible, but runtime migration not yet proven; production old / target new. |
| Hosted test credential setup | PASS, isolation **PARTIAL** | Operator created dedicated test role, test-only cPanel association, 0600 `.pgpass`; verified non-interactive `psql -w`. |
| Test metadata inventory | PASS for inventory | Existing test DB unexpectedly already had **32** migrations and H6 objects; unsuitable for fresh 27→32 proof without rebuilding. |
| Rehearsal plan | PARTIAL → resolved | Agreed **two DBs only**; operator confirmed old test rows disposable; no third database. |
| `H6_HOST_G1` | Initially BLOCKED, later auth PASS | No approved production read-only role; operator authenticated using existing production Admin credential from protected production `.env`. No credential printed to Codex. Verified 27 migration rows. |
| `H6_HOST_G2_EXPORT` | PASS | Production schema-only archive and `public.migrations` data-only archive, under one exported read-only snapshot; no application data exported. |
| `H6_HOST_G3_S1/S1R` | PARTIAL → READY | Prepared exact filtered PG10 restore TOC; owner and extension checks; old test backup requirement waived only after operator explicitly declared test data disposable. |
| `H6_HOST_G3_S2_RESTORATION` | PASS | Recreated **only** test DB; restored schema and migrations ledger at **27**, owners reset to Codex test role. Production unchanged. |
| `H6_HOST_G4_S1` | BLOCKED | Historical target object not in hosted repos; absent isolated runtime/environment. |
| `H6_HOST_G4_S1R` | READY | Isolated exact-SHA checkout; Composer 2.10.2 / PHP 8.2.34 / Laravel 12.67.0; testing-only runtime, UTC/test identity checks. |
| `H6_HOST_G4_S2` | PASS | **Actual 27→32 migration execution** on PG10.23, exit 0, expected catalog and ownership. |
| `H6_HOST_G4_S3` | PARTIAL | Classified 108 test files / 820 methods; discovered 12 hardcoded production-role references and PG10-specific SQLSTATE harness branch. |
| `H6_HOST_G4_S3R` | APPROVED | Independently reviewed 12-file test/support-only compatibility patch. |
| `H6_HOST_G4_S3E` | READY | Patched **overlay only** (not approved main), autoload/runtime set up; 32-migration database unchanged. |
| `H6_HOST_G4_S4A` | PARTIAL | 78 passed / 7 failed due missing Vite `public/build/manifest.json`; no DB failure. |
| `H6_HOST_G4_S4A_R1` | PASS | Locked npm install and Vite build; focused 11/11 route tests passed; **85/85 distinct read-only tests closed** across two runs. |
| `H6_HOST_G4_S4B1` | PARTIAL | Three TransactionManager tests leave sentinel rows without guaranteed after-test cleanup; wrapper proposed. |
| `H6_HOST_G4_S4B2` | PASS | 3/3 committed-write tests, explicit scoped cleanup, zero sentinel rows afterward, catalog unchanged. |
| `H6_HOST_G4_S4B3` | READY | 55 RefreshDatabase files / 471 methods; `migrate:fresh` runs once per PHPUnit process, destroying/rebuilding **test** schema. |
| `H6_HOST_G4_S4B3_E1` | PASS | First `ActiveUserAuthorizationTest.php`: 4/4, first `migrate:fresh` rebuilt all 32 migrations. |
| `H6_HOST_G4_S4B3_E2` | PARTIAL | 13/54 files and 81 methods passed PHPUnit; **12/13 post-file gates** passed; file 13 gate silent failure, stopped before files 14–54. |
| `H6_HOST_G4_S4B3_E2R1` | PARTIAL | Could not reconstruct exact historical predicate; confirmed `set -e` observability defect. |
| `H6_HOST_FINAL_CLOSURE_STAGE1` | REMEDIATION_REQUIRED | First wrapper patch did not emit actual/expected for command predicates. |
| `H6_HOST_FINAL_CLOSURE_R1` | READY | Complete 5-field predicate records implemented and validated offline. |
| `H6_HOST_FINAL_CLOSURE_R2` | APPROVED | Independent review passed; no blocker/major/minor findings. |
| **`H6_HOST_FINAL_CLOSURE_STAGE2`** | **PASS — CLOSED** | One authorized rerun of file 13: **8/8 PHPUnit; 23/23 immediate gates**, wrapper defect resolved, hosted-validation cycle closed. |

Do **not** interpret closure as permission to run the remaining hosted test suite or upgrade production.

## 4. Baseline artifacts, checksums and recovery provenance

**Immutable G2 source artifacts** (paths refer to the Namecheap host and are *not* stored in GitHub):

```text
/home/sewaellf/.educore-private-backups/h6-host-g2-5341uNxm/
  production-27-schema.dump
    bytes: 180539
    SHA256: cbc7c867dd074f6b3abfcf1398d8a49debdd93c3a1661a782bc470e83a0405eb
  production-27-migrations.dump
    bytes: 1465
    SHA256: 5c96b0ceb44979627cf4a7eb019b258ee7536e3647c054b4a8bf85aa42aa47b7
```

Obtained by `pg_dump 10.23` under one `REPEATABLE READ READ ONLY` exported snapshot. First archive contained **zero TABLE DATA entries**; second contained **only TABLE DATA public.migrations** and its sequence state. Count exactly **27** migration identifiers, no H6 target identifiers. Directory mode 0700, file mode 0600. Both archives were TOC-checked and **later actually restored successfully**, but they contain *no production application rows*. They are **not** a complete recoverable production backup.

Schema TOC: 306 entries. Precisely five source-specific entries disabled for test restore: source `DATABASE`, `SCHEMA public`, its comment, `EXTENSION plpgsql`, its comment. 301 retained entries. PostgreSQL 10's `pg_restore` lacked `--no-comments`, so explicit TOC filtering avoided extension owner/comment errors. `--no-owner --no-acl` ensured test objects belonged to `sewaellf_educore_Codex` after restore.

Other evidence:
```text
G3-S2 run:
  /home/sewaellf/.educore-private-backups/h6-host-g3-s2-zl3BBarq
  closure manifest SHA256 a29f5909f598019a602f1140c01cdb51597f54e380025626a6adbb016c4811e7
  restore list SHA256 a4be83f1c734252367318d5a3e727c2a3236c827dcbc323ce8b621dda624e98a

G4-S2 migrations:
  /home/sewaellf/.educore-rehearsal/h6-g4-target-pTB2ZTOv/runtime/logs/h6-g4-s2-AilnGU1C
  manifest SHA256 bf4f464de3e1e6bdef6e176af133fd012e8d1b6f5a7e3f002aa6a9e797b3cc33
  evidence index SHA256 26d740412c14529747084bfb90523621dc6c9290080005e83f6aadc513ff5321

G4-S3E isolated harness:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/evidence
  closure manifest SHA256 13fbce51debd1371b08fe42b051daaeecab3e5989ad8cd11b1e0e4938d75efcf

G4-S4A read-only:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/h6-g4-s4a-Q57VKt
G4-S4A Vite remediation:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/h6-g4-s4a-r1-coqLeN
G4-S4B2 committed-write:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/h6-g4-s4b2-g5xxUL6V
G4-S4B3 E1 canary:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/h6-g4-s4b3-e1-tRlzcEng
G4-S4B3 E2 halted run:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/h6-g4-s4b3-e2-YiDqJKBJ
Final Stage 2 closure evidence:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/h6-host-final-stage2-84dzFjlu/evidence
```

**Archival caution:** These are host-local artifact locations, not guaranteed future backups. Before future reuse, verify each exists, is readable, matches its checksum and is suitable for the then-current release. Do not upload secrets, `.env`, `.pgpass`, production row dumps, logs with tokens, or private backup archives to GitHub.

## 5. Database identity, permissions and surprising pitfalls

1. **Two-database architecture is deliberate.** Production `sewaellf_educore` + reusable/disposable test `sewaellf_educore_test`. No third DB is required for routine development. User expressly accepted loss of the old *test* rows before G3 recreation; that was **not** a license to delete production.
2. The dedicated test role was created manually in cPanel as `sewaellf_educore_Codex` and associated to the test DB only. `.pgpass` at `/home/sewaellf/.pgpass` (0600) enabled `psql -w` without password exposure. All credentials are out-of-repo.
3. **cPanel association is not a complete PostgreSQL access firewall.** The test role's `has_database_privilege(..., 'sewaellf_educore', 'CONNECT')` returned true via PostgreSQL's `PUBLIC` CONNECT default. It lacked production-database CREATE and was not a member of the known production Admin or owner roles. That does **not** prove absence of every possible production table privilege. Exact DB name, role, host and connection guards remain mandatory.
4. PostgreSQL `PUBLIC` is a pseudo-role, not an ordinary `pg_roles` entry. Calling `has_database_privilege('PUBLIC',...)` produced `ERROR: role "PUBLIC" does not exist`. Public grants were correctly audited via `aclexplode(COALESCE(datacl,acldefault(...)))` with `grantee=0`.
5. Initially the test role had broad grants but was **not the owner** of old test objects (`sewaellf_educore_Admin` owned them), and could not read five sequences. Rather than expand privileges for disposable test data, the test DB was recreated and the baseline restored `--no-owner --no-acl` **as the test role**. Subsequent owner checks passed.
6. Deleting/recreating the test DB required cPanel/UAPI identity guards, verification of expected DB owner `sewaellf_educore_test`, test-role membership, `public` schema (owner `postgres`, USAGE/CREATE for test role), `plpgsql` owner/location, `UTF8 / en_US.UTF-8`, and re-association of **both** existing test users without removing Admin's production association.
7. PostgreSQL 10 session timezone default was `America/New_York`. Test/dump/migration operations set `PGOPTIONS='-c timezone=UTC'`; some read-only checks set `default_transaction_read_only=on` as well. **Remove that read-only default for legitimately authorized write-test phases**; never use `read_only=off` as a reason to skip identity gates.
8. Avoid calling diagnostic PHP from the production repository directory: PHP CLI generated an untracked `error_log` earlier. It was preserved (not deleted) at `/home/sewaellf/.educore-private-backups/runtime/educore-php-cli-error-20261017.log` and the production worktree returned clean.

## 6. Runtime and frontend complications

- Hosted PHP `8.2.34`, Composer `2.10.2`, Laravel `12.67.0`; `pdo_pgsql` and `pgsql` available. Target lockfile matched and production platform requirements passed.
- Isolated target checkout was created from exact approved Git object outside the webroot. A testing-only process environment used array cache/session, sync queue, local isolated filesystem/logs, ephemeral APP_KEY, and explicit database identity checks. The source checkout and production `.env` were not reused.
- Twelve test/support files assumed role `sewaellf_educore_Admin` or PG16+ referential-integrity SQLSTATE behavior. An independently reviewed **test-only** patch changed exactly 12 files (+13/−12), preserving exact test role guards and the PG10 `23503` assertion, without modifying application or migration source.
  ```text
  patch:
  /home/sewaellf/.educore-rehearsal/h6-g4-target-pTB2ZTOv/runtime/logs/h6-g4-s3r-rwSii4cu/h6-g4-s3r-test-harness.patch
  SHA256 fd214f763d07c6a778e922898c3d22436bada3ef6fdaa450d6e670471a336c97
  ```
- Vite SPA tests initially failed 7/11 due absent `public/build/manifest.json` in isolated overlay; **not** a PG10 SQL error. `npm ci --ignore-scripts` honored the locked `package-lock.json` (SHA256 `d602ffb85ad321ca9debc59efff410154e3aeff4cafd00dc945e540182362e07`), then Vite 7.3.6 built 218 modules. Initial build hit Rust/Rayon `Resource temporarily unavailable`; retry with **process-only thread limits** succeeded. Generated manifest and five assets stayed in the overlay, no deployment. Manifest SHA256 `3479ba53870bc141333cddbbdd3c2613a6c2b55f232fa36af2bf0300d8b285a1`.
- Tests from `RefreshDatabase` call `migrate:fresh` once **per PHPUnit process**, destroying/rebuilding the test tables. Running 55 files individually would cause 55 such resets. It is not a safe activity on production, and schema rebuild testing is not the same as incremental migration testing.
- The accepted incremental post-G4-S2 catalog hash included migration batch information and **changed legitimately** after `migrate:fresh`. Later guards used stable migration-identifier and **schema-only** hashes instead:
  ```text
  Old 27 identifier hash:
    9f2b5fdf6cdf1967b0e97d9c24af4743fd986acb281bca4d3176f36ee9989fbd
  Post-fresh 32 identifier hash:
    6c4d623c04aa7f9b7db9f71d6456709732441d0ab06b64eb9237dadce884d039
  Stable post-fresh schema-only catalog:
    dd453a2fb8279b106cb38bb3289f2e66bdeaa9b35d08974efaf37517c4877e81
  G4-S2 incremental full fingerprint / pre-fresh:
    4e42d3a6f3eb9fa3a50e4958d8daab395a8d537d35a0a9c93ef7568e374390a2
  ```
  These hashes are for the historical frozen source, comparison algorithm and environment; recompute for future target revisions.

## 7. Detailed test result accounting and final verification incident

Frozen suite inventory (mutually exclusive): **108 files / 820 methods**:
- Read-only candidate group: 14 files / 85 methods.
- Committed-write TransactionManager group: 1 file / 3 methods.
- Transactional `RefreshDatabase`: 55 files / 471 methods.
- Destructive-reset non-concurrency: 22 files / 146 methods.
- Multiprocess concurrency/worker: 16 files / 115 methods.

Run outcomes:
- `S4A`: 78 passed initially; 7 SPA entry assertions failed solely because Vite manifest absent. Focused rerun of `FrontendEntryRoutesTest.php` yielded 11/11, final **85/85 distinct PASS** across two invocations.
- `S4B2`: 3/3 TransactionManager tests, 7 assertions; test suite inserted committed UUID sentinels in `public.users`. Source lacked after-test cleanup; a strictly UUID/email-scoped trap/wrapper cleaned the committed fixture, with dependent-row guards. Counts before/after 0/0, 0 sentinel rows.
- `S4B3 E1`: 4/4 `ActiveUserAuthorizationTest.php`, 7 assertions; exactly one `migrate:fresh`; 32 migration ledger and schema-only hash matched.
- `S4B3 E2`: 13 files executed / 81 methods / 455 assertions, **all PHPUnit PASS**. The first 12 files passed immediate verification. `AdminPracticeActivityApiTest.php` (file 13, 8 methods / 25 assertions) had a silent nonzero **immediate post-file gate**, so execution stopped before remaining 41 files.
- Later standalone read-only checks all passed: DB/role/UTC/32 migrations, stable schema hash, ownership (49/49 public tables+sequences; 48/48 functions), zero unrelated sessions, persistent rows only `education_stages=3` and `subjects=6`.
- Investigation proved an instrumentation/observability bug: `set -e` exited when a predicate returned nonzero **before logging its name**. The exact predicate was narrowed to a session count or source/patch equality check but **never established**. A race around the check was **plausible, not proven**. Do not claim a concrete transient DB race as root cause.
- First instrumentation patch lacked actual/expected values for command predicates and was rejected in independent review.
- Revised private-wrapper patch `a6bbebd4951d27e35e9a338a85f1076bad9ec84fa35f80e7964a7907448fc824` added records with `predicate, sanitized actual, expected, exit_status, PASS|FAIL`; offline fixtures and an independent review approved it. Original wrapper SHA256 `68214c8386648dc2391e40982d64395800f9a5e0b2d848a4d2956c867f5d3fc0`. No test/source/app code changes were needed.
  ```text
  revised patch:
  /home/sewaellf/.educore-rehearsal/h6-g4-s3e-FKiu0C/h6-host-final-r1-3g17r0mh/h6-host-final-closure-r1-wrapper.patch
  SHA256 a6bbebd4951d27e35e9a338a85f1076bad9ec84fa35f80e7964a7907448fc824
  ```
- `H6_HOST_FINAL_CLOSURE_STAGE2=PASS`: one authorized rerun of file 13: **8/8 tests, 25 assertions, one test-only `migrate:fresh`, all **23/23** immediate predicates PASS with full logs; database/ownership/UTC/cat checks passed; production never accessed. The historical predicate is **still unknown**.

Unique passing-method accounting: 85 + 3 + 4 + 81 = **173 / 820**. The file-13 rerun does not add eight different methods. Non-executed hosted cohorts: **41 RefreshDatabase files / 386 methods**, **22 destructive-reset files / 146 methods**, **16 concurrency files / 115 methods** = **647 unexecuted distinct methods**. No assertion that these cohorts pass on PG10 is justified. GitHub CI provides separate PG16 evidence.

## 8. Future production release: resume checklist (do not execute automatically)

**This is a handoff/checklist, not current execution authorization.** Treat each production-affecting step as a distinct gated release decision.

### Gate A — Reestablish current release facts
1. Decide release scope with product owner. Re-resolve actual GitHub `main` SHA/CI; historical SHA `d3210a9` may no longer be the desired release.
2. Inspect exact hosted deployed SHA, Git status, public entrypoint/symlink mapping and production app services without modifying them.
3. Re-inventory PostgreSQL server version, extension support, database size, schema migration ledger, locale/UTC, ownership, storage quota and active services with **operator-authorized read-only access**.
4. Recheck live application PHP/Composer/Node requirements and frontend artifact generation; never assume older archived `vendor` or `public/build` matches future code.
5. Preserve all evidence and re-verify original archive hashes if reuse is contemplated; do not confuse G2's **schema-only** pair with a full production database backup.

### Gate B — Resolve production blockers
6. **Upgrade/migrate off unsupported PostgreSQL 10.23** or explicitly obtain a documented, risk-accepted exception based on hosting/provider constraints. PG10 compatibility of five migrations does not constitute a supported production posture.
7. Create a **fresh, complete, secure production backup** including application rows, database globals/required roles, media/files and application configuration needed to recover service; preserve it outside webroot and validate checksums and access control.
8. Perform a genuinely successful **restore drill** and define RTO/RPO and rollback mechanism. No forward-only down-migration shortcut. If testing production rows outside production, enforce data-handling controls/anonymization and access isolation.
9. Resolve any database role ownership, grants and connection isolation issues for the actual deployment runtime. The test role must never become a production migration principal.
10. Check release compatibility on representative existing data, FK/history constraints, lock duration, long-running transactions and migration idempotency/replay. The previous empty-data structural rehearsal cannot answer these questions.
11. Decide how much of the previously **unexecuted PG10 hosted cohorts** is relevant to the release; do not casually demand another 820-test shared-host cycle. Prefer local and GitHub CI, with focused release-specific staging verification.

### Gate C — Deploy only when separately approved
12. Freeze exact release artifact (commit SHA, Composer lock, frontend assets, migration set), verify checksum and CI provenance.
13. Schedule downtime/maintenance window if required; secure fresh backup and rollback decision points before opening DB writes.
14. Verify production-only environment: `APP_ENV=production`, debug disabled, explicit production DB identity, UTC policy, secrets, cache/storage paths, queues, mail and web entrypoint. Ensure no testing `.pgpass` or patched test overlay is deployed.
15. Run release-specific preflight then migrations **only with explicit production authorization**; capture first error and STOP, never auto-run reset/rollback.
16. Deploy the immutable application/frontend artifact; run authenticated health checks and representative Admin/Teacher/Student journeys, monitor errors/workers and verify post-deploy schema/application status.
17. Obtain explicit operator acceptance before declaring `PRODUCTION_DEPLOYED` or retiring rollback materials.

**Prohibited without separate authorization:** deleting/recreating production DB, `migrate:fresh` on production, pulling production credentials into GitHub, blind copying test `.env`/overlay, using test-role grants on production, running outdated source against new production schema, automatic release promotion.

## 9. Deferred work and ownership of next steps

**Current engineering-cycle disposition:** Hosted validation closed *with bounded evidence*. It is neither full production qualification nor a promise that the old public instance has H6.

**Next active location:** `/home/s4d/gitHub/online/EduCore-codex` (local Ubuntu). Confirm repo SHA, branch/cleanliness, isolated local PostgreSQL and Laravel/Vite operation before continuing the *approved product roadmap* after H6. Keep GitHub CI as normal engineering gate. No repeated Namecheap/localhost switching while developing.

**When publication becomes a real milestone:** reopen **this document**, inspect exact current release state, perform the Gate A/B/C checks above, and prepare a **new bounded release approval**. Do not restart the old G1→G4 task chain from scratch unless environment or code changes invalidate its evidence.

## 10. Recorded closure statement

```ini
H6_HOST_FINAL_CLOSURE_STAGE2=PASS
HOSTED_VALIDATION=CLOSED_FOR_CURRENT_ENGINEERING_CYCLE
WRAPPER_OBSERVABILITY_DEFECT=RESOLVED
HISTORICAL_FAILED_PREDICATE=UNKNOWN
HOSTED_UNIQUE_TESTS_PASS=173/820
HOSTED_REMAINING_UNEXECUTED=647
TEST_DB_MIGRATIONS_AT_CLOSURE=32
PRODUCTION_DB_UPGRADED=no
DEPLOYMENT_PERFORMED=no
POSTGRESQL_10_EOL_RISK=OPEN
ACTIVE_DEVELOPMENT_LOCATION=LOCAL_UBUNTU
PRODUCTION_RELEASE_AUTHORIZATION=NONE
```

*Documentation change only. This record contains no passwords, credentials, `.env` contents, actual production application rows or binary backups.*

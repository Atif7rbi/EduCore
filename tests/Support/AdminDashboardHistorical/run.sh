#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

readonly HISTORICAL_SHA="e3f42f3b17b8f3e38881710289fac2944a3e89bd"
readonly CURRENT_BASELINE_SHA="0494a0666d7beebe225d7687a29d29f2ea519a4d"
readonly EXPECTED_DATABASE="educore_historical_test"
readonly EXPECTED_ROLE="educore_historical_app"
readonly ROOT="$(git rev-parse --show-toplevel)"
readonly SUPPORT_DIR="$ROOT/tests/Support/AdminDashboardHistorical"

DATABASE_HOST="${EDUCORE_HISTORICAL_DATABASE_HOST:-}"
DATABASE_PORT="${EDUCORE_HISTORICAL_DATABASE_PORT:-}"
DATABASE_NAME="${EDUCORE_HISTORICAL_DATABASE:-}"
DATABASE_ROLE="${EDUCORE_HISTORICAL_ROLE:-}"
DATABASE_PASSWORD="${EDUCORE_HISTORICAL_PASSWORD:-}"
EXPECTED_CURRENT_SHA="${EDUCORE_HISTORICAL_EXPECTED_CURRENT_SHA:-}"
RUN_DIRECTORY=""
HISTORICAL_CHECKOUT=""
FIXTURE_NAME=""
APPLICATION_KEY=""

fail()
{
    printf 'Historical Dashboard regression infrastructure error: %s\n' "$*" >&2
    exit 1
}

require_command()
{
    command -v "$1" >/dev/null 2>&1 \
        || fail "required command is unavailable: $1"
}

require_environment()
{
    local name="$1"

    [[ -n "${!name:-}" ]] \
        || fail "required environment is unset: $name"
}

application_psql()
{
    PGPASSWORD="$DATABASE_PASSWORD" \
    PGCONNECT_TIMEOUT=5 \
    PGOPTIONS="-c statement_timeout=15000 -c lock_timeout=5000 -c timezone=UTC" \
        timeout 20s psql \
        -X \
        -v ON_ERROR_STOP=1 \
        -h "$DATABASE_HOST" \
        -p "$DATABASE_PORT" \
        -U "$DATABASE_ROLE" \
        -d "$DATABASE_NAME" \
        "$@"
}

run_application()
{
    local checkout="$1"

    shift

    (
        cd "$checkout"
        env \
            APP_ENV=testing \
            APP_DEBUG=false \
            APP_KEY="$APPLICATION_KEY" \
            APP_URL=http://localhost \
            CACHE_STORE=array \
            SESSION_DRIVER=array \
            QUEUE_CONNECTION=sync \
            MAIL_MAILER=array \
            DB_CONNECTION=pgsql \
            DB_HOST="$DATABASE_HOST" \
            DB_PORT="$DATABASE_PORT" \
            DB_DATABASE="$DATABASE_NAME" \
            DB_USERNAME="$DATABASE_ROLE" \
            DB_PASSWORD="$DATABASE_PASSWORD" \
            DB_URL= \
            DB_SSLMODE=disable \
            TZ=UTC \
            PGOPTIONS="-c timezone=UTC -c statement_timeout=15000 -c lock_timeout=5000" \
            EDUCORE_HISTORICAL_EPHEMERAL_CI=1 \
            EDUCORE_HISTORICAL_DATABASE="$DATABASE_NAME" \
            EDUCORE_HISTORICAL_ROLE="$DATABASE_ROLE" \
            EDUCORE_HISTORICAL_FIXTURE_NAME="$FIXTURE_NAME" \
            "$@"
    )
}

verify_ci_environment()
{
    local root_real
    local runner_temp_real
    local workspace_real

    [[ "${GITHUB_ACTIONS:-}" == "true" ]] \
        || fail "this harness is restricted to GitHub Actions"
    [[ "${EDUCORE_HISTORICAL_RUNNER_ENVIRONMENT:-}" == "github-hosted" ]] \
        || fail "this harness requires a GitHub-hosted runner"
    require_environment GITHUB_SHA
    require_environment GITHUB_WORKSPACE
    require_environment RUNNER_TEMP

    [[ -d "$GITHUB_WORKSPACE" && ! -L "$GITHUB_WORKSPACE" ]] \
        || fail "GitHub workspace must be a regular directory"
    [[ -d "$RUNNER_TEMP" && ! -L "$RUNNER_TEMP" ]] \
        || fail "runner temporary root must be a regular directory"

    root_real="$(realpath -e "$ROOT")"
    workspace_real="$(realpath -e "$GITHUB_WORKSPACE")"
    runner_temp_real="$(realpath -e "$RUNNER_TEMP")"

    [[ "$workspace_real" == "$root_real" ]] \
        || fail "active source is not the exact GitHub workspace"
    [[ "$runner_temp_real" != "$root_real" ]] \
        || fail "runner temporary root aliases the source checkout"

    case "$runner_temp_real/" in
        "$root_real/"*)
            fail "runner temporary root is inside the active source checkout"
            ;;
    esac
}

verify_configuration()
{
    require_environment EDUCORE_HISTORICAL_DATABASE_HOST
    require_environment EDUCORE_HISTORICAL_DATABASE_PORT
    require_environment EDUCORE_HISTORICAL_DATABASE
    require_environment EDUCORE_HISTORICAL_ROLE
    require_environment EDUCORE_HISTORICAL_PASSWORD
    require_environment EDUCORE_HISTORICAL_EXPECTED_CURRENT_SHA

    [[ "$DATABASE_HOST" == "127.0.0.1" ]] \
        || fail "database host must be the job-local loopback address"
    [[ "$DATABASE_PORT" =~ ^[0-9]+$ ]] \
        || fail "database port is invalid"
    ((DATABASE_PORT >= 1 && DATABASE_PORT <= 65535)) \
        || fail "database port is outside the valid range"
    [[ "$DATABASE_NAME" == "$EXPECTED_DATABASE" ]] \
        || fail "ephemeral database identity mismatch"
    [[ "$DATABASE_ROLE" == "$EXPECTED_ROLE" ]] \
        || fail "ephemeral application role identity mismatch"
    [[ "$EXPECTED_CURRENT_SHA" =~ ^[0-9a-f]{40}$ ]] \
        || fail "expected current source SHA is invalid"
}

verify_revisions()
{
    local resolved_baseline
    local resolved_historical
    local source_sha

    resolved_historical="$(git -C "$ROOT" rev-parse "$HISTORICAL_SHA^{commit}")"
    resolved_baseline="$(git -C "$ROOT" rev-parse "$CURRENT_BASELINE_SHA^{commit}")"
    source_sha="$(git -C "$ROOT" rev-parse HEAD)"

    [[ "$resolved_historical" == "$HISTORICAL_SHA" ]] \
        || fail "historical SHA resolution mismatch"
    [[ "$resolved_baseline" == "$CURRENT_BASELINE_SHA" ]] \
        || fail "approved baseline SHA resolution mismatch"
    [[ "$source_sha" == "$EXPECTED_CURRENT_SHA" ]] \
        || fail "current checkout does not match the expected submitted revision"
    [[ "$source_sha" == "$GITHUB_SHA" ]] \
        || fail "current checkout does not match GITHUB_SHA"
    git -C "$ROOT" merge-base --is-ancestor \
        "$CURRENT_BASELINE_SHA" "$source_sha" \
        || fail "current source does not descend from the approved baseline"
    git -C "$ROOT" diff --quiet \
        "$CURRENT_BASELINE_SHA" "$source_sha" \
        -- database/migrations \
        || fail "production migrations differ from the approved baseline"
    git -C "$ROOT" cat-file -e \
        "$source_sha:app/Application/AdminDashboard/ReadAdminDashboardSummary.php" \
        || fail "current source lacks the reviewed Dashboard summary"
    git -C "$ROOT" cat-file -e \
        "$source_sha:tests/Integration/AdminDashboardHistoricalDatabaseTest.php" \
        || fail "current source lacks the historical integration test"
}

initialize_private_directory()
{
    local owner
    local mode
    local runner_temp_real
    local run_real

    runner_temp_real="$(realpath -e "$RUNNER_TEMP")"
    RUN_DIRECTORY="$(mktemp -d "$runner_temp_real/educore-historical-dashboard.XXXXXX")"
    chmod 0700 "$RUN_DIRECTORY"

    owner="$(stat -c '%u' "$RUN_DIRECTORY")"
    mode="$(stat -c '%a' "$RUN_DIRECTORY")"
    run_real="$(realpath -e "$RUN_DIRECTORY")"

    [[ "$owner" == "$(id -u)" && "$mode" == "700" ]] \
        || fail "job-private directory ownership or mode is unsafe"
    case "$run_real/" in
        "$runner_temp_real/"*) ;;
        *) fail "job-private directory escaped RUNNER_TEMP" ;;
    esac

    HISTORICAL_CHECKOUT="$RUN_DIRECTORY/historical-source"
}

verify_initial_database()
{
    local identity
    local database
    local role
    local owner
    local timezone
    local version
    local superuser
    local createdb
    local createrole
    local login
    local relation_count
    local migrations_absent

    identity="$(application_psql -qAt -F '|' <<'SQL'
SELECT
    current_database(),
    current_user,
    pg_get_userbyid(database.datdba),
    current_setting('TimeZone'),
    current_setting('server_version_num'),
    role.rolsuper,
    role.rolcreatedb,
    role.rolcreaterole,
    role.rolcanlogin,
    (
        SELECT count(*)
        FROM pg_catalog.pg_class AS relation
        JOIN pg_catalog.pg_namespace AS namespace
          ON namespace.oid = relation.relnamespace
        WHERE namespace.nspname = 'public'
          AND relation.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')
    ),
    to_regclass('public.migrations') IS NULL
FROM pg_catalog.pg_database AS database
JOIN pg_catalog.pg_roles AS role
  ON role.rolname = current_user
WHERE database.datname = current_database();
SQL
)"

    IFS='|' read -r \
        database role owner timezone version superuser createdb createrole \
        login relation_count migrations_absent <<<"$identity"

    [[ "$database" == "$EXPECTED_DATABASE" ]] \
        || fail "connected database identity mismatch"
    [[ "$role" == "$EXPECTED_ROLE" && "$owner" == "$EXPECTED_ROLE" ]] \
        || fail "application role or database ownership mismatch"
    [[ "$timezone" == "UTC" ]] \
        || fail "database session timezone is not UTC"
    [[ "$version" =~ ^16[0-9]{4}$ ]] \
        || fail "PostgreSQL major version is not 16"
    [[ "$superuser" == "f" && "$createdb" == "f" && "$createrole" == "f" ]] \
        || fail "application role has forbidden PostgreSQL privileges"
    [[ "$login" == "t" ]] \
        || fail "application role cannot log in"
    [[ "$relation_count" == "0" && "$migrations_absent" == "t" ]] \
        || fail "ephemeral application schema is not empty"
}

create_historical_clone()
{
    git clone \
        --no-local \
        --no-hardlinks \
        --no-checkout \
        "$ROOT" \
        "$HISTORICAL_CHECKOUT"
    git -C "$HISTORICAL_CHECKOUT" checkout --detach "$HISTORICAL_SHA"

    [[ -d "$HISTORICAL_CHECKOUT/.git" && ! -L "$HISTORICAL_CHECKOUT/.git" ]] \
        || fail "historical source is not an isolated ordinary clone"
    [[ "$(git -C "$HISTORICAL_CHECKOUT" rev-parse HEAD)" == "$HISTORICAL_SHA" ]] \
        || fail "historical clone is not detached at the approved SHA"

    case "$(realpath -e "$HISTORICAL_CHECKOUT")/" in
        "$(realpath -e "$RUN_DIRECTORY")/"*) ;;
        *) fail "historical clone escaped the job-private directory" ;;
    esac
}

prepare_historical_checkout()
{
    cp -a --reflink=auto "$ROOT/vendor" "$HISTORICAL_CHECKOUT/vendor"
    mkdir -p \
        "$HISTORICAL_CHECKOUT/bootstrap/cache" \
        "$HISTORICAL_CHECKOUT/storage/framework/cache/data" \
        "$HISTORICAL_CHECKOUT/storage/framework/sessions" \
        "$HISTORICAL_CHECKOUT/storage/framework/views" \
        "$HISTORICAL_CHECKOUT/storage/logs"
    timeout 120s composer dump-autoload \
        --working-dir="$HISTORICAL_CHECKOUT" \
        --no-interaction \
        --no-scripts \
        --quiet
    run_application "$HISTORICAL_CHECKOUT" \
        timeout 60s php artisan package:discover --no-ansi
}

verify_migration_count()
{
    local expected="$1"
    local actual

    actual="$(application_psql -qAt -c 'SELECT count(*) FROM migrations;')"
    [[ "$actual" == "$expected" ]] \
        || fail "expected $expected migrations, observed $actual"
}

verify_historical_fixture()
{
    local record

    record="$(application_psql -v fixture_name="$FIXTURE_NAME" -qAt -F '|' <<'SQL'
SELECT
    curriculum.teacher_subject_assignment_id IS NULL,
    version.status,
    lesson.status,
    practice.status,
    template_version.status
FROM curricula AS curriculum
JOIN curriculum_versions AS version
  ON version.curriculum_id = curriculum.id
JOIN lessons AS lesson
  ON lesson.curriculum_version_id = version.id
JOIN practice_activities AS practice
  ON practice.curriculum_version_id = version.id
JOIN exam_templates AS template
  ON template.curriculum_version_id = version.id
JOIN exam_template_versions AS template_version
  ON template_version.exam_template_id = template.id
WHERE curriculum.name = :'fixture_name';
SQL
)"

    [[ "$record" == "t|published|published|active|published" ]] \
        || fail "historical publication workflow did not persist expected state"
}

verify_forward_integrity()
{
    local trigger_count
    local preserved
    local subject_id
    local rejected_id
    local rejection_log="$RUN_DIRECTORY/ownerless-rejection.log"
    local status

    preserved="$(application_psql -v fixture_name="$FIXTURE_NAME" -qAt -F '|' <<'SQL'
SELECT
    curriculum.teacher_subject_assignment_id IS NULL,
    version.status
FROM curricula AS curriculum
JOIN curriculum_versions AS version
  ON version.curriculum_id = curriculum.id
WHERE curriculum.name = :'fixture_name';
SQL
)"
    [[ "$preserved" == "t|published" ]] \
        || fail "forward migrations did not preserve historical ownership/status"

    trigger_count="$(application_psql -qAt <<'SQL'
SELECT count(*)
FROM pg_catalog.pg_trigger
WHERE tgname = 'trg_curricula_ownership_integrity'
  AND tgenabled = 'O'
  AND NOT tgisinternal;
SQL
)"
    [[ "$trigger_count" == "1" ]] \
        || fail "current Curriculum ownership trigger is not enabled"

    subject_id="$(application_psql -qAt \
        -c "SELECT id FROM subjects WHERE code = 'mathematics';")"
    rejected_id="$(php -r '
        $hex = bin2hex(random_bytes(16));
        printf(
            "%s-%s-%s-%s-%s",
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    ')"

    set +e
    application_psql \
        -v rejected_id="$rejected_id" \
        -v subject_id="$subject_id" \
        -v rejected_name="Rejected Ownerless $DATABASE_NAME" \
        -qAt <<'SQL' >"$rejection_log" 2>&1
INSERT INTO curricula (
    id,
    subject_id,
    name,
    teacher_subject_assignment_id,
    created_at,
    updated_at
)
VALUES (
    :'rejected_id',
    :'subject_id',
    :'rejected_name',
    NULL,
    transaction_timestamp(),
    transaction_timestamp()
);
SQL
    status=$?
    set -e

    [[ "$status" -ne 0 ]] \
        || fail "current ownership trigger accepted a new ownerless Curriculum"
    grep -Fq 'chk_curricula_owner_required' "$rejection_log" \
        || fail "ownerless rejection did not identify the ownership constraint"
    [[ "$(application_psql \
        -v rejected_name="Rejected Ownerless $DATABASE_NAME" \
        -qAt \
        -c "SELECT count(*) FROM curricula WHERE name = :'rejected_name';")" == "0" ]] \
        || fail "rejected ownerless fixture left persistent residue"
}

main()
{
    local command

    [[ "$#" -eq 0 ]] || fail "this CI-only harness accepts no arguments"

    for command in \
        composer cp git grep id mktemp php psql realpath stat timeout; do
        require_command "$command"
    done

    php -m | grep -Fxq pdo_pgsql \
        || fail "PHP extension pdo_pgsql is unavailable"
    php -m | grep -Fxq pgsql \
        || fail "PHP extension pgsql is unavailable"
    [[ -d "$ROOT/vendor" ]] \
        || fail "Composer dependencies must be installed before this runner executes"

    verify_ci_environment
    verify_configuration
    verify_revisions
    initialize_private_directory

    FIXTURE_NAME="Historical Ownerless Curriculum $EXPECTED_CURRENT_SHA"
    APPLICATION_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"

    verify_initial_database
    create_historical_clone
    prepare_historical_checkout

    run_application "$HISTORICAL_CHECKOUT" \
        timeout 180s php artisan migrate --force --no-interaction --no-ansi
    verify_migration_count 30

    php "$SUPPORT_DIR/prepare-historical-test.php" \
        "$HISTORICAL_CHECKOUT/tests/Feature/AdminContentPublishingFlowTest.php"
    run_application "$HISTORICAL_CHECKOUT" \
        timeout 300s php vendor/bin/phpunit \
        --no-configuration \
        --bootstrap vendor/autoload.php \
        tests/Feature/AdminContentPublishingFlowTest.php
    verify_historical_fixture

    run_application "$ROOT" \
        timeout 180s php artisan migrate --force --no-interaction --no-ansi
    verify_migration_count 32
    verify_forward_integrity

    run_application "$ROOT" \
        timeout 300s php vendor/bin/phpunit \
        --no-configuration \
        --bootstrap vendor/autoload.php \
        tests/Integration/AdminDashboardHistoricalDatabaseTest.php

    printf 'Historical Dashboard regression completed successfully.\n'
    printf 'GitHub Actions will dispose of the private filesystem and PostgreSQL service.\n'
}

main "$@"

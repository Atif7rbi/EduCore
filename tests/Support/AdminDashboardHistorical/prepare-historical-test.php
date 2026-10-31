<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(
        STDERR,
        "Usage: php prepare-historical-test.php <historical-test-path>\n"
    );
    exit(64);
}

$path = $argv[1];
$expectedHash =
    '692b1e9edc6ab380ba76b7f286f9043b19802494365665bfbab8ed0495f09cb4';

if (! is_file($path) || is_link($path)) {
    throw new RuntimeException(
        'Historical fixture source must be a regular, non-symlink file.'
    );
}

$source = file_get_contents($path);

if (! is_string($source) || hash('sha256', $source) !== $expectedHash) {
    throw new RuntimeException(
        'Historical fixture source does not match the approved historical SHA.'
    );
}

$source = replaceOnce(
    $source,
    "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\n",
    ''
);
$source = replaceOnce(
    $source,
    "    use RefreshDatabase;\n\n",
    ''
);

$startMarker = '        $subjectId = (string) Str::uuid();';
$endMarker = "        DB::table('topics')->insert([";

$start = strpos($source, $startMarker);
$end = strpos($source, $endMarker);

if (
    $start === false
    || $end === false
    || $end <= $start
    || strpos($source, $startMarker, $start + 1) !== false
    || strpos($source, $endMarker, $end + 1) !== false
) {
    throw new RuntimeException(
        'Historical fixture construction markers are not exact and unique.'
    );
}

$replacement = <<<'PHP'
        $fixtureName = getenv('EDUCORE_HISTORICAL_FIXTURE_NAME');
        $expectedDatabase = getenv('EDUCORE_HISTORICAL_DATABASE');
        $expectedRole = getenv('EDUCORE_HISTORICAL_ROLE');

        if (
            ! is_string($fixtureName)
            || $fixtureName === ''
            || ! is_string($expectedDatabase)
            || $expectedDatabase === ''
            || ! is_string($expectedRole)
            || $expectedRole === ''
        ) {
            throw new RuntimeException(
                'Historical runner environment is incomplete.'
            );
        }

        $identity = DB::selectOne(
            <<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user,
    current_setting('TimeZone') AS timezone
SQL
        );

        if (
            ($identity->database_name ?? null) !== $expectedDatabase
            || ($identity->database_user ?? null) !== $expectedRole
            || ($identity->timezone ?? null) !== 'UTC'
            || DB::table('migrations')->count() !== 30
        ) {
            throw new RuntimeException(
                'Historical database identity, role, UTC, or migration ledger mismatch.'
            );
        }

        $subjectId = DB::table('subjects')
            ->where('code', 'mathematics')
            ->where('status', 'active')
            ->soleValue('id');

        $curriculum = $this->postJson(
            "/api/admin/subjects/{$subjectId}/curricula",
            ['name' => $fixtureName]
        )->assertCreated();

        $curriculumId = (string) $curriculum->json('data.id');

        $version = $this->postJson(
            "/api/admin/curricula/{$curriculumId}/versions",
            [
                'version_number' => 1,
                'label' => 'Historical Ownerless v1',
            ]
        )->assertCreated();

        $curriculumVersionId =
            (string) $version->json('data.id');
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();

PHP;

$source = substr($source, 0, $start)
    .$replacement
    .substr($source, $end);

$temp = $path.'.educore-historical-'.bin2hex(random_bytes(6));

try {
    $bytes = file_put_contents($temp, $source, LOCK_EX);

    if ($bytes !== strlen($source)) {
        throw new RuntimeException(
            'Historical fixture test preparation was incomplete.'
        );
    }

    if (! chmod($temp, 0600)) {
        throw new RuntimeException(
            'Historical fixture test permissions could not be secured.'
        );
    }

    if (! rename($temp, $path)) {
        throw new RuntimeException(
            'Historical fixture test could not be atomically installed.'
        );
    }
} finally {
    if (is_file($temp) && ! unlink($temp)) {
        throw new RuntimeException(
            'Historical fixture temporary file cleanup failed.'
        );
    }
}

function replaceOnce(string $source, string $needle, string $replacement): string
{
    if (
        substr_count($source, $needle) !== 1
    ) {
        throw new RuntimeException(
            'Historical fixture source replacement was not exact and unique.'
        );
    }

    return str_replace($needle, $replacement, $source);
}

<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDashboardHistoricalDatabaseTest extends TestCase
{
    private string $databaseName;

    private string $databaseRole;

    private string $fixtureName;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'array']);

        $this->assertSame(
            '1',
            $this->requiredEnvironment(
                'EDUCORE_HISTORICAL_EPHEMERAL_CI'
            )
        );

        $this->databaseName = $this->requiredEnvironment(
            'EDUCORE_HISTORICAL_DATABASE'
        );
        $this->databaseRole = $this->requiredEnvironment(
            'EDUCORE_HISTORICAL_ROLE'
        );
        $this->fixtureName = $this->requiredEnvironment(
            'EDUCORE_HISTORICAL_FIXTURE_NAME'
        );

        $identity = DB::selectOne(<<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user,
    current_setting('TimeZone') AS timezone,
    current_setting('server_version_num')::integer AS server_version_num
SQL);

        $this->assertSame(
            $this->databaseName,
            $identity->database_name ?? null
        );
        $this->assertSame(
            $this->databaseRole,
            $identity->database_user ?? null
        );
        $this->assertSame('UTC', $identity->timezone ?? null);
        $this->assertGreaterThanOrEqual(
            160000,
            (int) ($identity->server_version_num ?? 0)
        );
        $this->assertLessThan(
            170000,
            (int) ($identity->server_version_num ?? 0)
        );
        $this->assertSame(32, DB::table('migrations')->count());
        $this->assertSame(0, DB::connection()->transactionLevel());

        $role = DB::selectOne(
            <<<'SQL'
SELECT
    rolsuper::integer AS rolsuper,
    rolcreatedb::integer AS rolcreatedb,
    rolcreaterole::integer AS rolcreaterole
FROM pg_catalog.pg_roles
WHERE rolname = ?
SQL,
            [$this->databaseRole]
        );

        $this->assertNotNull($role);
        $this->assertSame(0, (int) $role->rolsuper);
        $this->assertSame(0, (int) $role->rolcreatedb);
        $this->assertSame(0, (int) $role->rolcreaterole);
    }

    public function test_production_dashboard_reads_preserved_historical_population(): void
    {
        $historical = DB::table('curricula as curriculum')
            ->join(
                'curriculum_versions as version',
                'version.curriculum_id',
                '=',
                'curriculum.id'
            )
            ->where('curriculum.name', $this->fixtureName)
            ->select([
                'curriculum.teacher_subject_assignment_id',
                'version.status',
            ])
            ->sole();

        $this->assertNull($historical->teacher_subject_assignment_id);
        $this->assertSame('published', $historical->status);
        $this->assertSame(
            1,
            DB::table('pg_catalog.pg_trigger')
                ->where(
                    'tgname',
                    'trg_curricula_ownership_integrity'
                )
                ->where('tgenabled', 'O')
                ->count()
        );

        $admin = User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->firstOrFail();
        $before = $this->tableCounts();
        $queries = [];

        DB::listen(
            static function (QueryExecuted $query) use (&$queries): void {
                $queries[] = strtolower((string) preg_replace(
                    '/\s+/',
                    ' ',
                    trim($query->sql)
                ));
            }
        );

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.counts.curricula', 1)
            ->assertJsonPath('data.counts.teacher_owned_curricula', 0)
            ->assertJsonPath('data.counts.legacy_ownerless_curricula', 1)
            ->assertJsonPath('data.counts.curriculum_versions', 1)
            ->assertJsonPath(
                'data.readiness.published_curriculum_versions',
                1
            )
            ->assertJsonPath(
                'data.readiness.published_teacher_owned_curriculum_versions',
                0
            )
            ->assertJsonPath(
                'data.readiness.published_legacy_ownerless_curriculum_versions',
                1
            )
            ->assertJsonPath('data.readiness.published_lessons', 1)
            ->assertJsonPath(
                'data.readiness.active_practice_activities',
                1
            )
            ->assertJsonPath('data.readiness.active_exam_templates', 1)
            ->assertJsonPath(
                'data.operations.teacher_accounts',
                ['active' => 0, 'disabled' => 0, 'total' => 0]
            )
            ->assertJsonPath(
                'data.operations.student_accounts',
                ['active' => 1, 'disabled' => 0, 'total' => 1]
            )
            ->assertJsonPath(
                'data.operations.teacher_subject_assignments',
                ['active' => 0, 'inactive' => 0, 'total' => 0]
            )
            ->assertJsonPath(
                'data.operations.student_enrollments',
                [
                    'pending' => 0,
                    'active' => 0,
                    'inactive' => 0,
                    'total' => 0,
                ]
            );

        $published = (int) $response->json(
            'data.readiness.published_curriculum_versions'
        );
        $teacherOwned = (int) $response->json(
            'data.readiness.published_teacher_owned_curriculum_versions'
        );
        $legacy = (int) $response->json(
            'data.readiness.published_legacy_ownerless_curriculum_versions'
        );

        $this->assertSame($published, $teacherOwned + $legacy);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            (string) $response->json('data.snapshot_at')
        );
        $this->assertTrue($this->containsQuery(
            $queries,
            'set transaction isolation level repeatable read, read only'
        ));
        $this->assertTrue($this->containsQuery(
            $queries,
            "current_setting( 'transaction_isolation' )"
        ));
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame($before, $this->tableCounts());
    }

    private function requiredEnvironment(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException(
                "Historical regression runner did not provide {$name}."
            );
        }

        return $value;
    }

    private function containsQuery(array $queries, string $needle): bool
    {
        foreach ($queries as $query) {
            if (str_contains($query, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function tableCounts(): array
    {
        $tables = [
            'users',
            'learner_profiles',
            'curricula',
            'curriculum_versions',
            'topics',
            'lessons',
            'assessment_items',
            'practice_activities',
            'exam_templates',
            'teacher_subject_assignments',
            'student_enrollments',
        ];

        return array_combine(
            $tables,
            array_map(
                static fn (string $table): int => DB::table($table)->count(),
                $tables
            )
        );
    }
}

<?php

namespace Tests\Feature;

use App\Application\Production\DatabaseReadinessCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseReadinessCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_check_is_postgres_backed(): void
    {
        $this->assertSame(
            'pgsql',
            DB::connection()->getDriverName()
        );
    }

    public function test_required_infrastructure_tables_exist(): void
    {
        $tables = collect(
            DB::select(
                <<<'SQL'
SELECT tablename
FROM pg_catalog.pg_tables
WHERE schemaname = 'public'
SQL
            )
        )->pluck('tablename');

        foreach (
            [
                'migrations',
                'users',
                'learner_profiles',
                'cache',
                'cache_locks',
                'jobs',
                'job_batches',
                'failed_jobs',
            ]
            as $table
        ) {
            $this->assertTrue(
                $tables->contains($table),
                "Expected table [{$table}] to exist."
            );
        }
    }

    public function test_server_version_can_be_read_using_postgres_contract(): void
    {
        $row = DB::selectOne(
            <<<'SQL'
SELECT
    current_setting('server_version') AS server_version,
    current_setting('server_version_num')::integer AS server_version_num
SQL
        );

        $this->assertNotSame(
            '',
            (string) $row->server_version
        );

        $this->assertGreaterThan(
            0,
            (int) $row->server_version_num
        );
    }

    public function test_minimum_production_postgres_major_is_explicit(): void
    {
        $this->assertSame(
            14,
            DatabaseReadinessCheck::MINIMUM_POSTGRES_MAJOR
        );
    }
}

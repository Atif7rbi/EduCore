<?php

namespace Tests\Feature;

use App\Application\Production\DatabaseReadinessCheck;
use App\Application\Production\ProductionReadinessAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionReadinessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_backend_implementation_readiness(): void
    {
        $result = app(
            ProductionReadinessAudit::class
        )->inspect();

        $this->assertTrue(
            $result[
                'implementation_ready'
            ]
        );

        $this->assertTrue(
            $result[
                'smoke'
            ]['ok']
        );

        $this->assertSame(
            'pgsql',
            $result[
                'smoke'
            ][
                'database_driver'
            ]
        );
    }

    public function test_audit_classifies_database_against_explicit_production_minimum(): void
    {
        $result = app(
            ProductionReadinessAudit::class
        )->inspect();

        $versionNum =
            (int) DB::selectOne(
                <<<'SQL'
SELECT
    current_setting('server_version_num')::integer AS server_version_num
SQL
            )->server_version_num;

        $major =
            intdiv(
                $versionNum,
                10000
            );

        if (
            $major
            < DatabaseReadinessCheck::MINIMUM_POSTGRES_MAJOR
        ) {
            $this->assertFalse(
                $result[
                    'database'
                ]['ok']
            );

            $this->assertFalse(
                $result[
                    'deployment_ready'
                ]
            );

            $this->assertContains(
                'Production database readiness failed.',
                $result[
                    'blockers'
                ]
            );

            return;
        }

        $this->assertTrue(
            $result[
                'database'
            ]['ok']
        );
    }

    public function test_nonproduction_audit_does_not_pretend_to_validate_production_environment_values(): void
    {
        $result = app(
            ProductionReadinessAudit::class
        )->inspect();

        $this->assertSame(
            'testing',
            $result[
                'environment'
            ][
                'app_environment'
            ]
        );

        $this->assertFalse(
            $result[
                'environment'
            ][
                'production_contract_checked'
            ]
        );

        $this->assertNull(
            $result[
                'environment'
            ][
                'production_contract_ok'
            ]
        );

        /*
         * Non-production execution may prove implementation
         * health, but it can never authorize deployment.
         */
        $this->assertFalse(
            $result[
                'deployment_ready'
            ]
        );
    }
}

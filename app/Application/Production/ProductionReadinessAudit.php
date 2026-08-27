<?php

namespace App\Application\Production;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductionReadinessAudit
{
    public function __construct(
        private readonly ProductionEnvironmentContract $environment,
        private readonly DatabaseReadinessCheck $database,
        private readonly ProductionSmokeCheck $smoke,
    ) {
    }

    /**
     * @return array{
     *     implementation_ready: bool,
     *     deployment_ready: bool,
     *     environment: array<string, mixed>,
     *     database: array<string, mixed>,
     *     smoke: array<string, mixed>,
     *     blockers: array<int, string>
     * }
     */
    public function inspect(): array
    {
        $blockers = [];

        $environmentResult = [
            'app_environment' =>
                app()->environment(),
            'production_contract_checked' =>
                false,
            'production_contract_ok' =>
                null,
        ];

        if (app()->environment('production')) {
            $environmentResult[
                'production_contract_checked'
            ] = true;

            try {
                $this->environment->validate();

                $environmentResult[
                    'production_contract_ok'
                ] = true;
            } catch (RuntimeException $exception) {
                $environmentResult[
                    'production_contract_ok'
                ] = false;

                $environmentResult[
                    'message'
                ] = $exception->getMessage();

                $blockers[] =
                    'Production environment contract failed.';
            }
        }

        $databaseResult = [
            'ok' => false,
            'server_version' => null,
            'minimum_supported_major' =>
                DatabaseReadinessCheck::MINIMUM_POSTGRES_MAJOR,
        ];

        try {
            $databaseInspection =
                $this->database->inspect();

            $databaseResult = [
                'ok' => true,
                ...$databaseInspection,
            ];
        } catch (RuntimeException $exception) {
            $versionRow = null;

            if (
                DB::connection()->getDriverName()
                === 'pgsql'
            ) {
                $versionRow = DB::selectOne(
                    <<<'SQL'
SELECT
    current_setting('server_version') AS server_version,
    current_setting('server_version_num')::integer AS server_version_num
SQL
                );
            }

            $databaseResult = [
                'ok' => false,
                'server_version' =>
                    $versionRow !== null
                        ? (string) $versionRow->server_version
                        : null,
                'server_version_num' =>
                    $versionRow !== null
                        ? (int) $versionRow->server_version_num
                        : null,
                'minimum_supported_major' =>
                    DatabaseReadinessCheck::MINIMUM_POSTGRES_MAJOR,
                'message' =>
                    $exception->getMessage(),
            ];

            $blockers[] =
                'Production database readiness failed.';
        }

        $smokeResult = [
            'ok' => false,
        ];

        try {
            $smokeInspection =
                $this->smoke->inspect();

            $smokeResult = [
                'ok' => true,
                ...$smokeInspection,
            ];
        } catch (RuntimeException $exception) {
            $smokeResult = [
                'ok' => false,
                'message' =>
                    $exception->getMessage(),
            ];

            $blockers[] =
                'Runtime smoke check failed.';
        }

        $implementationReady =
            $smokeResult['ok'] === true;

        /*
         * Deployment readiness is a production-runtime claim.
         *
         * A local/testing/staging-style execution may still
         * inspect implementation, database, and smoke health,
         * but it must never claim that the production
         * environment contract was validated.
         */
        $deploymentReady =
            $implementationReady
            && app()->environment('production')
            && $environmentResult[
                'production_contract_checked'
            ] === true
            && $environmentResult[
                'production_contract_ok'
            ] === true
            && $databaseResult['ok'] === true;

        return [
            'implementation_ready' =>
                $implementationReady,

            'deployment_ready' =>
                $deploymentReady,

            'environment' =>
                $environmentResult,

            'database' =>
                $databaseResult,

            'smoke' =>
                $smokeResult,

            'blockers' =>
                array_values(
                    array_unique($blockers)
                ),
        ];
    }
}

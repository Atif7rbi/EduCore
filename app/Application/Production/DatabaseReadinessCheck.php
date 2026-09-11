<?php

namespace App\Application\Production;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DatabaseReadinessCheck
{
    public const MINIMUM_POSTGRES_VERSION = '10.23';

    public const MINIMUM_POSTGRES_VERSION_NUM = 100023;

    /**
     * @return array{
     *     driver: string,
     *     server_version: string,
     *     server_version_num: int,
     *     minimum_supported_version: string,
     *     minimum_supported_version_num: int,
     *     required_tables: array<int, string>,
     *     migrations_pending: bool
     * }
     */
    public function inspect(): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore production database must use PostgreSQL.'
            );
        }

        $versionRow = DB::selectOne(
            <<<'SQL'
SELECT
    current_setting('server_version') AS server_version,
    current_setting('server_version_num')::integer AS server_version_num
SQL
        );

        $serverVersion =
            (string) $versionRow->server_version;

        $serverVersionNum =
            (int) $versionRow->server_version_num;

        if (
            $serverVersionNum
            < self::MINIMUM_POSTGRES_VERSION_NUM
        ) {
            throw new RuntimeException(
                sprintf(
                    'EduCore production requires PostgreSQL %s or newer; current server is %s.',
                    self::MINIMUM_POSTGRES_VERSION,
                    $serverVersion,
                )
            );
        }

        $requiredTables = [
            'migrations',
            'users',
            'learner_profiles',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
        ];

        $missingTables = collect(
            $requiredTables
        )
            ->reject(
                fn (string $table): bool => Schema::hasTable($table)
            )
            ->values()
            ->all();

        if ($missingTables !== []) {
            throw new RuntimeException(
                'Missing required production database tables: '
                .implode(', ', $missingTables)
                .'.'
            );
        }

        $pending = $this->pendingMigrationsExist();

        return [
            'driver' => 'pgsql',
            'server_version' => $serverVersion,
            'server_version_num' => $serverVersionNum,
            'minimum_supported_version' => self::MINIMUM_POSTGRES_VERSION,
            'minimum_supported_version_num' => self::MINIMUM_POSTGRES_VERSION_NUM,
            'required_tables' => $requiredTables,
            'migrations_pending' => $pending,
        ];
    }

    private function pendingMigrationsExist(): bool
    {
        $migrationRepository =
            app('migration.repository');

        if (! $migrationRepository->repositoryExists()) {
            return true;
        }

        $ran = collect(
            $migrationRepository->getRan()
        );

        $files = collect(
            app('migrator')->getMigrationFiles([
                database_path('migrations'),
            ])
        );

        return $files
            ->keys()
            ->diff($ran)
            ->isNotEmpty();
    }
}

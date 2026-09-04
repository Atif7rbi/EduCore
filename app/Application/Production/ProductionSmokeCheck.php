<?php

namespace App\Application\Production;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductionSmokeCheck
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(): array
    {
        $checks = [];

        $checks['application_boot'] = true;

        $checks['environment'] =
            app()->environment();

        $checks['debug_disabled'] =
            config('app.debug') === false;

        $checks['database_driver'] =
            DB::connection()->getDriverName();

        if (
            $checks['database_driver']
            !== 'pgsql'
        ) {
            throw new RuntimeException(
                'Smoke check requires PostgreSQL.'
            );
        }

        DB::selectOne(
            'SELECT 1 AS ok'
        );

        $checks['database_connectivity'] =
            true;

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

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Required table [{$table}] is missing."
                );
            }
        }

        $checks['required_tables'] =
            true;

        $healthRoute = collect(
            Route::getRoutes()
        )->first(
            fn ($route): bool => in_array(
                'GET',
                $route->methods(),
                true
            )
                && $route->uri()
                    === 'api/health'
        );

        if ($healthRoute === null) {
            throw new RuntimeException(
                'Public API health route is missing.'
            );
        }

        $checks['health_route'] =
            true;

        $storagePath =
            storage_path();

        if (! is_dir($storagePath)) {
            throw new RuntimeException(
                'Storage directory is missing.'
            );
        }

        if (! is_writable($storagePath)) {
            throw new RuntimeException(
                'Storage directory is not writable.'
            );
        }

        $checks['storage_writable'] =
            true;

        return $checks;
    }
}

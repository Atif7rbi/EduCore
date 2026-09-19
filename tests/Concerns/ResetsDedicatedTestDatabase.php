<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait ResetsDedicatedTestDatabase
{
    protected function tearDown(): void
    {
        try {
            /*
             * These tests intentionally retain their real PostgreSQL
             * transaction semantics. In particular, commit/deferred
             * integrity behavior must not be hidden inside Laravel's
             * RefreshDatabase outer transaction.
             *
             * Restore the isolated test database only after the test
             * has completed.
             */
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $database = DB::selectOne(
                'SELECT current_database() AS database_name'
            );

            if (
                ! is_object($database)
                || ($database->database_name ?? null)
                    !== 'sewaellf_educore_test'
            ) {
                throw new RuntimeException(
                    'Refusing test database reset outside '
                    .'sewaellf_educore_test.'
                );
            }

            $exitCode = Artisan::call(
                'migrate:fresh',
                [
                    '--force' => true,
                ],
            );

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'Failed to restore dedicated test database.'
                );
            }
        } finally {
            parent::tearDown();
        }
    }
}

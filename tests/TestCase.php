<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $environment = $_ENV['APP_ENV']
            ?? $_SERVER['APP_ENV']
            ?? getenv('APP_ENV')
            ?: null;

        $databaseName = $_ENV['DB_DATABASE']
            ?? $_SERVER['DB_DATABASE']
            ?? getenv('DB_DATABASE')
            ?: null;

        if ($environment !== 'testing') {
            throw new \RuntimeException(
                'EduCore tests require APP_ENV=testing before Laravel bootstrap.'
            );
        }

        if (! is_string($databaseName)
            || ! preg_match('/(?:^|[_-])(test|testing)(?:[_-]|$)/i', $databaseName)) {
            throw new \RuntimeException(
                'Unsafe EduCore test database target before Laravel bootstrap: '
                .($databaseName ?: '[unset]')
                .'. Test databases must include test or testing in their name.'
            );
        }

        parent::setUp();

        if (! app()->environment('testing')) {
            throw new \RuntimeException(
                'EduCore tests require APP_ENV=testing.'
            );
        }

        if (config('database.default') !== 'pgsql') {
            throw new \RuntimeException(
                'EduCore Feature/Integration tests require PostgreSQL.'
            );
        }

        $resolvedDatabaseName = DB::connection()->getDatabaseName();

        if (! preg_match('/(?:^|[_-])(test|testing)(?:[_-]|$)/i', $resolvedDatabaseName)) {
            throw new \RuntimeException(
                'Unsafe EduCore test database target: '.$resolvedDatabaseName
                .'. Test databases must include test or testing in their name.'
            );
        }
    }
}

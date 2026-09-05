<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
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

        $databaseName = DB::connection()->getDatabaseName();

        if (! preg_match('/(?:^|[_-])(test|testing)(?:[_-]|$)/i', $databaseName)) {
            throw new \RuntimeException(
                'Unsafe EduCore test database target: '.$databaseName
                .'. Test databases must include test or testing in their name.'
            );
        }
    }
}

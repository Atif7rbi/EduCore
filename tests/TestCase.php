<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            throw new \RuntimeException(
                'EduCore Feature/Integration tests require PostgreSQL.'
            );
        }
    }
}

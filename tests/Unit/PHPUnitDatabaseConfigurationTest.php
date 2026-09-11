<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PHPUnitDatabaseConfigurationTest extends TestCase
{
    #[Test]
    public function phpunit_forces_the_isolated_test_database(): void
    {
        self::assertSame('testing', getenv('APP_ENV'));
        self::assertSame('pgsql', getenv('DB_CONNECTION'));
        self::assertSame('sewaellf_educore_test', getenv('DB_DATABASE'));
        self::assertSame('', getenv('DB_URL'));
    }
}

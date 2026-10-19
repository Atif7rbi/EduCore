<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PHPUnitDatabaseConfigurationTest extends TestCase
{
    #[Test]
    public function phpunit_requires_an_explicit_isolated_test_database(): void
    {
        self::assertSame('testing', getenv('APP_ENV'));
        self::assertSame('pgsql', getenv('DB_CONNECTION'));
        $databaseName = getenv('DB_DATABASE');

        self::assertIsString($databaseName);
        self::assertNotSame('', $databaseName);
        self::assertMatchesRegularExpression(
            '/(?:^|[_-])(test|testing)(?:[_-]|$)/i',
            $databaseName,
        );
        self::assertSame('', getenv('DB_URL'));
    }
}

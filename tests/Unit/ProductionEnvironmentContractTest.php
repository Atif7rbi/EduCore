<?php

namespace Tests\Unit;

use App\Application\Production\ProductionEnvironmentContract;
use RuntimeException;
use Tests\TestCase;

class ProductionEnvironmentContractTest extends TestCase
{
    public function test_safe_production_configuration_passes(): void
    {
        $this->safeConfiguration();

        app(
            ProductionEnvironmentContract::class
        )->validate();

        $this->assertTrue(true);
    }

    public function test_debug_mode_is_rejected(): void
    {
        $this->safeConfiguration();

        config([
            'app.debug' => true,
        ]);

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'APP_DEBUG must be false'
        );

        app(
            ProductionEnvironmentContract::class
        )->validate();
    }

    public function test_non_https_application_url_is_rejected(): void
    {
        $this->safeConfiguration();

        config([
            'app.url' =>
                'http://edu.example.test',
        ]);

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'APP_URL must use HTTPS'
        );

        app(
            ProductionEnvironmentContract::class
        )->validate();
    }

    public function test_non_postgres_database_is_rejected(): void
    {
        $this->safeConfiguration();

        config([
            'database.default' => 'sqlite',
        ]);

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'DB_CONNECTION must be pgsql'
        );

        app(
            ProductionEnvironmentContract::class
        )->validate();
    }

    public function test_insecure_session_cookie_is_rejected(): void
    {
        $this->safeConfiguration();

        config([
            'session.secure' => false,
        ]);

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'SESSION_SECURE_COOKIE must be true'
        );

        app(
            ProductionEnvironmentContract::class
        )->validate();
    }

    public function test_file_session_driver_is_rejected(): void
    {
        $this->safeConfiguration();

        config([
            'session.driver' => 'file',
        ]);

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'SESSION_DRIVER must be database or redis'
        );

        app(
            ProductionEnvironmentContract::class
        )->validate();
    }

    public function test_array_cache_is_rejected(): void
    {
        $this->safeConfiguration();

        config([
            'cache.default' => 'array',
        ]);

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'CACHE_STORE must be database or redis'
        );

        app(
            ProductionEnvironmentContract::class
        )->validate();
    }

    public function test_sync_queue_is_rejected(): void
    {
        $this->safeConfiguration();

        config([
            'queue.default' => 'sync',
        ]);

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'QUEUE_CONNECTION must be database or redis'
        );

        app(
            ProductionEnvironmentContract::class
        )->validate();
    }

    public function test_multiple_violations_are_reported_together(): void
    {
        $this->safeConfiguration();

        config([
            'app.debug' => true,
            'app.url' =>
                'http://unsafe.example',
            'database.default' =>
                'sqlite',
            'session.secure' => false,
        ]);

        try {
            app(
                ProductionEnvironmentContract::class
            )->validate();

            $this->fail(
                'Expected production configuration failure.'
            );
        } catch (RuntimeException $exception) {
            $message =
                $exception->getMessage();

            $this->assertStringContainsString(
                'APP_DEBUG must be false',
                $message
            );

            $this->assertStringContainsString(
                'APP_URL must use HTTPS',
                $message
            );

            $this->assertStringContainsString(
                'DB_CONNECTION must be pgsql',
                $message
            );

            $this->assertStringContainsString(
                'SESSION_SECURE_COOKIE must be true',
                $message
            );
        }
    }

    private function safeConfiguration(): void
    {
        config([
            'app.debug' => false,
            'app.url' =>
                'https://edu.example.test',

            'database.default' =>
                'pgsql',

            'session.driver' =>
                'database',

            'session.secure' =>
                true,

            'session.http_only' =>
                true,

            'session.same_site' =>
                'lax',

            'cache.default' =>
                'database',

            'queue.default' =>
                'database',
        ]);
    }
}

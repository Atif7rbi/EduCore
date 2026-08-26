<?php

namespace Tests\Feature;

use App\Application\Production\ProductionSmokeCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionSmokeCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_check_validates_runtime_foundations(): void
    {
        $result = app(
            ProductionSmokeCheck::class
        )->inspect();

        $this->assertTrue(
            $result['application_boot']
        );

        $this->assertSame(
            'testing',
            $result['environment']
        );

        $this->assertSame(
            'pgsql',
            $result['database_driver']
        );

        $this->assertTrue(
            $result[
                'database_connectivity'
            ]
        );

        $this->assertTrue(
            $result['required_tables']
        );

        $this->assertTrue(
            $result['health_route']
        );

        $this->assertTrue(
            $result['storage_writable']
        );
    }

    public function test_smoke_command_succeeds_in_testing_environment(): void
    {
        $exitCode = Artisan::call(
            'production:smoke-check'
        );

        $this->assertSame(
            0,
            $exitCode
        );

        $output =
            Artisan::output();

        $this->assertStringContainsString(
            'EduCore smoke check: OK',
            $output
        );

        $this->assertStringContainsString(
            'database_driver: pgsql',
            $output
        );
    }

    public function test_health_endpoint_is_public_and_operational(): void
    {
        $response =
            $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'status' => 'ok',
                ],
            ]);

        $this->assertNotNull(
            $response->headers->get(
                'X-Request-ID'
            )
        );

        $response
            ->assertHeader(
                'X-Content-Type-Options',
                'nosniff'
            )
            ->assertHeader(
                'X-Frame-Options',
                'DENY'
            );
    }
}

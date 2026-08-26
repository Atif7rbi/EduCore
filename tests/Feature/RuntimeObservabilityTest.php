<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RuntimeObservabilityTest extends TestCase
{
    public function test_api_response_has_generated_request_id(): void
    {
        $response =
            $this->getJson('/api/health');

        $response->assertOk();

        $requestId =
            $response->headers->get(
                'X-Request-ID'
            );

        $this->assertNotNull(
            $requestId
        );

        $this->assertTrue(
            Str::isUuid($requestId)
        );
    }

    public function test_web_response_has_generated_request_id(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $requestId =
            $response->headers->get(
                'X-Request-ID'
            );

        $this->assertNotNull(
            $requestId
        );

        $this->assertTrue(
            Str::isUuid($requestId)
        );
    }

    public function test_each_request_gets_distinct_request_id(): void
    {
        $first =
            $this->getJson('/api/health')
                ->headers
                ->get('X-Request-ID');

        $second =
            $this->getJson('/api/health')
                ->headers
                ->get('X-Request-ID');

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $this->assertNotSame(
            $first,
            $second
        );
    }

    public function test_request_id_is_added_to_log_context(): void
    {
        Log::spy();

        Route::get(
            '/api/test-observability-log',
            function () {
                Log::info(
                    'A8.4 observability probe'
                );

                return response()->json([
                    'ok' => true,
                ]);
            }
        );

        $response = $this->getJson(
            '/api/test-observability-log'
        );

        $response->assertOk();

        $requestId =
            $response->headers->get(
                'X-Request-ID'
            );

        $this->assertNotNull(
            $requestId
        );

        Log::shouldHaveReceived(
            'withContext'
        )
            ->atLeast()
            ->once()
            ->withArgs(
                function (
                    array $context
                ) use (
                    $requestId
                ): bool {
                    return (
                        $context[
                            'request_id'
                        ] ?? null
                    ) === $requestId;
                }
            );
    }

    public function test_authentication_exception_keeps_401_contract(): void
    {
        $response = $this->getJson(
            '/api/progress/overview'
        );

        $response
            ->assertStatus(401)
            ->assertExactJson([
                'error' => [
                    'code' =>
                        'unauthenticated',
                    'message' =>
                        'Authentication is required.',
                ],
            ]);

        $this->assertTrue(
            Str::isUuid(
                (string) $response
                    ->headers
                    ->get('X-Request-ID')
            )
        );
    }

    public function test_unexpected_api_exception_uses_safe_internal_error_contract(): void
    {
        Route::get(
            '/api/test-unexpected-error',
            function (): never {
                throw new RuntimeException(
                    'Sensitive runtime detail that must never reach the client.'
                );
            }
        );

        $response = $this->getJson(
            '/api/test-unexpected-error'
        );

        $response
            ->assertStatus(500)
            ->assertExactJson([
                'error' => [
                    'code' =>
                        'internal_error',
                    'message' =>
                        'An unexpected server error occurred.',
                ],
            ]);

        $this->assertStringNotContainsString(
            'Sensitive runtime detail',
            $response->getContent()
        );

        $this->assertTrue(
            Str::isUuid(
                (string) $response
                    ->headers
                    ->get('X-Request-ID')
            )
        );
    }

    public function test_known_api_errors_keep_existing_contract(): void
    {
        $this->getJson(
            '/api/route-that-does-not-exist'
        )
            ->assertStatus(404)
            ->assertExactJson([
                'error' => [
                    'code' =>
                        'not_found',
                    'message' =>
                        'The requested resource was not found.',
                ],
            ])
            ->assertHeader(
                'X-Content-Type-Options',
                'nosniff'
            );

        $this->assertTrue(
            Str::isUuid(
                (string) $this
                    ->getJson('/api/health')
                    ->headers
                    ->get('X-Request-ID')
            )
        );
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
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

    public function test_authorization_exception_keeps_403_contract(): void
    {
        Route::get(
            '/api/test-forbidden',
            function (): never {
                throw new AuthorizationException(
                    'Sensitive authorization detail.'
                );
            }
        );

        $this->getJson('/api/test-forbidden')
            ->assertStatus(403)
            ->assertExactJson([
                'error' => [
                    'code' => 'forbidden',
                    'message' =>
                        'You are not authorized to perform this action.',
                ],
            ]);
    }

    public function test_method_not_allowed_exception_keeps_405_contract(): void
    {
        Route::get(
            '/api/test-method-only',
            fn () => response()->json([
                'ok' => true,
            ])
        );

        $this->postJson(
            '/api/test-method-only'
        )
            ->assertStatus(405)
            ->assertExactJson([
                'error' => [
                    'code' =>
                        'method_not_allowed',
                    'message' =>
                        'The HTTP method is not allowed for this resource.',
                ],
            ]);
    }

    public function test_csrf_exception_keeps_419_contract(): void
    {
        Route::get(
            '/api/test-csrf-expired',
            function (): never {
                throw new TokenMismatchException(
                    'Sensitive CSRF detail.'
                );
            }
        );

        $this->getJson('/api/test-csrf-expired')
            ->assertStatus(419)
            ->assertExactJson([
                'error' => [
                    'code' =>
                        'csrf_token_mismatch',
                    'message' =>
                        'The session security token has expired or is invalid.',
                ],
            ]);
    }

    public function test_throttle_http_exception_keeps_429_contract_and_headers(): void
    {
        Route::get(
            '/api/test-throttled-http',
            function (): never {
                throw new TooManyRequestsHttpException(
                    7,
                    'Sensitive throttle detail.'
                );
            }
        );

        $this->getJson(
            '/api/test-throttled-http'
        )
            ->assertStatus(429)
            ->assertExactJson([
                'error' => [
                    'code' =>
                        'too_many_requests',
                    'message' =>
                        'Too many requests. Please retry later.',
                ],
            ])
            ->assertHeader(
                'Retry-After',
                '7'
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

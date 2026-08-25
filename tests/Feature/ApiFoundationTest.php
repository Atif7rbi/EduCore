<?php

namespace Tests\Feature;

use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_api_health_uses_success_envelope(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'status' => 'ok',
                ],
            ]);
    }

    public function test_api_response_success_envelope(): void
    {
        Route::get('/api/test-success', function () {
            return ApiResponse::success(
                [
                    'id' => 'resource-1',
                ],
                201,
            );
        });

        $this->getJson('/api/test-success')
            ->assertStatus(201)
            ->assertExactJson([
                'data' => [
                    'id' => 'resource-1',
                ],
            ]);
    }

    public function test_integrity_exception_is_mapped_to_safe_conflict(): void
    {
        Route::get('/api/test-integrity', function () {
            throw new IntegrityConstraintViolation(
                'P0001',
                'Raw PostgreSQL integrity detail that must not leak.',
            );
        });

        $response = $this->getJson('/api/test-integrity');

        $response
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);

        $this->assertStringNotContainsString(
            'PostgreSQL',
            $response->getContent()
        );
    }

    public function test_concurrency_exception_is_mapped_to_conflict(): void
    {
        Route::get('/api/test-concurrency', function () {
            throw new ConcurrencyConflict('40001');
        });

        $this->getJson('/api/test-concurrency')
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'concurrency_conflict',
                    'message' => 'The resource changed during the operation. Please retry.',
                ],
            ]);
    }

    public function test_validation_exception_uses_validation_envelope(): void
    {
        Route::post('/api/test-validation', function () {
            $validator = Validator::make(
                [],
                [
                    'name' => ['required'],
                ],
            );

            throw new ValidationException($validator);
        });

        $this->postJson('/api/test-validation')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath(
                'error.message',
                'The submitted data is invalid.'
            )
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'details' => [
                        'name',
                    ],
                ],
            ]);
    }

    public function test_missing_api_route_uses_not_found_envelope(): void
    {
        $this->getJson('/api/route-that-does-not-exist')
            ->assertStatus(404)
            ->assertExactJson([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ]);
    }

    public function test_web_not_found_is_not_forced_into_api_contract(): void
    {
        $response = $this->get('/route-that-does-not-exist');

        $response->assertNotFound();

        $this->assertStringNotContainsString(
            '"code":"not_found"',
            $response->getContent()
        );
    }
}

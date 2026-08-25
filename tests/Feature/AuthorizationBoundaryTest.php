<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function managementRequests(string $id): array
    {
        return [
            ['postJson', "/api/curriculum-versions/{$id}/publish", []],
            ['postJson', "/api/curriculum-versions/{$id}/retire", []],
            ['postJson', "/api/lesson-revisions/{$id}/release", []],
            ['postJson', "/api/lessons/{$id}/publish", [
                'published_revision_id' => $id,
            ]],
            ['postJson', "/api/lessons/{$id}/retire", []],
            ['postJson', "/api/assessment-item-revisions/{$id}/release", []],
            ['postJson', "/api/assessment-items/{$id}/publish", [
                'published_revision_id' => $id,
            ]],
            ['postJson', "/api/assessment-items/{$id}/retire", []],
            ['postJson', "/api/practice-activities/{$id}/items", [
                'assessment_item_revision_id' => $id,
                'assessment_item_id' => $id,
                'display_order' => 0,
            ]],
            ['deleteJson', "/api/practice-activities/{$id}/items/{$id}", []],
            ['postJson', "/api/exam-template-versions/{$id}/generations", [
                'generator_version' => 'test',
                'seed' => 'test',
                'items' => [
                    [
                        'assessment_item_revision_id' => $id,
                        'assessment_item_id' => $id,
                    ],
                ],
            ]],
            ['postJson', "/api/attempt-responses/{$id}/regrade-corrections", [
                'corrected_is_correct' => true,
                'reason' => 'test',
            ]],
        ];
    }

    public function test_management_routes_reject_guest(): void
    {
        $id = (string) Str::uuid();

        foreach ($this->managementRequests($id) as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertStatus(401)
                ->assertJsonPath('error.code', 'unauthenticated');
        }
    }

    public function test_management_routes_reject_student(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $id = (string) Str::uuid();

        foreach ($this->managementRequests($id) as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'management_forbidden');
        }
    }

    public function test_management_routes_reject_teacher(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $id = (string) Str::uuid();

        foreach ($this->managementRequests($id) as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'management_forbidden');
        }
    }

    public function test_management_routes_reject_disabled_admin(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'disabled',
        ]);

        $this->actingAs($user);

        $id = (string) Str::uuid();

        foreach ($this->managementRequests($id) as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'account_disabled');
        }
    }

    public function test_active_admin_passes_management_boundary(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $id = (string) Str::uuid();

        foreach ($this->managementRequests($id) as [$method, $uri, $payload]) {
            $response = $this->{$method}($uri, $payload);

            $this->assertNotSame(
                401,
                $response->getStatusCode(),
                "Active admin was rejected as unauthenticated for {$uri}"
            );

            $this->assertNotSame(
                403,
                $response->getStatusCode(),
                "Active admin was rejected by management boundary for {$uri}"
            );
        }
    }

    public function test_health_is_public_and_learner_catalog_requires_authentication(): void
    {
        $missingId = (string) Str::uuid();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');

        $this->getJson(
            "/api/curriculum-versions/{$missingId}"
        )
            ->assertStatus(401);

        $this->getJson(
            "/api/lessons/{$missingId}"
        )
            ->assertStatus(401);

        $this->getJson(
            "/api/practice-activities/{$missingId}"
        )
            ->assertStatus(401);
    }
}

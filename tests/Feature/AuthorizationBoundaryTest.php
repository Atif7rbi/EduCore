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
            ['postJson', '/api/admin/subjects', [
                'name' => 'Test Subject',
            ]],
            ['putJson', "/api/admin/subjects/{$id}", [
                'name' => 'Updated Subject',
            ]],
            ['postJson', "/api/admin/subjects/{$id}/curricula", [
                'name' => 'Test Curriculum',
            ]],
            ['putJson', "/api/admin/curricula/{$id}", [
                'name' => 'Updated Curriculum',
            ]],
            ['postJson', "/api/admin/curricula/{$id}/versions", [
                'version_number' => 1,
                'label' => 'Version One',
            ]],
            ['putJson', "/api/admin/curriculum-versions/{$id}", [
                'version_number' => 1,
                'label' => 'Updated Version',
            ]],
            ['getJson', '/api/admin/subjects', []],

            ['getJson', "/api/admin/curriculum-versions/{$id}/topics", []],
            ['postJson', "/api/admin/curriculum-versions/{$id}/topics", [
                'name' => 'Test Topic',
                'display_order' => 0,
            ]],
            ['putJson', "/api/admin/topics/{$id}", [
                'name' => 'Updated Topic',
                'display_order' => 1,
            ]],

            ['getJson', '/api/admin/skills', []],
            ['postJson', '/api/admin/skills', [
                'name' => 'Test Skill',
                'description' => 'Test description',
            ]],
            ['putJson', "/api/admin/skills/{$id}", [
                'name' => 'Updated Skill',
                'description' => 'Updated description',
            ]],

            ['getJson', "/api/admin/curriculum-versions/{$id}/assessment-items", []],
            ['postJson', "/api/admin/curriculum-versions/{$id}/assessment-items", [
                'item_type' => 'multiple_choice',
                'internal_label' => 'Test Assessment Item',
            ]],
            ['putJson', "/api/admin/assessment-items/{$id}", [
                'item_type' => 'multiple_choice',
                'internal_label' => 'Updated Assessment Item',
            ]],

            ['getJson', "/api/admin/assessment-items/{$id}/revisions", []],
            ['postJson', "/api/admin/assessment-items/{$id}/revisions", [
                'revision_number' => 1,
                'primary_topic_id' => null,
                'difficulty' => 'medium',
                'content_payload' => [
                    'prompt' => 'Test question',
                ],
                'content_schema_version' => 1,
                'scoring_payload' => [
                    'correct_choice' => 0,
                ],
                'scoring_schema_version' => 1,
            ]],

            ['getJson', "/api/admin/assessment-item-revisions/{$id}/skills", []],
            ['postJson', "/api/admin/assessment-item-revisions/{$id}/skills", [
                'skill_version_placement_id' => $id,
                'role' => 'primary',
            ]],
            ['deleteJson', "/api/admin/assessment-item-revisions/{$id}/skills/{$id}", []],

            ['getJson', "/api/admin/curriculum-versions/{$id}/lessons", []],
            ['postJson', "/api/admin/curriculum-versions/{$id}/lessons", [
                'title' => 'Test Lesson',
                'description' => 'Test lesson description',
                'display_order' => 0,
            ]],
            ['putJson', "/api/admin/lessons/{$id}", [
                'title' => 'Updated Lesson',
                'description' => 'Updated lesson description',
                'display_order' => 1,
            ]],

            ['getJson', "/api/admin/lessons/{$id}/revisions", []],
            ['postJson', "/api/admin/lessons/{$id}/revisions", [
                'revision_number' => 1,
                'primary_topic_id' => $id,
                'content_payload' => [
                    'blocks' => [],
                ],
                'content_schema_version' => 1,
            ]],

            ['getJson', "/api/admin/lesson-revisions/{$id}/skills", []],
            ['postJson', "/api/admin/lesson-revisions/{$id}/skills", [
                'skill_version_placement_id' => $id,
            ]],
            ['deleteJson', "/api/admin/lesson-revisions/{$id}/skills/{$id}", []],

            ['getJson', "/api/admin/curriculum-versions/{$id}/skill-placements", []],
            ['postJson', "/api/admin/curriculum-versions/{$id}/skill-placements", [
                'skill_id' => $id,
            ]],
            ['deleteJson', "/api/admin/skill-placements/{$id}", []],

            ['postJson', "/api/admin/skill-placements/{$id}/home-topics", [
                'topic_id' => $id,
            ]],
            ['deleteJson', "/api/admin/skill-placements/{$id}/home-topics/{$id}", []],
            ['getJson', "/api/admin/subjects/{$id}/curricula", []],
            ['getJson', "/api/admin/curricula/{$id}/versions", []],
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

<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationBoundaryTest extends TestCase
{
    public function test_management_routes_are_fail_closed(): void
    {
        $id = (string) Str::uuid();

        $requests = [
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

        foreach ($requests as [$method, $uri, $payload]) {
            $this->{$method}($uri, $payload)
                ->assertStatus(403)
                ->assertJsonPath(
                    'error.code',
                    'management_authorization_required'
                );
        }
    }

    public function test_public_read_and_health_routes_are_not_management_blocked(): void
    {
        $missingId = (string) Str::uuid();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');

        $this->getJson(
            "/api/curriculum-versions/{$missingId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->getJson(
            "/api/lessons/{$missingId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->getJson(
            "/api/practice-activities/{$missingId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurriculumApiTest extends TestCase
{
    public function test_draft_curriculum_version_can_be_published_via_api(): void
    {
        $versionId = $this->createCurriculumVersion('draft');

        $response = $this->postJson(
            "/api/curriculum-versions/{$versionId}/publish"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $versionId)
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.label', 'v1')
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('curriculum_versions', [
            'id' => $versionId,
            'status' => 'published',
        ]);
    }

    public function test_published_curriculum_version_can_be_retired_via_api(): void
    {
        $versionId = $this->createCurriculumVersion('published');

        $response = $this->postJson(
            "/api/curriculum-versions/{$versionId}/retire"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $versionId)
            ->assertJsonPath('data.status', 'retired');

        $this->assertDatabaseHas('curriculum_versions', [
            'id' => $versionId,
            'status' => 'retired',
        ]);
    }

    public function test_publishing_already_published_version_is_idempotent(): void
    {
        $versionId = $this->createCurriculumVersion('published');

        $this->postJson(
            "/api/curriculum-versions/{$versionId}/publish"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $versionId)
            ->assertJsonPath('data.status', 'published');
    }

    public function test_retired_version_cannot_be_published_again(): void
    {
        $versionId = $this->createCurriculumVersion('retired');

        $this->postJson(
            "/api/curriculum-versions/{$versionId}/publish"
        )
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);
    }

    public function test_invalid_retire_lifecycle_is_mapped_to_api_conflict(): void
    {
        $versionId = $this->createCurriculumVersion('draft');

        $this->postJson(
            "/api/curriculum-versions/{$versionId}/retire"
        )
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);
    }

    public function test_missing_curriculum_version_returns_api_not_found(): void
    {
        $versionId = (string) Str::uuid();

        $this->postJson(
            "/api/curriculum-versions/{$versionId}/publish"
        )
            ->assertStatus(404)
            ->assertExactJson([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ]);
    }

    public function test_non_uuid_curriculum_version_does_not_match_route(): void
    {
        $this->postJson(
            '/api/curriculum-versions/not-a-uuid/publish'
        )
            ->assertStatus(404)
            ->assertExactJson([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ]);
    }

    private function createCurriculumVersion(string $status): string
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "API Curriculum {$curriculumId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }
}

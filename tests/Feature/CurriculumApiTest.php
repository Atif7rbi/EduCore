<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireManagementAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesHistoricalOwnerlessCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class CurriculumApiTest extends TestCase
{
    use CreatesHistoricalOwnerlessCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(
            RequireManagementAuthorization::class
        );
    }

    public function test_incomplete_draft_curriculum_version_is_rejected_via_api(): void
    {
        $versionId =
            $this->createCurriculumVersion(
                'draft'
            );

        $response = $this->postJson(
            "/api/curriculum-versions/{$versionId}/publish"
        );

        $response
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_ready'
            )
            ->assertJsonPath(
                'error.details.blockers',
                [
                    'has_topic',
                    'has_skill_placement',
                    'has_published_lesson',
                    'has_published_assessment_item',
                    'has_learner_usable_practice',
                    'has_usable_exam_template',
                ]
            );

        $this->assertDatabaseHas(
            'curriculum_versions',
            [
                'id' => $versionId,
                'status' => 'draft',
            ]
        );
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

    public function test_missing_curriculum_version_fails_closed_for_management_write(): void
    {
        $versionId = (string) Str::uuid();

        $this->postJson(
            "/api/curriculum-versions/{$versionId}/publish"
        )
            ->assertStatus(403)
            ->assertExactJson([
                'error' => [
                    'code' => 'admin_curriculum_content_read_only',
                    'message' => 'Teacher-owned curriculum content is read-only for management users.',
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
        $subjectId =
            $this->canonicalSubjectId();
        $curriculumId = null;
        $versionId = (string) Str::uuid();

        $curriculum =
            $this->createHistoricalOwnerlessCurriculumFixture(
                'API Curriculum '.Str::uuid()
            );

        $curriculumId = $curriculum->id;
        $subjectId = $curriculum->subject_id;

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

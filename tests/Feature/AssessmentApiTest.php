<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireManagementAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssessmentApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(
            RequireManagementAuthorization::class
        );
    }

    public function test_assessment_item_revision_can_be_released_via_api(): void
    {
        [, $revisionId] = $this->createAssessmentFixture();

        $response = $this->postJson(
            "/api/assessment-item-revisions/{$revisionId}/release"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $revisionId)
            ->assertJsonPath('data.revision_number', 1)
            ->assertJsonPath('data.difficulty', 'easy');

        $this->assertNotNull(
            $response->json('data.released_at')
        );

        $this->assertDatabaseMissing('assessment_item_revisions', [
            'id' => $revisionId,
            'released_at' => null,
        ]);
    }

    public function test_released_revision_can_publish_item_via_api(): void
    {
        [$itemId, $revisionId] = $this->createAssessmentFixture();

        $this->postJson(
            "/api/assessment-item-revisions/{$revisionId}/release"
        )->assertOk();

        $this->postJson(
            "/api/assessment-items/{$itemId}/publish",
            [
                'published_revision_id' => $revisionId,
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.id', $itemId)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath(
                'data.published_revision_id',
                $revisionId
            );

        $this->assertDatabaseHas('assessment_items', [
            'id' => $itemId,
            'status' => 'published',
            'published_revision_id' => $revisionId,
        ]);
    }

    public function test_unreleased_revision_cannot_publish_item_via_api(): void
    {
        [$itemId, $revisionId] = $this->createAssessmentFixture();

        $this->postJson(
            "/api/assessment-items/{$itemId}/publish",
            [
                'published_revision_id' => $revisionId,
            ],
        )
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);
    }

    public function test_publish_requires_valid_revision_uuid(): void
    {
        [$itemId] = $this->createAssessmentFixture();

        $this->postJson(
            "/api/assessment-items/{$itemId}/publish",
            [
                'published_revision_id' => 'not-a-uuid',
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'details' => [
                        'published_revision_id',
                    ],
                ],
            ]);
    }

    public function test_published_item_can_be_retired_via_api(): void
    {
        [$itemId, $revisionId] = $this->createAssessmentFixture();

        $this->postJson(
            "/api/assessment-item-revisions/{$revisionId}/release"
        )->assertOk();

        $this->postJson(
            "/api/assessment-items/{$itemId}/publish",
            [
                'published_revision_id' => $revisionId,
            ],
        )->assertOk();

        $this->postJson(
            "/api/assessment-items/{$itemId}/retire"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $itemId)
            ->assertJsonPath('data.status', 'retired');

        $this->assertDatabaseHas('assessment_items', [
            'id' => $itemId,
            'status' => 'retired',
        ]);
    }

    public function test_draft_item_cannot_be_retired_via_api(): void
    {
        [$itemId] = $this->createAssessmentFixture();

        $this->postJson(
            "/api/assessment-items/{$itemId}/retire"
        )
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);
    }

    public function test_missing_item_returns_not_found(): void
    {
        $itemId = (string) Str::uuid();

        $this->postJson(
            "/api/assessment-items/{$itemId}/retire"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_missing_revision_returns_not_found(): void
    {
        $revisionId = (string) Str::uuid();

        $this->postJson(
            "/api/assessment-item-revisions/{$revisionId}/release"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    /**
     * @return array{string, string}
     */
    private function createAssessmentFixture(): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Assessment API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Assessment API Curriculum {$curriculumId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Assessment API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Assessment API Skill {$skillId}",
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skill_version_placements')->insert([
            'id' => $placementId,
            'skill_id' => $skillId,
            'curriculum_version_id' => $versionId,
            'created_at' => now(),
        ]);

        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' => $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' => "Assessment API Item {$itemId}",
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('assessment_item_revisions')->insert([
            'id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'difficulty' => 'easy',
            'content_payload' => json_encode([
                'stem' => '2 + 2 = ?',
                'options' => [2, 3, 4, 5],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'scoring_payload' => json_encode([
                'correct_option' => 2,
            ], JSON_THROW_ON_ERROR),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        DB::table('assessment_item_revision_skills')->insert([
            'id' => (string) Str::uuid(),
            'assessment_item_revision_id' => $revisionId,
            'skill_version_placement_id' => $placementId,
            'curriculum_version_id' => $versionId,
            'role' => 'primary',
            'created_at' => now(),
        ]);

        return [$itemId, $revisionId];
    }
}

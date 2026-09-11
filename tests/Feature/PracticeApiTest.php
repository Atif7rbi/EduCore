<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireManagementAuthorization;
use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PracticeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(
            RequireManagementAuthorization::class
        );
    }

    public function test_released_revision_can_be_added_to_active_activity_via_api(): void
    {
        [$activityId, $versionId] = $this->createActiveActivity();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            released: true,
        );

        $response = $this->postJson(
            "/api/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' => $revisionId,
                'assessment_item_id' => $itemId,
                'display_order' => 1,
            ],
        );

        $response
            ->assertStatus(201)
            ->assertJsonPath(
                'data.practice_activity_id',
                $activityId
            )
            ->assertJsonPath(
                'data.assessment_item_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.assessment_item_id',
                $itemId
            )
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            )
            ->assertJsonPath(
                'data.display_order',
                1
            );

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $response->json('data.id'),
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
            'assessment_item_id' => $itemId,
            'display_order' => 1,
        ]);
    }

    public function test_unreleased_revision_cannot_be_added_via_api(): void
    {
        [$activityId, $versionId] = $this->createActiveActivity();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            released: false,
        );

        $this->postJson(
            "/api/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' => $revisionId,
                'assessment_item_id' => $itemId,
                'display_order' => 1,
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

    public function test_add_item_request_validates_input(): void
    {
        [$activityId] = $this->createActiveActivity();

        $this->postJson(
            "/api/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' => 'bad',
                'assessment_item_id' => 'bad',
                'display_order' => -1,
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'assessment_item_revision_id',
                        'assessment_item_id',
                        'display_order',
                    ],
                ],
            ]);
    }

    public function test_item_can_be_removed_when_activity_keeps_another_item(): void
    {
        [$activityId, $versionId, $existingItemId] =
            $this->createActiveActivity();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            released: true,
        );

        $addResponse = $this->postJson(
            "/api/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' => $revisionId,
                'assessment_item_id' => $itemId,
                'display_order' => 1,
            ],
        )->assertStatus(201);

        $practiceActivityItemId = $addResponse->json('data.id');

        $this->deleteJson(
            "/api/practice-activities/{$activityId}/items/{$practiceActivityItemId}"
        )
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'deleted' => true,
                ],
            ]);

        $this->assertDatabaseMissing('practice_activity_items', [
            'id' => $practiceActivityItemId,
        ]);

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $existingItemId,
            'practice_activity_id' => $activityId,
        ]);
    }

    public function test_last_item_cannot_be_removed_from_active_activity(): void
    {
        [$activityId, , $existingItemId] =
            $this->createActiveActivity();

        $this->deleteJson(
            "/api/practice-activities/{$activityId}/items/{$existingItemId}"
        )
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $existingItemId,
        ]);
    }

    public function test_missing_activity_returns_not_found(): void
    {
        $activityId = (string) Str::uuid();

        $this->postJson(
            "/api/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' => (string) Str::uuid(),
                'assessment_item_id' => (string) Str::uuid(),
                'display_order' => 0,
            ],
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_item_from_different_activity_returns_not_found(): void
    {
        [$activityId] = $this->createActiveActivity();
        [$otherActivityId, , $otherItemId] =
            $this->createActiveActivity();

        $this->deleteJson(
            "/api/practice-activities/{$activityId}/items/{$otherItemId}"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $otherItemId,
            'practice_activity_id' => $otherActivityId,
        ]);
    }

    /**
     * @return array{string, string, string}
     */
    private function createActiveActivity(): array
    {
        $versionId = $this->createCurriculumVersion();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            released: true,
        );

        $activityId = (string) Str::uuid();
        $practiceActivityItemId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => "Practice API Activity {$activityId}",
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('practice_activity_items')->insert([
            'id' => $practiceActivityItemId,
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'display_order' => 0,
            'created_at' => now(),
        ]);

        DB::table('practice_activities')
            ->where('id', $activityId)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        return [
            $activityId,
            $versionId,
            $practiceActivityItemId,
        ];
    }

    private function createCurriculumVersion(): string
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Practice API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Practice API Curriculum {$curriculumId}",
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

        return $versionId;
    }

    /**
     * @return array{string, string}
     */
    private function createAssessmentRevision(
        string $versionId,
        bool $released,
    ): array {
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Practice API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Practice API Skill {$skillId}",
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
            'internal_label' => "Practice API Item {$itemId}",
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
                'stem' => '5 + 5 = ?',
                'options' => [8, 9, 10, 11],
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

        if ($released) {
            $service = new ReleaseAssessmentItemRevision(
                new TransactionManager(
                    new PostgresExceptionTranslator()
                )
            );

            $service->execute($revisionId);
        }

        return [$revisionId, $itemId];
    }
}

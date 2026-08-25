<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Exam\BuildExamGeneration;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttemptApiTest extends TestCase
{
    public function test_practice_attempt_can_be_built_via_api(): void
    {
        [
            $learnerId,
            $activityId,
            $revisionId,
            $itemId,
        ] = $this->createPracticeFixture();

        $response = $this->postJson(
            "/api/practice-activities/{$activityId}/attempts",
            [],
        );

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.learner_profile_id', $learnerId)
            ->assertJsonPath('data.exam_generation_id', null)
            ->assertJsonPath('data.practice_activity_id', $activityId)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath(
                'data.items.0.assessment_item_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.items.0.assessment_item_id',
                $itemId
            )
            ->assertJsonPath(
                'data.items.0.presentation_position',
                0
            );

        $this->assertNotNull(
            $response->json('data.started_at')
        );

        $attemptId = $response->json('data.id');
        $attemptItemId = $response->json('data.items.0.id');

        $this->assertDatabaseHas('attempts', [
            'id' => $attemptId,
            'learner_profile_id' => $learnerId,
            'practice_activity_id' => $activityId,
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('attempt_responses', [
            'attempt_item_id' => $attemptItemId,
            'answer_change_count' => 0,
            'time_spent_ms' => 0,
            'original_is_correct' => null,
        ]);
    }

    public function test_exam_attempt_can_be_built_via_api(): void
    {
        [
            $learnerId,
            $generationId,
            $revisionId,
            $itemId,
        ] = $this->createExamFixture();

        $response = $this->postJson(
            "/api/exam-generations/{$generationId}/attempts",
            [],
        );

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.learner_profile_id', $learnerId)
            ->assertJsonPath(
                'data.exam_generation_id',
                $generationId
            )
            ->assertJsonPath(
                'data.practice_activity_id',
                null
            )
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath(
                'data.items.0.assessment_item_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.items.0.assessment_item_id',
                $itemId
            );

        $this->assertNotNull(
            $response->json('data.started_at')
        );
    }

    public function test_second_exam_attempt_for_same_generation_is_rejected_via_api(): void
    {
        [$learnerId, $generationId] =
            $this->createExamFixture();

        $this->postJson(
            "/api/exam-generations/{$generationId}/attempts",
            [],
        )->assertStatus(201);

        $this->postJson(
            "/api/exam-generations/{$generationId}/attempts",
            [],
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'integrity_conflict'
            );
    }

    public function test_missing_exam_generation_returns_not_found(): void
    {
        $generationId = (string) Str::uuid();
        $learnerId = $this->createLearner();

        $this->postJson(
            "/api/exam-generations/{$generationId}/attempts",
            [],
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_attempt_response_can_be_saved_and_changed_via_api(): void
    {
        [$learnerId, $activityId] =
            $this->createPracticeFixture();

        $attemptResponse = $this->postJson(
            "/api/practice-activities/{$activityId}/attempts",
            [],
        )->assertStatus(201);

        $attemptItemId = $attemptResponse->json(
            'data.items.0.id'
        );

        $this->putJson(
            "/api/attempt-items/{$attemptItemId}/response",
            [
                'response_payload' => [
                    'selected_option' => 1,
                ],
                'time_spent_ms' => 1000,
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.response_payload.selected_option',
                1
            )
            ->assertJsonPath(
                'data.answer_change_count',
                0
            )
            ->assertJsonPath(
                'data.time_spent_ms',
                1000
            )
            ->assertJsonPath(
                'data.original_is_correct',
                null
            );

        $this->putJson(
            "/api/attempt-items/{$attemptItemId}/response",
            [
                'response_payload' => [
                    'selected_option' => 2,
                ],
                'time_spent_ms' => 2400,
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.response_payload.selected_option',
                2
            )
            ->assertJsonPath(
                'data.answer_change_count',
                1
            )
            ->assertJsonPath(
                'data.time_spent_ms',
                2400
            );
    }

    public function test_attempt_response_rejects_negative_time(): void
    {
        [$learnerId, $activityId] =
            $this->createPracticeFixture();

        $attemptResponse = $this->postJson(
            "/api/practice-activities/{$activityId}/attempts",
            [],
        )->assertStatus(201);

        $attemptItemId = $attemptResponse->json(
            'data.items.0.id'
        );

        $this->putJson(
            "/api/attempt-items/{$attemptItemId}/response",
            [
                'response_payload' => [
                    'selected_option' => 2,
                ],
                'time_spent_ms' => -1,
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            );
    }

    public function test_missing_attempt_item_response_returns_not_found(): void
    {
        $this->createLearner();

        $attemptItemId = (string) Str::uuid();

        $this->putJson(
            "/api/attempt-items/{$attemptItemId}/response",
            [
                'response_payload' => [
                    'selected_option' => 2,
                ],
                'time_spent_ms' => 1000,
            ],
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_attempt_can_be_read_without_scoring_truth(): void
    {
        [$learnerId, $activityId] =
            $this->createPracticeFixture();

        $created = $this->postJson(
            "/api/practice-activities/{$activityId}/attempts",
            [],
        )->assertStatus(201);

        $attemptId = $created->json('data.id');
        $attemptItemId = $created->json('data.items.0.id');

        $this->putJson(
            "/api/attempt-items/{$attemptItemId}/response",
            [
                'response_payload' => [
                    'selected_option' => 2,
                ],
                'time_spent_ms' => 2100,
            ],
        )->assertOk();

        $response = $this->getJson(
            "/api/attempts/{$attemptId}"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $attemptId)
            ->assertJsonPath(
                'data.learner_profile_id',
                $learnerId
            )
            ->assertJsonPath(
                'data.practice_activity_id',
                $activityId
            )
            ->assertJsonPath(
                'data.status',
                'in_progress'
            )
            ->assertJsonPath(
                'data.items.0.id',
                $attemptItemId
            )
            ->assertJsonPath(
                'data.items.0.response.response_payload.selected_option',
                2
            )
            ->assertJsonPath(
                'data.items.0.response.answer_change_count',
                0
            )
            ->assertJsonPath(
                'data.items.0.response.time_spent_ms',
                2100
            );

        $item = $response->json('data.items.0');
        $itemResponse = $response->json(
            'data.items.0.response'
        );

        $this->assertArrayNotHasKey(
            'scoring_snapshot',
            $item
        );

        $this->assertArrayNotHasKey(
            'scoring_schema_version',
            $item
        );

        $this->assertArrayNotHasKey(
            'original_is_correct',
            $itemResponse
        );
    }

    public function test_missing_attempt_read_returns_not_found(): void
    {
        $this->createLearner();

        $attemptId = (string) Str::uuid();

        $this->getJson(
            "/api/attempts/{$attemptId}"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_learner_attempt_routes_require_authentication(): void
    {
        auth()->logout();

        $missingId = (string) Str::uuid();

        $this->postJson(
            "/api/exam-generations/{$missingId}/attempts",
            [],
        )->assertUnauthorized();

        $this->postJson(
            "/api/practice-activities/{$missingId}/attempts",
            [],
        )->assertUnauthorized();

        $this->putJson(
            "/api/attempt-items/{$missingId}/response",
            [
                'response_payload' => null,
                'time_spent_ms' => 0,
            ],
        )->assertUnauthorized();

        $this->getJson(
            "/api/attempts/{$missingId}"
        )->assertUnauthorized();
    }

    public function test_attempt_identity_comes_from_authenticated_user_not_request_body(): void
    {
        [$learnerId, $activityId] =
            $this->createPracticeFixture();

        $spoofedLearnerId = (string) Str::uuid();

        $response = $this->postJson(
            "/api/practice-activities/{$activityId}/attempts",
            [
                'learner_profile_id' => $spoofedLearnerId,
            ],
        );

        $response
            ->assertStatus(201)
            ->assertJsonPath(
                'data.learner_profile_id',
                $learnerId
            );

        $this->assertDatabaseHas('attempts', [
            'id' => $response->json('data.id'),
            'learner_profile_id' => $learnerId,
        ]);

        $this->assertDatabaseMissing('attempts', [
            'id' => $response->json('data.id'),
            'learner_profile_id' => $spoofedLearnerId,
        ]);
    }

    public function test_learner_cannot_read_another_learners_attempt(): void
    {
        [, $activityId] =
            $this->createPracticeFixture();

        $created = $this->postJson(
            "/api/practice-activities/{$activityId}/attempts",
            [],
        )->assertStatus(201);

        $attemptId = $created->json('data.id');

        $this->createLearner();

        $this->getJson(
            "/api/attempts/{$attemptId}"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_learner_cannot_update_another_learners_attempt_item(): void
    {
        [, $activityId] =
            $this->createPracticeFixture();

        $created = $this->postJson(
            "/api/practice-activities/{$activityId}/attempts",
            [],
        )->assertStatus(201);

        $attemptItemId = $created->json(
            'data.items.0.id'
        );

        $this->createLearner();

        $this->putJson(
            "/api/attempt-items/{$attemptItemId}/response",
            [
                'response_payload' => [
                    'selected_option' => 2,
                ],
                'time_spent_ms' => 1000,
            ],
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );

        $this->assertDatabaseHas(
            'attempt_responses',
            [
                'attempt_item_id' => $attemptItemId,
                'response_payload' => null,
                'answer_change_count' => 0,
                'time_spent_ms' => 0,
            ]
        );
    }

    /**
     * @return array{string, string, string, string}
     */
    private function createPracticeFixture(): array
    {
        [
            $learnerId,
            $versionId,
            $revisionId,
            $itemId,
        ] = $this->createAssessmentFixture();

        $activityId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => "Attempt API Practice {$activityId}",
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('practice_activity_items')->insert([
            'id' => (string) Str::uuid(),
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
            $learnerId,
            $activityId,
            $revisionId,
            $itemId,
        ];
    }

    /**
     * @return array{string, string, string, string}
     */
    private function createExamFixture(): array
    {
        [
            $learnerId,
            $versionId,
            $revisionId,
            $itemId,
        ] = $this->createAssessmentFixture();

        $templateId = (string) Str::uuid();
        $templateVersionId = (string) Str::uuid();

        DB::table('exam_templates')->insert([
            'id' => $templateId,
            'curriculum_version_id' => $versionId,
            'name' => "Attempt API Template {$templateId}",
            'description' => null,
            'status' => 'active',
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_template_versions')->insert([
            'id' => $templateVersionId,
            'exam_template_id' => $templateId,
            'curriculum_version_id' => $versionId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'rules_payload' => json_encode([
                'question_count' => 1,
            ], JSON_THROW_ON_ERROR),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_template_versions')
            ->where('id', $templateVersionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $generation = (new BuildExamGeneration(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute(
            $templateVersionId,
            'attempt-api-generator-v1',
            'attempt-api-seed',
            [
                [
                    'assessment_item_revision_id' => $revisionId,
                    'assessment_item_id' => $itemId,
                ],
            ],
        );

        return [
            $learnerId,
            $generation->id,
            $revisionId,
            $itemId,
        ];
    }

    /**
     * @return array{string, string, string, string}
     */
    private function createAssessmentFixture(): array
    {
        $learnerId = $this->createLearner();

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
            'name' => "Attempt API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Attempt API Curriculum {$curriculumId}",
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
            'name' => "Attempt API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Attempt API Skill {$skillId}",
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
            'internal_label' => "Attempt API Item {$itemId}",
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
                'stem' => '8 + 7 = ?',
                'options' => [13, 14, 15, 16],
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

        (new ReleaseAssessmentItemRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($revisionId);

        return [
            $learnerId,
            $versionId,
            $revisionId,
            $itemId,
        ];
    }

    private function createLearner(): string
    {
        $userId = (string) Str::uuid();
        $learnerId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => "Attempt API User {$userId}",
            'email' => "attempt-api-{$userId}@example.test",
            'password' => 'not-used',
            'status' => 'active',
            'role' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('learner_profiles')->insert([
            'id' => $learnerId,
            'user_id' => $userId,
            'created_at' => now(),
        ]);

        $this->actingAs(
            User::query()->findOrFail($userId)
        );

        return $learnerId;
    }
}

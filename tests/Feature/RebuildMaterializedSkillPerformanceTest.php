<?php

namespace Tests\Feature;

use App\Application\Analytics\CreateEvidenceScope;
use App\Application\Analytics\RebuildMaterializedSkillPerformance;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class RebuildMaterializedSkillPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_materializes_single_primary_and_supporting_counts(): void
    {
        $fixture = $this->fixture();

        $singleAttempt = $this->attempt(
            $fixture,
            [
                [$fixture['target_skill_id'], 'primary'],
            ],
            true,
        );

        $supportingAttempt = $this->attempt(
            $fixture,
            [
                [$fixture['other_skill_id'], 'primary'],
                [$fixture['target_skill_id'], 'supporting'],
            ],
            true,
        );

        $result = $this->service()->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [
                $singleAttempt,
                $supportingAttempt,
            ],
        );

        $this->assertNotNull($result);

        $this->assertSame(
            1,
            $result->single_primary_correct_count
        );

        $this->assertSame(
            1,
            $result->single_primary_answered_count
        );

        $this->assertSame(
            1,
            $result->supporting_positive_count
        );

        $this->assertSame(
            1,
            $result->supporting_exposure_count
        );

        $this->assertNotNull(
            $result->last_rebuilt_at
        );
    }

    public function test_incorrect_single_primary_counts_answered_without_correct(): void
    {
        $fixture = $this->fixture();

        $attemptId = $this->attempt(
            $fixture,
            [
                [$fixture['target_skill_id'], 'primary'],
            ],
            false,
        );

        $result = $this->service()->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );

        $this->assertNotNull($result);

        $this->assertSame(
            0,
            $result->single_primary_correct_count
        );

        $this->assertSame(
            1,
            $result->single_primary_answered_count
        );
    }

    public function test_multi_primary_composite_is_not_distributed_into_individual_cache(): void
    {
        $fixture = $this->fixture();

        $attemptId = $this->attempt(
            $fixture,
            [
                [$fixture['target_skill_id'], 'primary'],
                [$fixture['other_skill_id'], 'primary'],
            ],
            true,
        );

        $result = $this->service()->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );

        $this->assertNull($result);

        $this->assertDatabaseMissing(
            'materialized_skill_performances',
            [
                'learner_profile_id' =>
                    $fixture['learner_profile_id'],
                'skill_id' =>
                    $fixture['target_skill_id'],
                'evidence_scope_id' =>
                    $fixture['evidence_scope_id'],
            ]
        );
    }

    public function test_latest_regrade_is_reflected_by_full_rebuild(): void
    {
        $fixture = $this->fixture();

        $attemptId = $this->attempt(
            $fixture,
            [
                [$fixture['target_skill_id'], 'primary'],
            ],
            false,
        );

        $first = $this->service()->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );

        $this->assertSame(
            0,
            $first->single_primary_correct_count
        );

        $responseId = DB::table(
            'attempt_responses as ar'
        )
            ->join(
                'attempt_items as ai',
                'ai.id',
                '=',
                'ar.attempt_item_id'
            )
            ->where(
                'ai.attempt_id',
                $attemptId
            )
            ->value('ar.id');

        DB::table(
            'regrade_corrections'
        )->insert([
            'id' =>
                (string) Str::uuid(),
            'attempt_response_id' =>
                $responseId,
            'correction_number' => 1,
            'corrected_is_correct' => true,
            'reason' =>
                'A7.4 rebuild verification',
            'corrected_at' => now(),
            'created_at' => now(),
        ]);

        $second = $this->service()->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );

        $this->assertSame(
            1,
            $second->single_primary_correct_count
        );

        $this->assertSame(
            1,
            $second->single_primary_answered_count
        );

        $this->assertDatabaseCount(
            'materialized_skill_performances',
            1
        );
    }

    public function test_rebuild_replaces_existing_counts_instead_of_incrementing(): void
    {
        $fixture = $this->fixture();

        $attemptId = $this->attempt(
            $fixture,
            [
                [$fixture['target_skill_id'], 'primary'],
            ],
            true,
        );

        $service = $this->service();

        $first = $service->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );

        $second = $service->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );

        $this->assertSame(
            1,
            $first->single_primary_correct_count
        );

        $this->assertSame(
            1,
            $second->single_primary_correct_count
        );

        $this->assertSame(
            1,
            $second->single_primary_answered_count
        );

        $this->assertDatabaseCount(
            'materialized_skill_performances',
            1
        );
    }

    public function test_zero_evidence_rebuild_removes_stale_cache_row(): void
    {
        $fixture = $this->fixture();

        $attemptId = $this->attempt(
            $fixture,
            [
                [$fixture['target_skill_id'], 'primary'],
            ],
            true,
        );

        $service = $this->service();

        $service->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );

        $this->assertDatabaseCount(
            'materialized_skill_performances',
            1
        );

        $result = $service->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [],
        );

        $this->assertNull($result);

        $this->assertDatabaseCount(
            'materialized_skill_performances',
            0
        );
    }

    public function test_attempt_from_another_learner_is_rejected(): void
    {
        $fixture = $this->fixture();

        $other = $this->fixture();

        $attemptId = $this->attempt(
            $other,
            [
                [$other['target_skill_id'], 'primary'],
            ],
            true,
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );
    }

    public function test_in_progress_attempt_is_rejected_from_eligible_set(): void
    {
        $fixture = $this->fixture();

        $attemptId = $this->attempt(
            $fixture,
            [
                [$fixture['target_skill_id'], 'primary'],
            ],
            true,
            'in_progress',
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->service()->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [$attemptId],
        );
    }

    private function service(): RebuildMaterializedSkillPerformance
    {
        return app(
            RebuildMaterializedSkillPerformance::class
        );
    }

    private function fixture(): array
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::query()
            ->create([
                'user_id' => $user->id,
            ]);

        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $targetSkillId = (string) Str::uuid();
        $otherSkillId = (string) Str::uuid();
        $activityId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' =>
                'A7.4 Subject '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' =>
                'A7.4 Curriculum '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'curriculum_versions'
        )->insert([
            'id' => $versionId,
            'curriculum_id' =>
                $curriculumId,
            'version_number' => 1,
            'label' => 'A7.4 v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' =>
                $versionId,
            'name' =>
                'A7.4 Topic '.Str::random(8),
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (
            [$targetSkillId, $otherSkillId]
            as $skillId
        ) {
            DB::table('skills')->insert([
                'id' => $skillId,
                'name' =>
                    'A7.4 Skill '.Str::random(8),
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table(
            'practice_activities'
        )->insert([
            'id' => $activityId,
            'curriculum_version_id' =>
                $versionId,
            'lesson_id' => null,
            'name' =>
                'A7.4 Practice '.Str::random(8),
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = app(
            CreateEvidenceScope::class
        )->execute(
            'A7.4 Scope '.Str::random(8),
            null,
            [
                'opaque' => true,
            ],
            1,
        );

        return [
            'learner_profile_id' =>
                $learner->id,
            'curriculum_version_id' =>
                $versionId,
            'primary_topic_id' =>
                $topicId,
            'target_skill_id' =>
                $targetSkillId,
            'other_skill_id' =>
                $otherSkillId,
            'practice_activity_id' =>
                $activityId,
            'evidence_scope_id' =>
                $scope->id,
        ];
    }

    private function attempt(
        array $fixture,
        array $classifications,
        ?bool $isCorrect,
        string $finalStatus = 'submitted',
    ): string {
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();
        $attemptId = (string) Str::uuid();
        $attemptItemId = (string) Str::uuid();
        $responseId = (string) Str::uuid();

        DB::table(
            'assessment_items'
        )->insert([
            'id' => $itemId,
            'curriculum_version_id' =>
                $fixture[
                    'curriculum_version_id'
                ],
            'item_type' =>
                'multiple_choice',
            'internal_label' =>
                'A7.4 Item '.Str::random(8),
            'status' => 'draft',
            'published_revision_id' =>
                null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'assessment_item_revisions'
        )->insert([
            'id' => $revisionId,
            'assessment_item_id' =>
                $itemId,
            'curriculum_version_id' =>
                $fixture[
                    'curriculum_version_id'
                ],
            'revision_number' => 1,
            'primary_topic_id' =>
                $fixture[
                    'primary_topic_id'
                ],
            'difficulty' => 'easy',
            'content_payload' =>
                json_encode(
                    ['stem' => 'A7.4'],
                    JSON_THROW_ON_ERROR,
                ),
            'content_schema_version' => 1,
            'scoring_payload' =>
                json_encode(
                    ['correct_option' => 0],
                    JSON_THROW_ON_ERROR,
                ),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'learner_profile_id' =>
                $fixture[
                    'learner_profile_id'
                ],
            'exam_generation_id' => null,
            'practice_activity_id' =>
                $fixture[
                    'practice_activity_id'
                ],
            'curriculum_version_id' =>
                $fixture[
                    'curriculum_version_id'
                ],
            'status' => 'in_progress',
            'started_at' => null,
            'finalized_at' => null,
            'created_at' => now(),
            'updated_at' => null,
        ]);

        DB::table('attempt_items')->insert([
            'id' => $attemptItemId,
            'attempt_id' => $attemptId,
            'assessment_item_revision_id' =>
                $revisionId,
            'assessment_item_id' =>
                $itemId,
            'curriculum_version_id' =>
                $fixture[
                    'curriculum_version_id'
                ],
            'exam_generation_id' => null,
            'exam_generation_item_id' => null,
            'presentation_position' => 0,
            'presented_payload' =>
                json_encode(
                    ['stem' => 'A7.4'],
                    JSON_THROW_ON_ERROR,
                ),
            'presented_schema_version' => 1,
            'scoring_snapshot' =>
                json_encode(
                    ['correct_option' => 0],
                    JSON_THROW_ON_ERROR,
                ),
            'scoring_schema_version' => 1,
            'primary_topic_id' =>
                $fixture[
                    'primary_topic_id'
                ],
            'created_at' => now(),
        ]);

        foreach (
            $classifications
            as [$skillId, $role]
        ) {
            DB::table(
                'attempt_item_classification_skills'
            )->insert([
                'id' =>
                    (string) Str::uuid(),
                'attempt_item_id' =>
                    $attemptItemId,
                'skill_id' => $skillId,
                'role' => $role,
                'created_at' => now(),
            ]);
        }

        DB::table(
            'attempt_responses'
        )->insert([
            'id' => $responseId,
            'attempt_item_id' =>
                $attemptItemId,
            'response_payload' =>
                $isCorrect === null
                    ? null
                    : json_encode(
                        [
                            'selected_option' =>
                                $isCorrect
                                    ? 0
                                    : 1,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
            'answer_change_count' => 0,
            'time_spent_ms' => 100,
            'original_is_correct' =>
                $isCorrect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('attempts')
            ->where('id', $attemptId)
            ->update([
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($finalStatus !== 'in_progress') {
            DB::table('attempts')
                ->where('id', $attemptId)
                ->update([
                    'status' =>
                        $finalStatus,
                    'finalized_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $attemptId;
    }
}

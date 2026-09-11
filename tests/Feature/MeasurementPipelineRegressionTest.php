<?php

namespace Tests\Feature;

use App\Application\Analytics\CreateEvidenceScope;
use App\Application\Analytics\RebuildMaterializedSkillPerformance;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeasurementPipelineRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_evidence_regrade_rebuild_and_read_pipeline_preserves_measurement_semantics(): void
    {
        $fixture = $this->fixture();

        /*
         * Attempt 1:
         * target Skill is the sole primary Skill.
         * Original outcome = incorrect.
         * Regrade later changes effective outcome to correct.
         */
        $singlePrimary = $this->attempt(
            $fixture,
            [
                [
                    $fixture['target_skill_id'],
                    'primary',
                ],
            ],
            false,
        );

        /*
         * Attempt 2:
         * target Skill is supporting.
         * Correct response => exposure + positive evidence.
         */
        $supporting = $this->attempt(
            $fixture,
            [
                [
                    $fixture['other_skill_id'],
                    'primary',
                ],
                [
                    $fixture['target_skill_id'],
                    'supporting',
                ],
            ],
            true,
        );

        /*
         * Attempt 3:
         * target Skill is one of two primary Skills.
         * Incorrect composite outcome must NOT become
         * individual negative evidence for target Skill.
         */
        $composite = $this->attempt(
            $fixture,
            [
                [
                    $fixture['target_skill_id'],
                    'primary',
                ],
                [
                    $fixture['other_skill_id'],
                    'primary',
                ],
            ],
            false,
        );

        DB::table('regrade_corrections')->insert([
            'id' => (string) Str::uuid(),
            'attempt_response_id' =>
                $singlePrimary[
                    'attempt_response_id'
                ],
            'correction_number' => 1,
            'corrected_is_correct' => true,
            'reason' =>
                'A7.6 measurement pipeline regression',
            'corrected_at' => now(),
            'created_at' => now(),
        ]);

        $performance = app(
            RebuildMaterializedSkillPerformance::class
        )->execute(
            $fixture['learner_profile_id'],
            $fixture['target_skill_id'],
            $fixture['evidence_scope_id'],
            [
                $singlePrimary['attempt_id'],
                $supporting['attempt_id'],
                $composite['attempt_id'],
            ],
        );

        $this->assertNotNull($performance);

        /*
         * Sole-primary:
         * exactly one answered item and latest regrade
         * makes it effectively correct.
         */
        $this->assertSame(
            1,
            $performance
                ->single_primary_answered_count
        );

        $this->assertSame(
            1,
            $performance
                ->single_primary_correct_count
        );

        /*
         * Supporting:
         * one correct supporting participation.
         */
        $this->assertSame(
            1,
            $performance
                ->supporting_exposure_count
        );

        $this->assertSame(
            1,
            $performance
                ->supporting_positive_count
        );

        /*
         * If composite primary had been incorrectly
         * distributed, answered_count would be 2.
         */
        $this->assertSame(
            1,
            $performance
                ->single_primary_answered_count
        );

        $this->actingAs(
            User::query()->findOrFail(
                $fixture['user_id']
            )
        );

        $response = $this->getJson(
            '/api/analytics/skills'
            .'?evidence_scope_id='
            .$fixture['evidence_scope_id']
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.evidence_scope.id',
                $fixture['evidence_scope_id']
            )
            ->assertJsonPath(
                'data.temporal_boundary',
                'lifetime'
            )
            ->assertJsonPath(
                'data.skills.0.skill.id',
                $fixture['target_skill_id']
            )
            ->assertJsonPath(
                'data.skills.0.single_primary.correct_count',
                1
            )
            ->assertJsonPath(
                'data.skills.0.single_primary.answered_count',
                1
            )
            ->assertJsonPath(
                'data.skills.0.single_primary.accuracy',
                1
            )
            ->assertJsonPath(
                'data.skills.0.supporting.positive_count',
                1
            )
            ->assertJsonPath(
                'data.skills.0.supporting.exposure_count',
                1
            )
            ->assertJsonMissingPath(
                'data.skills.0.supporting.accuracy'
            )
            ->assertJsonMissingPath(
                'data.skills.0.mastery'
            )
            ->assertJsonMissingPath(
                'data.mastery'
            )
            ->assertJsonMissingPath(
                'data.global_score'
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

        $subjectId =
            (string) Str::uuid();

        $curriculumId =
            (string) Str::uuid();

        $curriculumVersionId =
            (string) Str::uuid();

        $topicId =
            (string) Str::uuid();

        $targetSkillId =
            (string) Str::uuid();

        $otherSkillId =
            (string) Str::uuid();

        $practiceActivityId =
            (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' =>
                'A7.6 Subject '
                .Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' =>
                'A7.6 Curriculum '
                .Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'curriculum_versions'
        )->insert([
            'id' =>
                $curriculumVersionId,
            'curriculum_id' =>
                $curriculumId,
            'version_number' => 1,
            'label' => 'A7.6 v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'name' =>
                'A7.6 Topic '
                .Str::random(8),
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            [
                'id' =>
                    $targetSkillId,
                'name' =>
                    'A7.6 Target Skill',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' =>
                    $otherSkillId,
                'name' =>
                    'A7.6 Other Skill',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table(
            'practice_activities'
        )->insert([
            'id' =>
                $practiceActivityId,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'lesson_id' => null,
            'name' =>
                'A7.6 Practice '
                .Str::random(8),
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = app(
            CreateEvidenceScope::class
        )->execute(
            'A7.6 Explicit Scope',
            'Measurement pipeline regression scope',
            [
                'opaque' =>
                    'a7.6-regression',
            ],
            1,
        );

        return [
            'user_id' =>
                $user->id,

            'learner_profile_id' =>
                $learner->id,

            'curriculum_version_id' =>
                $curriculumVersionId,

            'primary_topic_id' =>
                $topicId,

            'target_skill_id' =>
                $targetSkillId,

            'other_skill_id' =>
                $otherSkillId,

            'practice_activity_id' =>
                $practiceActivityId,

            'evidence_scope_id' =>
                $scope->id,
        ];
    }

    /**
     * @param array<int, array{0: string, 1: string}> $classifications
     *
     * @return array{
     *     attempt_id: string,
     *     attempt_response_id: string
     * }
     */
    private function attempt(
        array $fixture,
        array $classifications,
        bool $isCorrect,
    ): array {
        $assessmentItemId =
            (string) Str::uuid();

        $revisionId =
            (string) Str::uuid();

        $attemptId =
            (string) Str::uuid();

        $attemptItemId =
            (string) Str::uuid();

        $responseId =
            (string) Str::uuid();

        DB::table(
            'assessment_items'
        )->insert([
            'id' =>
                $assessmentItemId,

            'curriculum_version_id' =>
                $fixture[
                    'curriculum_version_id'
                ],

            'item_type' =>
                'multiple_choice',

            'internal_label' =>
                'A7.6 Item '
                .Str::random(8),

            'status' => 'draft',

            'published_revision_id' =>
                null,

            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'assessment_item_revisions'
        )->insert([
            'id' =>
                $revisionId,

            'assessment_item_id' =>
                $assessmentItemId,

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
                    [
                        'stem' =>
                            'A7.6 measurement item',
                    ],
                    JSON_THROW_ON_ERROR,
                ),

            'content_schema_version' => 1,

            'scoring_payload' =>
                json_encode(
                    [
                        'correct_option' => 0,
                    ],
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

        DB::table(
            'attempt_items'
        )->insert([
            'id' => $attemptItemId,
            'attempt_id' => $attemptId,

            'assessment_item_revision_id' =>
                $revisionId,

            'assessment_item_id' =>
                $assessmentItemId,

            'curriculum_version_id' =>
                $fixture[
                    'curriculum_version_id'
                ],

            'exam_generation_id' => null,
            'exam_generation_item_id' =>
                null,

            'presentation_position' => 0,

            'presented_payload' =>
                json_encode(
                    [
                        'stem' =>
                            'A7.6 measurement item',
                    ],
                    JSON_THROW_ON_ERROR,
                ),

            'presented_schema_version' => 1,

            'scoring_snapshot' =>
                json_encode(
                    [
                        'correct_option' => 0,
                    ],
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

                'skill_id' =>
                    $skillId,

                'role' =>
                    $role,

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
                json_encode(
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

        /*
         * Seal construction first.
         */
        DB::table('attempts')
            ->where('id', $attemptId)
            ->update([
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        /*
         * Then finalize.
         */
        DB::table('attempts')
            ->where('id', $attemptId)
            ->update([
                'status' => 'submitted',
                'finalized_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'attempt_id' =>
                $attemptId,

            'attempt_response_id' =>
                $responseId,
        ];
    }
}

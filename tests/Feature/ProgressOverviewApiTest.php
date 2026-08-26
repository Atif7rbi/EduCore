<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgressOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_learner_has_zero_progress_counts(): void
    {
        $this->authenticateLearner();

        $this->getJson(
            '/api/progress/overview'
        )
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'learning' => [
                        'started_lessons_count' => 0,
                        'completed_lessons_count' => 0,
                    ],
                    'assessment' => [
                        'attempts_total' => 0,
                        'submitted_attempts' => 0,
                        'abandoned_attempts' => 0,
                        'in_progress_attempts' => 0,
                    ],
                ],
            ]);
    }

    public function test_learning_counts_are_completion_only_not_mastery(): void
    {
        $learner =
            $this->authenticateLearner();

        $versionId =
            $this->curriculumVersion();

        $topicId =
            $this->topic($versionId);

        $revisionOne =
            $this->releasedLessonRevision(
                $versionId,
                $topicId,
                1,
            );

        $revisionTwo =
            $this->releasedLessonRevision(
                $versionId,
                $topicId,
                2,
            );

        $firstProgressId =
            (string) Str::uuid();

        $secondProgressId =
            (string) Str::uuid();

        DB::table('lesson_progresses')->insert([
            [
                'id' => $firstProgressId,
                'learner_profile_id' =>
                    $learner->id,
                'lesson_revision_id' =>
                    $revisionOne,
                'status' => 'in_progress',
                'started_at' => now(),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $secondProgressId,
                'learner_profile_id' =>
                    $learner->id,
                'lesson_revision_id' =>
                    $revisionTwo,
                'status' => 'in_progress',
                'started_at' =>
                    now()->subMinute(),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('lesson_progresses')
            ->where('id', $secondProgressId)
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $this->getJson(
            '/api/progress/overview'
        )
            ->assertOk()
            ->assertJsonPath(
                'data.learning.started_lessons_count',
                2
            )
            ->assertJsonPath(
                'data.learning.completed_lessons_count',
                1
            )
            ->assertJsonMissingPath(
                'data.learning.mastery'
            )
            ->assertJsonMissingPath(
                'data.learning.score'
            );
    }

    public function test_attempt_counts_are_scoped_to_current_learner(): void
    {
        $learner =
            $this->authenticateLearner();

        $other =
            $this->learner();

        $this->attempt(
            $learner->id,
            'in_progress',
        );

        $this->attempt(
            $learner->id,
            'submitted',
        );

        $this->attempt(
            $learner->id,
            'abandoned',
        );

        $this->attempt(
            $other->id,
            'submitted',
        );

        $this->getJson(
            '/api/progress/overview'
        )
            ->assertOk()
            ->assertJsonPath(
                'data.assessment.attempts_total',
                3
            )
            ->assertJsonPath(
                'data.assessment.submitted_attempts',
                1
            )
            ->assertJsonPath(
                'data.assessment.abandoned_attempts',
                1
            )
            ->assertJsonPath(
                'data.assessment.in_progress_attempts',
                1
            );
    }

    public function test_progress_overview_requires_authentication(): void
    {
        $this->getJson(
            '/api/progress/overview'
        )->assertStatus(401);
    }

    public function test_progress_overview_requires_learner_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $this->getJson(
            '/api/progress/overview'
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    private function authenticateLearner(): LearnerProfile
    {
        $learner = $this->learner();

        $this->actingAs(
            $learner->user
        );

        return $learner;
    }

    private function learner(): LearnerProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        return LearnerProfile::query()->create([
            'user_id' => $user->id,
        ])->load('user');
    }

    private function curriculumVersion(): string
    {
        $subjectId = (string) Str::uuid();
        $curriculumId =
            (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' =>
                'Progress Subject '.Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' =>
                'Progress Curriculum '.Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'curriculum_versions'
        )->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Progress v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    private function topic(
        string $versionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $id,
            'curriculum_version_id' =>
                $versionId,
            'name' =>
                'Progress Topic '.Str::random(8),
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function releasedLessonRevision(
        string $versionId,
        string $topicId,
        int $revisionNumber,
    ): string {
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' =>
                $versionId,
            'title' =>
                'Progress Lesson '.Str::random(8),
            'description' => null,
            'status' => 'draft',
            'display_order' =>
                $revisionNumber,
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lesson_revisions')->insert([
            'id' => $revisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' =>
                $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' =>
                json_encode([
                    'blocks' => [],
                ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        DB::table('lesson_revisions')
            ->where('id', $revisionId)
            ->update([
                'released_at' => now(),
            ]);

        return $revisionId;
    }

    private function attempt(
        string $learnerProfileId,
        string $status,
    ): string {
        $versionId =
            $this->curriculumVersion();

        $practiceActivityId =
            (string) Str::uuid();

        DB::table(
            'practice_activities'
        )->insert([
            'id' => $practiceActivityId,
            'curriculum_version_id' =>
                $versionId,
            'lesson_id' => null,
            'name' =>
                'Progress Practice '
                .Str::random(8),
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (string) Str::uuid();

        /*
         * Respect the real Attempt lifecycle:
         *
         * create unsealed
         * -> seal by setting started_at
         * -> optionally finalize
         */
        DB::table('attempts')->insert([
            'id' => $id,
            'learner_profile_id' =>
                $learnerProfileId,
            'exam_generation_id' => null,
            'practice_activity_id' =>
                $practiceActivityId,
            'curriculum_version_id' =>
                $versionId,
            'status' => 'in_progress',
            'started_at' => null,
            'finalized_at' => null,
            'created_at' => now(),
            'updated_at' => null,
        ]);

        DB::table('attempts')
            ->where('id', $id)
            ->update([
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($status !== 'in_progress') {
            DB::table('attempts')
                ->where('id', $id)
                ->update([
                    'status' => $status,
                    'finalized_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $id;
    }
}

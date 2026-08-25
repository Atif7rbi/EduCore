<?php

namespace Tests\Feature;

use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LessonProgressApiTest extends TestCase
{
    public function test_authenticated_learner_can_start_published_lesson(): void
    {
        [$user, $learner] = $this->createLearner();
        [$lessonId, $revisionId] = $this->createPublishedLesson();

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'in_progress'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'in_progress',
            ]
        );
    }

    public function test_start_does_not_accept_learner_identity_from_body(): void
    {
        [$user, $learner] = $this->createLearner();
        [, $otherLearner] = $this->createLearner();

        [$lessonId, $revisionId] = $this->createPublishedLesson();

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress",
            [
                'learner_profile_id' => $otherLearner->id,
            ]
        )->assertOk();

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
            ]
        );

        $this->assertDatabaseMissing(
            'lesson_progresses',
            [
                'learner_profile_id' => $otherLearner->id,
                'lesson_revision_id' => $revisionId,
            ]
        );
    }

    public function test_authenticated_learner_can_complete_published_lesson(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] = $this->createPublishedLesson();

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertOk();

        $response = $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'completed'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'completed',
            ]
        );

        $this->assertNotNull(
            $response->json('data.completed_at')
        );
    }

    public function test_complete_without_prior_start_creates_completed_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] = $this->createPublishedLesson();

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'completed'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'completed',
            ]
        );
    }

    public function test_progress_routes_require_authentication(): void
    {
        $lessonId = (string) Str::uuid();

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertStatus(401);

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )->assertStatus(401);
    }

    public function test_progress_routes_require_learner_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $lessonId = (string) Str::uuid();

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    public function test_draft_lesson_is_not_visible_to_progress_endpoint(): void
    {
        [$user] = $this->createLearner();

        $lessonId = $this->createDraftLesson();

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    /**
     * @return array{User, LearnerProfile}
     */
    private function createLearner(): array
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        return [$user, $learner];
    }

    /**
     * @return array{string, string}
     */
    private function createPublishedLesson(): array
    {
        [
            $lessonId,
            $revisionId,
            $versionId,
        ] = $this->createLessonFixture();

        (new ReleaseLessonRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($revisionId);

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'published',
                'published_revision_id' => $revisionId,
                'updated_at' => now(),
            ]);

        (new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($versionId);

        return [
            $lessonId,
            $revisionId,
        ];
    }

    private function createDraftLesson(): string
    {
        [
            $lessonId,
        ] = $this->createLessonFixture();

        return $lessonId;
    }

    /**
     * @return array{string, string, string}
     */
    private function createLessonFixture(): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Progress API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Progress API Curriculum {$curriculumId}",
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
            'name' => "Progress API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => 'Progress API Lesson',
            'description' => null,
            'status' => 'draft',
            'display_order' => 0,
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lesson_revisions')->insert([
            'id' => $revisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' => json_encode([
                'blocks' => [
                    [
                        'type' => 'text',
                        'value' => 'Progress API content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        return [
            $lessonId,
            $revisionId,
            $versionId,
        ];
    }
}

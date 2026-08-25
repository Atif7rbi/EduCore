<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadApiTest extends TestCase
{
    public function test_catalog_routes_require_authentication(): void
    {
        $id = (string) Str::uuid();

        $this->getJson("/api/curriculum-versions/{$id}")
            ->assertStatus(401);

        $this->getJson("/api/lessons/{$id}")
            ->assertStatus(401);

        $this->getJson("/api/practice-activities/{$id}")
            ->assertStatus(401);
    }

    public function test_catalog_routes_require_learner_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $id = (string) Str::uuid();

        $this->getJson("/api/curriculum-versions/{$id}")
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    public function test_published_curriculum_version_returns_topics_in_display_order(): void
    {
        $this->authenticateLearner();

        $versionId = $this->createCurriculumVersion();

        $topicLater = $this->createTopic(
            $versionId,
            'Later Topic',
            2,
        );

        $topicFirst = $this->createTopic(
            $versionId,
            'First Topic',
            1,
        );

        $this->publishCurriculumVersion($versionId);

        $this->getJson(
            "/api/curriculum-versions/{$versionId}"
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.topics.0.id', $topicFirst)
            ->assertJsonPath('data.topics.1.id', $topicLater);
    }

    public function test_draft_and_retired_curriculum_versions_are_not_visible(): void
    {
        $this->authenticateLearner();

        $draftId = $this->createCurriculumVersion();

        $this->getJson(
            "/api/curriculum-versions/{$draftId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $retiredId = $this->createCurriculumVersion();

        DB::table('curriculum_versions')
            ->where('id', $retiredId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        DB::table('curriculum_versions')
            ->where('id', $retiredId)
            ->update([
                'status' => 'retired',
                'updated_at' => now(),
            ]);

        $this->getJson(
            "/api/curriculum-versions/{$retiredId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_curriculum_lessons_returns_only_published_lessons(): void
    {
        $this->authenticateLearner();

        $versionId = $this->createCurriculumVersion();
        $topicId = $this->createTopic(
            $versionId,
            'Main Topic',
            0,
        );

        $publishedLater = $this->createPublishedLesson(
            $versionId,
            $topicId,
            'Published Later',
            2,
        );

        $publishedFirst = $this->createPublishedLesson(
            $versionId,
            $topicId,
            'Published First',
            1,
        );

        $this->createDraftLesson(
            $versionId,
            'Draft Hidden',
            0,
        );

        $this->publishCurriculumVersion($versionId);

        $response = $this->getJson(
            "/api/curriculum-versions/{$versionId}/lessons"
        );

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $publishedFirst)
            ->assertJsonPath('data.1.id', $publishedLater);
    }

    public function test_published_lesson_returns_only_active_practice_activities(): void
    {
        $this->authenticateLearner();

        $versionId = $this->createCurriculumVersion();
        $topicId = $this->createTopic(
            $versionId,
            'Lesson Topic',
            0,
        );

        $lessonId = $this->createPublishedLesson(
            $versionId,
            $topicId,
            'Published Lesson',
            0,
        );

        [$revisionId, $itemId] =
            $this->createReleasedAssessmentRevision(
                $versionId,
                $topicId,
            );

        $activeActivityId = $this->createPracticeActivity(
            $versionId,
            $lessonId,
            $revisionId,
            $itemId,
            true,
        );

        $archivedActivityId = $this->createPracticeActivity(
            $versionId,
            $lessonId,
            $revisionId,
            $itemId,
            false,
        );

        $this->publishCurriculumVersion($versionId);

        $response = $this->getJson(
            "/api/lessons/{$lessonId}"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $lessonId)
            ->assertJsonCount(1, 'data.practice_activities')
            ->assertJsonPath(
                'data.practice_activities.0.id',
                $activeActivityId
            );

        $ids = collect(
            $response->json('data.practice_activities')
        )->pluck('id')->all();

        $this->assertNotContains(
            $archivedActivityId,
            $ids
        );
    }

    public function test_draft_lesson_and_lesson_in_unpublished_curriculum_are_not_visible(): void
    {
        $this->authenticateLearner();

        $publishedVersionId = $this->createCurriculumVersion();
        $publishedTopicId = $this->createTopic(
            $publishedVersionId,
            'Published Topic',
            0,
        );

        $draftLessonId = $this->createDraftLesson(
            $publishedVersionId,
            'Draft Lesson',
            0,
        );

        $this->publishCurriculumVersion(
            $publishedVersionId
        );

        $this->getJson(
            "/api/lessons/{$draftLessonId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $draftVersionId = $this->createCurriculumVersion();
        $draftTopicId = $this->createTopic(
            $draftVersionId,
            'Draft Curriculum Topic',
            0,
        );

        $publishedLessonInDraftVersion =
            $this->createPublishedLesson(
                $draftVersionId,
                $draftTopicId,
                'Hidden Published Lesson',
                0,
            );

        $this->getJson(
            "/api/lessons/{$publishedLessonInDraftVersion}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_active_practice_activity_is_visible_only_in_published_curriculum(): void
    {
        $this->authenticateLearner();

        $publishedVersionId =
            $this->createCurriculumVersion();

        $publishedTopicId = $this->createTopic(
            $publishedVersionId,
            'Practice Topic',
            0,
        );

        [$publishedRevisionId, $publishedItemId] =
            $this->createReleasedAssessmentRevision(
                $publishedVersionId,
                $publishedTopicId,
            );

        $visibleActivityId = $this->createPracticeActivity(
            $publishedVersionId,
            null,
            $publishedRevisionId,
            $publishedItemId,
            true,
        );

        $this->publishCurriculumVersion(
            $publishedVersionId
        );

        $this->getJson(
            "/api/practice-activities/{$visibleActivityId}"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $visibleActivityId)
            ->assertJsonPath('data.status', 'active');

        $draftVersionId =
            $this->createCurriculumVersion();

        $draftTopicId = $this->createTopic(
            $draftVersionId,
            'Hidden Practice Topic',
            0,
        );

        [$draftRevisionId, $draftItemId] =
            $this->createReleasedAssessmentRevision(
                $draftVersionId,
                $draftTopicId,
            );

        $hiddenActivityId = $this->createPracticeActivity(
            $draftVersionId,
            null,
            $draftRevisionId,
            $draftItemId,
            true,
        );

        $this->getJson(
            "/api/practice-activities/{$hiddenActivityId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_archived_practice_activity_is_not_visible(): void
    {
        $this->authenticateLearner();

        $versionId = $this->createCurriculumVersion();
        $topicId = $this->createTopic(
            $versionId,
            'Archived Practice Topic',
            0,
        );

        [$revisionId, $itemId] =
            $this->createReleasedAssessmentRevision(
                $versionId,
                $topicId,
            );

        $activityId = $this->createPracticeActivity(
            $versionId,
            null,
            $revisionId,
            $itemId,
            false,
        );

        $this->publishCurriculumVersion($versionId);

        $this->getJson(
            "/api/practice-activities/{$activityId}"
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_missing_visible_resources_return_not_found(): void
    {
        $this->authenticateLearner();

        $missingId = (string) Str::uuid();

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

    private function authenticateLearner(): LearnerProfile
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        return $learner;
    }

    private function createCurriculumVersion(): string
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Learner Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Learner Curriculum {$curriculumId}",
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

    private function publishCurriculumVersion(
        string $versionId,
    ): void {
        (new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($versionId);
    }

    private function createTopic(
        string $versionId,
        string $name,
        int $displayOrder,
    ): string {
        $topicId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => $name,
            'display_order' => $displayOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $topicId;
    }

    private function createDraftLesson(
        string $versionId,
        string $title,
        int $displayOrder,
    ): string {
        $lessonId = (string) Str::uuid();

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => $title,
            'description' => null,
            'status' => 'draft',
            'display_order' => $displayOrder,
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $lessonId;
    }

    private function createPublishedLesson(
        string $versionId,
        string $topicId,
        string $title,
        int $displayOrder,
    ): string {
        $lessonId = $this->createDraftLesson(
            $versionId,
            $title,
            $displayOrder,
        );

        $revisionId = (string) Str::uuid();

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
                        'value' => 'Learner lesson content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

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

        return $lessonId;
    }

    /**
     * @return array{string, string}
     */
    private function createReleasedAssessmentRevision(
        string $versionId,
        string $topicId,
    ): array {
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Learner Skill {$skillId}",
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
            'internal_label' => "Learner Item {$itemId}",
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
                'stem' => '10 + 5 = ?',
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

        DB::table(
            'assessment_item_revision_skills'
        )->insert([
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

        return [$revisionId, $itemId];
    }

    private function createPracticeActivity(
        string $versionId,
        ?string $lessonId,
        string $revisionId,
        string $itemId,
        bool $active,
    ): string {
        $activityId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => $lessonId,
            'name' => "Learner Practice {$activityId}",
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

        if ($active) {
            DB::table('practice_activities')
                ->where('id', $activityId)
                ->update([
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
        }

        return $activityId;
    }
}

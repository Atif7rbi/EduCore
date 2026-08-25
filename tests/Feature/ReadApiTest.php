<?php

namespace Tests\Feature;

use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadApiTest extends TestCase
{
    public function test_curriculum_version_returns_topics_in_display_order(): void
    {
        [$versionId] = $this->createCurriculumVersion();

        $topicLater = (string) Str::uuid();
        $topicFirst = (string) Str::uuid();

        DB::table('topics')->insert([
            [
                'id' => $topicLater,
                'curriculum_version_id' => $versionId,
                'name' => 'Later Topic',
                'display_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $topicFirst,
                'curriculum_version_id' => $versionId,
                'name' => 'First Topic',
                'display_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson(
            "/api/curriculum-versions/{$versionId}"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $versionId)
            ->assertJsonPath('data.topics.0.id', $topicFirst)
            ->assertJsonPath('data.topics.0.display_order', 1)
            ->assertJsonPath('data.topics.1.id', $topicLater)
            ->assertJsonPath('data.topics.1.display_order', 2);
    }

    public function test_curriculum_lessons_are_returned_in_display_order(): void
    {
        [$versionId] = $this->createCurriculumVersion();

        $lessonLater = $this->createLesson(
            $versionId,
            title: 'Later Lesson',
            displayOrder: 2,
        );

        $lessonFirst = $this->createLesson(
            $versionId,
            title: 'First Lesson',
            displayOrder: 1,
        );

        $this->getJson(
            "/api/curriculum-versions/{$versionId}/lessons"
        )
            ->assertOk()
            ->assertJsonPath('data.0.id', $lessonFirst)
            ->assertJsonPath('data.0.display_order', 1)
            ->assertJsonPath('data.1.id', $lessonLater)
            ->assertJsonPath('data.1.display_order', 2);
    }

    public function test_lesson_read_returns_published_revision_and_practice_activities(): void
    {
        [$versionId] = $this->createCurriculumVersion();

        $topicId = $this->createTopic($versionId);

        $lessonId = $this->createLesson(
            $versionId,
            title: 'Published Lesson',
            displayOrder: 0,
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
                    ['type' => 'text', 'value' => 'Lesson content'],
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

        $activityId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => $lessonId,
            'name' => 'Lesson Practice',
            'description' => 'Practice description',
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/lessons/{$lessonId}"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $lessonId)
            ->assertJsonPath(
                'data.published_revision.id',
                $revisionId
            )
            ->assertJsonPath(
                'data.published_revision.content_payload.blocks.0.value',
                'Lesson content'
            )
            ->assertJsonPath(
                'data.practice_activities.0.id',
                $activityId
            );

        $this->assertArrayNotHasKey(
            'scoring_payload',
            $response->json('data.published_revision')
        );
    }

    public function test_practice_activity_read_returns_membership_without_scoring_truth(): void
    {
        [$versionId] = $this->createCurriculumVersion();

        $activityId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => 'Standalone Practice',
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$revisionId, $itemId] =
            $this->createAssessmentRevision($versionId);

        $membershipId = (string) Str::uuid();

        DB::table('practice_activity_items')->insert([
            'id' => $membershipId,
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'display_order' => 0,
            'created_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/practice-activities/{$activityId}"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $activityId)
            ->assertJsonPath(
                'data.items.0.id',
                $membershipId
            )
            ->assertJsonPath(
                'data.items.0.assessment_item_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.items.0.assessment_item_id',
                $itemId
            );

        $item = $response->json('data.items.0');

        $this->assertArrayNotHasKey(
            'scoring_payload',
            $item
        );

        $this->assertArrayNotHasKey(
            'scoring_snapshot',
            $item
        );
    }

    public function test_missing_read_resources_return_not_found(): void
    {
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

    /**
     * @return array{string, string, string}
     */
    private function createCurriculumVersion(): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Read API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Read API Curriculum {$curriculumId}",
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

        return [
            $versionId,
            $curriculumId,
            $subjectId,
        ];
    }

    private function createTopic(string $versionId): string
    {
        $topicId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Read API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $topicId;
    }

    private function createLesson(
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

    /**
     * @return array{string, string}
     */
    private function createAssessmentRevision(
        string $versionId,
    ): array {
        $topicId = $this->createTopic($versionId);
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' => $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' => "Read API Item {$itemId}",
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

        return [
            $revisionId,
            $itemId,
        ];
    }
}

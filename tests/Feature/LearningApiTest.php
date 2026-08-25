<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningApiTest extends TestCase
{
    public function test_lesson_revision_can_be_released_via_api(): void
    {
        [, $revisionId] = $this->createLessonFixture();

        $response = $this->postJson(
            "/api/lesson-revisions/{$revisionId}/release"
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $revisionId)
            ->assertJsonPath('data.revision_number', 1);

        $this->assertNotNull(
            $response->json('data.released_at')
        );

        $this->assertDatabaseMissing('lesson_revisions', [
            'id' => $revisionId,
            'released_at' => null,
        ]);
    }

    public function test_released_revision_can_publish_lesson_via_api(): void
    {
        [$lessonId, $revisionId] = $this->createLessonFixture();

        $this->postJson(
            "/api/lesson-revisions/{$revisionId}/release"
        )->assertOk();

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
            [
                'published_revision_id' => $revisionId,
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.id', $lessonId)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath(
                'data.published_revision_id',
                $revisionId
            );

        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'status' => 'published',
            'published_revision_id' => $revisionId,
        ]);
    }

    public function test_unreleased_revision_cannot_publish_lesson_via_api(): void
    {
        [$lessonId, $revisionId] = $this->createLessonFixture();

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
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
        [$lessonId] = $this->createLessonFixture();

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
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

    public function test_published_lesson_can_be_retired_via_api(): void
    {
        [$lessonId, $revisionId] = $this->createLessonFixture();

        $this->postJson(
            "/api/lesson-revisions/{$revisionId}/release"
        )->assertOk();

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
            [
                'published_revision_id' => $revisionId,
            ],
        )->assertOk();

        $this->postJson(
            "/api/lessons/{$lessonId}/retire"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $lessonId)
            ->assertJsonPath('data.status', 'retired');

        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'status' => 'retired',
        ]);
    }

    public function test_draft_lesson_cannot_be_retired_via_api(): void
    {
        [$lessonId] = $this->createLessonFixture();

        $this->postJson(
            "/api/lessons/{$lessonId}/retire"
        )
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);
    }

    public function test_missing_lesson_returns_not_found(): void
    {
        $lessonId = (string) Str::uuid();

        $this->postJson(
            "/api/lessons/{$lessonId}/retire"
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
            "/api/lesson-revisions/{$revisionId}/release"
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
            'name' => "Learning API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Learning API Curriculum {$curriculumId}",
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
            'name' => "Learning API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => "Learning API Lesson {$lessonId}",
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
                'type' => 'lesson',
                'blocks' => [],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        return [$lessonId, $revisionId];
    }
}

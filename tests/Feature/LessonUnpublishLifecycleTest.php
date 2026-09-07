<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LessonUnpublishLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_lesson_can_be_unpublished_and_republished_without_recreating_content(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => 'Lifecycle Subject '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => 'Lifecycle Curriculum '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Lifecycle v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => 'Lifecycle Topic',
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => 'Lifecycle Lesson',
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
                        'value' => 'Reusable lesson content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        $this->postJson(
            "/api/lesson-revisions/{$revisionId}/release"
        )
            ->assertOk()
            ->assertJsonPath('data.id', $revisionId);

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
            ['published_revision_id' => $revisionId]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_revision_id', $revisionId);

        $this->postJson(
            "/api/lessons/{$lessonId}/unpublish"
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'unpublished')
            ->assertJsonPath('data.published_revision_id', $revisionId);

        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'status' => 'unpublished',
            'published_revision_id' => $revisionId,
        ]);

        $this->assertDatabaseHas('lesson_revisions', [
            'id' => $revisionId,
            'lesson_id' => $lessonId,
        ]);

        $this->postJson(
            "/api/lessons/{$lessonId}/publish",
            ['published_revision_id' => $revisionId]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_revision_id', $revisionId);

        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseCount('lesson_revisions', 1);
    }
}

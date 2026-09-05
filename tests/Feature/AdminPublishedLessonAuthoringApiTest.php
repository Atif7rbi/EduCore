<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPublishedLessonAuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_lesson_in_draft_curriculum_can_be_edited_without_replacing_published_content(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        [$versionId, $lessonId, $topicId, $publishedRevisionId] =
            $this->publishedLessonFixture();

        $this->putJson(
            "/api/admin/lessons/{$lessonId}",
            [
                'title' => 'Updated lesson title',
                'description' => 'Updated description',
                'display_order' => 1,
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath(
                'data.published_revision_id',
                $publishedRevisionId
            )
            ->assertJsonPath(
                'data.title',
                'Updated lesson title'
            );

        $response = $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            [
                'revision_number' => 2,
                'primary_topic_id' => $topicId,
                'content_payload' => [
                    'blocks' => [
                        [
                            'type' => 'text',
                            'value' => 'Unpublished edits',
                        ],
                    ],
                ],
                'content_schema_version' => 1,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.lesson_id', $lessonId)
            ->assertJsonPath('data.revision_number', 2)
            ->assertJsonPath('data.released_at', null);

        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'status' => 'published',
            'published_revision_id' => $publishedRevisionId,
        ]);

        $this->assertDatabaseHas('lesson_revisions', [
            'lesson_id' => $lessonId,
            'revision_number' => 2,
            'released_at' => null,
        ]);
    }

    public function test_retired_lesson_remains_frozen(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        [$versionId, $lessonId, $topicId] =
            $this->publishedLessonFixture();

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'retired',
                'updated_at' => now(),
            ]);

        $this->putJson(
            "/api/admin/lessons/{$lessonId}",
            [
                'title' => 'Forbidden',
                'description' => null,
                'display_order' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'lesson_retired');

        $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            [
                'revision_number' => 2,
                'primary_topic_id' => $topicId,
                'content_payload' => [
                    'blocks' => [],
                ],
                'content_schema_version' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'lesson_retired');
    }

    private function publishedLessonFixture(): array
    {
        $subjectId = (string) Str::uuid();
        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => 'Subject '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $curriculumId = (string) Str::uuid();
        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => 'Curriculum '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = (string) Str::uuid();
        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Working draft',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $topicId = (string) Str::uuid();
        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => 'Ratios',
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lessonId = (string) Str::uuid();
        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => 'Published lesson',
            'description' => null,
            'status' => 'draft',
            'display_order' => 1,
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publishedRevisionId = (string) Str::uuid();
        DB::table('lesson_revisions')->insert([
            'id' => $publishedRevisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' => json_encode([
                'blocks' => [
                    [
                        'type' => 'text',
                        'value' => 'Published content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => now(),
            'created_at' => now(),
        ]);

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'published',
                'published_revision_id' => $publishedRevisionId,
                'updated_at' => now(),
            ]);

        return [
            $versionId,
            $lessonId,
            $topicId,
            $publishedRevisionId,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminLessonAuthoringApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_update_draft_lesson(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');

        $response = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/lessons",
            [
                'title' => 'Ratios',
                'description' => 'Ratio fundamentals',
                'display_order' => 2,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            )
            ->assertJsonPath(
                'data.status',
                'draft'
            )
            ->assertJsonPath(
                'data.display_order',
                2
            );

        $lessonId = $response->json('data.id');

        $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/lessons"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $lessonId,
                'title' => 'Ratios',
            ]);

        $this->putJson(
            "/api/admin/lessons/{$lessonId}",
            [
                'title' => 'Ratios and Proportions',
                'description' =>
                    'Clarified fundamentals',
                'display_order' => 3,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.title',
                'Ratios and Proportions'
            )
            ->assertJsonPath(
                'data.display_order',
                3
            );
    }

    public function test_lesson_mutation_is_frozen_outside_draft_curriculum_version(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $lessonId = $this->lesson($versionId);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/lessons",
            [
                'title' => 'Forbidden',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );

        $this->putJson(
            "/api/admin/lessons/{$lessonId}",
            [
                'title' => 'Changed',
                'description' => null,
                'display_order' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_admin_can_create_and_list_unreleased_lesson_revision(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $lessonId = $this->lesson($versionId);
        $topicId = $this->topic($versionId);

        $response = $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => $topicId,
                'content_payload' => [
                    'blocks' => [
                        [
                            'type' => 'text',
                            'text' => 'Ratio lesson',
                        ],
                    ],
                ],
                'content_schema_version' => 1,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.lesson_id',
                $lessonId
            )
            ->assertJsonPath(
                'data.revision_number',
                1
            )
            ->assertJsonPath(
                'data.primary_topic_id',
                $topicId
            )
            ->assertJsonPath(
                'data.released_at',
                null
            );

        $revisionId = $response->json('data.id');

        $this->getJson(
            "/api/admin/lessons/{$lessonId}/revisions"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $revisionId,
                'revision_number' => 1,
            ]);
    }

    public function test_revision_number_is_unique_per_lesson(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $lessonId = $this->lesson($versionId);
        $topicId = $this->topic($versionId);

        $payload = [
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' => [
                'blocks' => [],
            ],
            'content_schema_version' => 1,
        ];

        $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            $payload
        )->assertCreated();

        $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            $payload
        )->assertStatus(409);
    }

    public function test_primary_topic_from_different_curriculum_version_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionOne = $this->version('draft');
        $versionTwo = $this->version('draft');

        $lessonId = $this->lesson($versionOne);
        $wrongTopic = $this->topic($versionTwo);

        $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => $wrongTopic,
                'content_payload' => [
                    'blocks' => [],
                ],
                'content_schema_version' => 1,
            ]
        )->assertStatus(409);
    }

    public function test_revision_authoring_is_frozen_after_curriculum_publish(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $lessonId = $this->lesson($versionId);
        $topicId = $this->topic($versionId);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/lessons/{$lessonId}/revisions",
            [
                'revision_number' => 1,
                'primary_topic_id' => $topicId,
                'content_payload' => [
                    'blocks' => [],
                ],
                'content_schema_version' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_lesson_authoring_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/curriculum-versions/{$id}/lessons"
        )
            ->assertStatus(401)
            ->assertJsonPath(
                'error.code',
                'unauthenticated'
            );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function version(
        string $status,
    ): string {
        $subjectId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => 'Subject '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $curriculumId = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => 'Curriculum '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Version '.Str::random(8),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    private function lesson(
        string $versionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('lessons')->insert([
            'id' => $id,
            'curriculum_version_id' => $versionId,
            'title' => 'Lesson '.Str::random(12),
            'description' => null,
            'status' => 'draft',
            'display_order' => 0,
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function topic(
        string $versionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $id,
            'curriculum_version_id' => $versionId,
            'name' => 'Topic '.Str::random(12),
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

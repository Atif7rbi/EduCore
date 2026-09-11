<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminLessonRevisionSkillApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_and_list_skill_classification(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $lessonId = $this->lesson($versionId);
        $topicId = $this->topic($versionId);
        $revisionId = $this->revision(
            $lessonId,
            $versionId,
            $topicId,
        );
        $placementId =
            $this->placement($versionId);

        $response = $this->postJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.skill_version_placement_id',
                $placementId
            )
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            );

        $classificationId =
            $response->json('data.id');

        $this->getJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $classificationId,
                'skill_version_placement_id' =>
                    $placementId,
            ]);
    }

    public function test_same_placement_cannot_be_added_twice(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $lessonId = $this->lesson($versionId);
        $topicId = $this->topic($versionId);
        $revisionId = $this->revision(
            $lessonId,
            $versionId,
            $topicId,
        );
        $placementId =
            $this->placement($versionId);

        $payload = [
            'skill_version_placement_id' =>
                $placementId,
        ];

        $this->postJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills",
            $payload
        )->assertCreated();

        $this->postJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills",
            $payload
        )->assertStatus(409);
    }

    public function test_cross_version_placement_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionOne = $this->version();
        $versionTwo = $this->version();

        $lessonId = $this->lesson($versionOne);
        $topicId = $this->topic($versionOne);

        $revisionId = $this->revision(
            $lessonId,
            $versionOne,
            $topicId,
        );

        $wrongPlacement =
            $this->placement($versionTwo);

        $this->postJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $wrongPlacement,
            ]
        )->assertStatus(409);
    }

    public function test_released_revision_classification_is_frozen(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $lessonId = $this->lesson($versionId);
        $topicId = $this->topic($versionId);

        $revisionId = $this->revision(
            $lessonId,
            $versionId,
            $topicId,
        );

        $placementId =
            $this->placement($versionId);

        $classification =
            $this->postJson(
                "/api/admin/lesson-revisions/{$revisionId}/skills",
                [
                    'skill_version_placement_id' =>
                        $placementId,
                ]
            )->assertCreated();

        $classificationId =
            $classification->json('data.id');

        DB::table('lesson_revisions')
            ->where('id', $revisionId)
            ->update([
                'released_at' => now(),
            ]);

        $newPlacement =
            $this->placement($versionId);

        $this->postJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $newPlacement,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'lesson_revision_released'
            );

        $this->deleteJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills/{$classificationId}"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'lesson_revision_released'
            );
    }

    public function test_admin_can_remove_unreleased_revision_skill(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $lessonId = $this->lesson($versionId);
        $topicId = $this->topic($versionId);

        $revisionId = $this->revision(
            $lessonId,
            $versionId,
            $topicId,
        );

        $placementId =
            $this->placement($versionId);

        $classification =
            $this->postJson(
                "/api/admin/lesson-revisions/{$revisionId}/skills",
                [
                    'skill_version_placement_id' =>
                        $placementId,
                ]
            )->assertCreated();

        $classificationId =
            $classification->json('data.id');

        $this->deleteJson(
            "/api/admin/lesson-revisions/{$revisionId}/skills/{$classificationId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.deleted',
                true
            );
    }

    public function test_classification_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/lesson-revisions/{$id}/skills"
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

    private function version(): string
    {
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
            'status' => 'draft',
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

    private function revision(
        string $lessonId,
        string $versionId,
        string $topicId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('lesson_revisions')->insert([
            'id' => $id,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' => json_encode([
                'blocks' => [],
            ]),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function placement(
        string $versionId,
    ): string {
        $skillId = (string) Str::uuid();

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => 'Skill '.Str::random(12),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $placementId = (string) Str::uuid();

        DB::table(
            'skill_version_placements'
        )->insert([
            'id' => $placementId,
            'skill_id' => $skillId,
            'curriculum_version_id' =>
                $versionId,
            'created_at' => now(),
        ]);

        return $placementId;
    }
}

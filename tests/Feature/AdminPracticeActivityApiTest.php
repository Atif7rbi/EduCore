<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPracticeActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_update_archived_activity(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $lessonId = $this->lesson($versionId);

        $response = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/practice-activities",
            [
                'lesson_id' => $lessonId,
                'name' => 'Ratios Practice',
                'description' => 'Practice set',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            )
            ->assertJsonPath(
                'data.lesson_id',
                $lessonId
            )
            ->assertJsonPath(
                'data.status',
                'archived'
            )
            ->assertJsonPath(
                'data.items_count',
                0
            );

        $activityId =
            $response->json('data.id');

        $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/practice-activities"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $activityId,
                'name' => 'Ratios Practice',
            ]);

        $this->putJson(
            "/api/admin/practice-activities/{$activityId}",
            [
                'lesson_id' => $lessonId,
                'name' => 'Ratios Practice Updated',
                'description' =>
                    'Updated practice set',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Ratios Practice Updated'
            );
    }

    public function test_cross_version_lesson_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionOne = $this->version('draft');
        $versionTwo = $this->version('draft');

        $wrongLesson =
            $this->lesson($versionTwo);

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionOne}/practice-activities",
            [
                'lesson_id' => $wrongLesson,
                'name' => 'Invalid Practice',
                'description' => null,
            ]
        )->assertStatus(409);
    }

    public function test_empty_archived_activity_cannot_be_activated(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');

        $activityId =
            $this->activity(
                $versionId,
                'archived'
            );

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/activate"
        )->assertStatus(409);
    }

    public function test_activity_with_unreleased_revision_cannot_be_activated(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');

        $activityId =
            $this->activity(
                $versionId,
                'archived'
            );

        $itemId = (string) Str::uuid();

        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' =>
                $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' =>
                'Item '.Str::random(12),
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revisionId = (string) Str::uuid();

        DB::table(
            'assessment_item_revisions'
        )->insert([
            'id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' =>
                $versionId,
            'revision_number' => 1,
            'primary_topic_id' => null,
            'difficulty' => 'easy',
            'content_payload' =>
                json_encode([
                    'prompt' => 'Question',
                ]),
            'content_schema_version' => 1,
            'scoring_payload' =>
                json_encode([
                    'correct_choice' => 0,
                ]),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        DB::table(
            'practice_activity_items'
        )->insert([
            'id' => (string) Str::uuid(),
            'practice_activity_id' =>
                $activityId,
            'assessment_item_revision_id' =>
                $revisionId,
            'assessment_item_id' =>
                $itemId,
            'curriculum_version_id' =>
                $versionId,
            'display_order' => 0,
            'created_at' => now(),
        ]);

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/activate"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'practice_activity_contains_unreleased_revision'
            );

        $this->assertDatabaseHas(
            'practice_activities',
            [
                'id' => $activityId,
                'status' => 'archived',
            ]
        );
    }

    public function test_active_activity_can_be_archived(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');

        $activityId =
            $this->completeActivity(
                $versionId
            );

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/archive"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'archived'
            );
    }

    public function test_active_activity_metadata_cannot_be_edited(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');

        $activityId =
            $this->completeActivity(
                $versionId
            );

        $this->putJson(
            "/api/admin/practice-activities/{$activityId}",
            [
                'lesson_id' => null,
                'name' => 'Changed',
                'description' => null,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'practice_activity_not_archived'
            );
    }

    public function test_activity_authoring_is_frozen_after_curriculum_publish(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');

        $activityId =
            $this->activity(
                $versionId,
                'archived'
            );

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/practice-activities",
            [
                'lesson_id' => null,
                'name' => 'Forbidden',
                'description' => null,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );

        $this->putJson(
            "/api/admin/practice-activities/{$activityId}",
            [
                'lesson_id' => null,
                'name' => 'Changed',
                'description' => null,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_practice_authoring_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/curriculum-versions/{$id}/practice-activities"
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
            'curriculum_version_id' =>
                $versionId,
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

    private function activity(
        string $versionId,
        string $status,
    ): string {
        $id = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $id,
            'curriculum_version_id' =>
                $versionId,
            'lesson_id' => null,
            'name' => 'Practice '.Str::random(12),
            'description' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function completeActivity(
        string $versionId,
    ): string {
        $activityId =
            $this->activity(
                $versionId,
                'archived'
            );

        $itemId = (string) Str::uuid();

        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' =>
                $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' =>
                'Item '.Str::random(12),
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $revisionId = (string) Str::uuid();

        DB::table(
            'assessment_item_revisions'
        )->insert([
            'id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' =>
                $versionId,
            'revision_number' => 1,
            'primary_topic_id' => null,
            'difficulty' => 'easy',
            'content_payload' =>
                json_encode([
                    'prompt' => 'Question',
                ]),
            'content_schema_version' => 1,
            'scoring_payload' =>
                json_encode([
                    'correct_choice' => 0,
                ]),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

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

        DB::table(
            'assessment_item_revision_skills'
        )->insert([
            'id' => (string) Str::uuid(),
            'assessment_item_revision_id' =>
                $revisionId,
            'skill_version_placement_id' =>
                $placementId,
            'curriculum_version_id' =>
                $versionId,
            'role' => 'primary',
            'created_at' => now(),
        ]);

        DB::table(
            'assessment_item_revisions'
        )
            ->where('id', $revisionId)
            ->update([
                'released_at' => now(),
            ]);

        DB::table(
            'practice_activity_items'
        )->insert([
            'id' => (string) Str::uuid(),
            'practice_activity_id' =>
                $activityId,
            'assessment_item_revision_id' =>
                $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' =>
                $versionId,
            'display_order' => 0,
            'created_at' => now(),
        ]);

        DB::table('practice_activities')
            ->where('id', $activityId)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        return $activityId;
    }
}

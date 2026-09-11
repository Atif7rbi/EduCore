<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPracticeActivityMembershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_and_list_released_revision(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $activityId = $this->activity(
            $versionId,
            'archived',
        );

        [$itemId, $revisionId] =
            $this->releasedRevision(
                $versionId
            );

        $response = $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.practice_activity_id',
                $activityId
            )
            ->assertJsonPath(
                'data.assessment_item_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.assessment_item_id',
                $itemId
            )
            ->assertJsonPath(
                'data.display_order',
                0
            );

        $membershipId =
            $response->json('data.id');

        $this->getJson(
            "/api/admin/practice-activities/{$activityId}/items"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $membershipId,
                'assessment_item_revision_id' =>
                    $revisionId,
            ]);
    }

    public function test_admin_can_build_archived_activity_then_activate_it(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();

        $activityId = $this->activity(
            $versionId,
            'archived',
        );

        [, $revisionId] =
            $this->releasedRevision(
                $versionId
            );

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        )->assertCreated();

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/activate"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'active'
            )
            ->assertJsonPath(
                'data.items_count',
                1
            );
    }

    public function test_unreleased_revision_cannot_survive_active_membership(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();

        $activityId = $this->activity(
            $versionId,
            'active',
        );

        [$itemId, $revisionId] =
            $this->unreleasedRevision(
                $versionId
            );

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        )->assertStatus(409);

        $this->assertDatabaseMissing(
            'practice_activity_items',
            [
                'practice_activity_id' =>
                    $activityId,
                'assessment_item_revision_id' =>
                    $revisionId,
                'assessment_item_id' =>
                    $itemId,
            ]
        );
    }

    public function test_cross_version_revision_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionOne = $this->version();
        $versionTwo = $this->version();

        $activityId = $this->activity(
            $versionOne,
            'archived',
        );

        [, $revisionId] =
            $this->releasedRevision(
                $versionTwo
            );

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        )->assertStatus(409);
    }

    public function test_duplicate_revision_and_duplicate_order_are_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();

        $activityId = $this->activity(
            $versionId,
            'archived',
        );

        [, $revisionOne] =
            $this->releasedRevision(
                $versionId
            );

        [, $revisionTwo] =
            $this->releasedRevision(
                $versionId
            );

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionOne,
                'display_order' => 0,
            ]
        )->assertCreated();

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionOne,
                'display_order' => 1,
            ]
        )->assertStatus(409);

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionTwo,
                'display_order' => 0,
            ]
        )->assertStatus(409);
    }

    public function test_admin_can_remove_item_from_archived_activity(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();

        $activityId = $this->activity(
            $versionId,
            'archived',
        );

        [, $revisionId] =
            $this->releasedRevision(
                $versionId
            );

        $membership = $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        )->assertCreated();

        $membershipId =
            $membership->json('data.id');

        $this->deleteJson(
            "/api/admin/practice-activities/{$activityId}/items/{$membershipId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.deleted',
                true
            );

        $this->assertDatabaseMissing(
            'practice_activity_items',
            [
                'id' => $membershipId,
            ]
        );
    }

    public function test_last_item_cannot_be_removed_from_active_activity(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();

        $activityId = $this->activity(
            $versionId,
            'archived',
        );

        [, $revisionId] =
            $this->releasedRevision(
                $versionId
            );

        $membership = $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        )->assertCreated();

        $membershipId =
            $membership->json('data.id');

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/activate"
        )->assertOk();

        $this->deleteJson(
            "/api/admin/practice-activities/{$activityId}/items/{$membershipId}"
        )->assertStatus(409);

        $this->assertDatabaseHas(
            'practice_activity_items',
            [
                'id' => $membershipId,
            ]
        );
    }

    public function test_membership_is_frozen_after_curriculum_publish(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();

        $activityId = $this->activity(
            $versionId,
            'archived',
        );

        [, $revisionId] =
            $this->releasedRevision(
                $versionId
            );

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/practice-activities/{$activityId}/items",
            [
                'assessment_item_revision_id' =>
                    $revisionId,
                'display_order' => 0,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_membership_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/practice-activities/{$id}/items"
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
            'name' =>
                'Curriculum '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' =>
                'Version '.Str::random(8),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
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
            'name' =>
                'Practice '.Str::random(12),
            'description' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function unreleasedRevision(
        string $versionId,
    ): array {
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

        return [
            $itemId,
            $revisionId,
        ];
    }

    private function releasedRevision(
        string $versionId,
    ): array {
        [$itemId, $revisionId] =
            $this->unreleasedRevision(
                $versionId
            );

        $skillId = (string) Str::uuid();

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' =>
                'Skill '.Str::random(12),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $placementId =
            (string) Str::uuid();

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

        return [
            $itemId,
            $revisionId,
        ];
    }
}

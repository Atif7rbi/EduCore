<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAssessmentRevisionSkillApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_and_list_primary_and_supporting_skills(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $itemId = $this->item($versionId);
        $revisionId = $this->revision(
            $itemId,
            $versionId,
        );

        $primaryPlacement =
            $this->placement($versionId);

        $supportingPlacement =
            $this->placement($versionId);

        $primary = $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $primaryPlacement,
                'role' => 'primary',
            ]
        );

        $primary
            ->assertCreated()
            ->assertJsonPath(
                'data.assessment_item_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.skill_version_placement_id',
                $primaryPlacement
            )
            ->assertJsonPath(
                'data.role',
                'primary'
            );

        $supporting = $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $supportingPlacement,
                'role' => 'supporting',
            ]
        );

        $supporting
            ->assertCreated()
            ->assertJsonPath(
                'data.role',
                'supporting'
            );

        $this->getJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills"
        )
            ->assertOk()
            ->assertJsonFragment([
                'skill_version_placement_id' =>
                    $primaryPlacement,
                'role' => 'primary',
            ])
            ->assertJsonFragment([
                'skill_version_placement_id' =>
                    $supportingPlacement,
                'role' => 'supporting',
            ]);
    }

    public function test_role_validation_rejects_unknown_role(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $itemId = $this->item($versionId);
        $revisionId = $this->revision(
            $itemId,
            $versionId,
        );

        $placementId =
            $this->placement($versionId);

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
                'role' => 'secondary',
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            );
    }

    public function test_same_placement_cannot_be_added_twice_even_with_different_role(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $itemId = $this->item($versionId);
        $revisionId = $this->revision(
            $itemId,
            $versionId,
        );

        $placementId =
            $this->placement($versionId);

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
                'role' => 'primary',
            ]
        )->assertCreated();

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
                'role' => 'supporting',
            ]
        )->assertStatus(409);
    }

    public function test_cross_version_skill_placement_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionOne = $this->version();
        $versionTwo = $this->version();

        $itemId = $this->item($versionOne);

        $revisionId = $this->revision(
            $itemId,
            $versionOne,
        );

        $wrongPlacement =
            $this->placement($versionTwo);

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $wrongPlacement,
                'role' => 'primary',
            ]
        )->assertStatus(409);
    }

    public function test_released_revision_classification_is_frozen(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $itemId = $this->item($versionId);

        $revisionId = $this->revision(
            $itemId,
            $versionId,
        );

        $primaryPlacement =
            $this->placement($versionId);

        $classification =
            $this->postJson(
                "/api/admin/assessment-item-revisions/{$revisionId}/skills",
                [
                    'skill_version_placement_id' =>
                        $primaryPlacement,
                    'role' => 'primary',
                ]
            )->assertCreated();

        $classificationId =
            $classification->json('data.id');

        DB::table('assessment_item_revisions')
            ->where('id', $revisionId)
            ->update([
                'released_at' => now(),
            ]);

        $newPlacement =
            $this->placement($versionId);

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $newPlacement,
                'role' => 'supporting',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'assessment_item_revision_released'
            );

        $this->deleteJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills/{$classificationId}"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'assessment_item_revision_released'
            );
    }

    public function test_primary_classification_allows_existing_release_flow(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $itemId = $this->item($versionId);

        $revisionId = $this->revision(
            $itemId,
            $versionId,
        );

        $placementId =
            $this->placement($versionId);

        $this->postJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills",
            [
                'skill_version_placement_id' =>
                    $placementId,
                'role' => 'primary',
            ]
        )->assertCreated();

        $this->postJson(
            "/api/assessment-item-revisions/{$revisionId}/release"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $revisionId
            );

        $this->assertDatabaseMissing(
            'assessment_item_revisions',
            [
                'id' => $revisionId,
                'released_at' => null,
            ]
        );
    }

    public function test_admin_can_remove_unreleased_classification(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version();
        $itemId = $this->item($versionId);

        $revisionId = $this->revision(
            $itemId,
            $versionId,
        );

        $placementId =
            $this->placement($versionId);

        $classification =
            $this->postJson(
                "/api/admin/assessment-item-revisions/{$revisionId}/skills",
                [
                    'skill_version_placement_id' =>
                        $placementId,
                    'role' => 'supporting',
                ]
            )->assertCreated();

        $classificationId =
            $classification->json('data.id');

        $this->deleteJson(
            "/api/admin/assessment-item-revisions/{$revisionId}/skills/{$classificationId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.deleted',
                true
            );
    }

    public function test_assessment_classification_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/assessment-item-revisions/{$id}/skills"
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

    private function item(
        string $versionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('assessment_items')->insert([
            'id' => $id,
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

        return $id;
    }

    private function revision(
        string $itemId,
        string $versionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'assessment_item_revisions'
        )->insert([
            'id' => $id,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' =>
                $versionId,
            'revision_number' => 1,
            'primary_topic_id' => null,
            'difficulty' => 'medium',
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

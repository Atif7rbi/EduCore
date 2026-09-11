<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSkillPlacementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_place_skill_in_draft_version(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $skillId = $this->skill();

        $response = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements",
            [
                'skill_id' => $skillId,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.skill_id',
                $skillId
            )
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            );

        $placementId = $response->json('data.id');

        $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $placementId,
                'skill_id' => $skillId,
            ]);
    }

    public function test_same_skill_cannot_be_placed_twice_in_same_version(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $skillId = $this->skill();

        $payload = [
            'skill_id' => $skillId,
        ];

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements",
            $payload
        )->assertCreated();

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements",
            $payload
        )->assertStatus(409);
    }

    public function test_admin_can_add_same_version_home_topic(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $skillId = $this->skill();
        $topicId = $this->topic($versionId);

        $placement = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements",
            [
                'skill_id' => $skillId,
            ]
        )->assertCreated();

        $placementId = $placement->json('data.id');

        $response = $this->postJson(
            "/api/admin/skill-placements/{$placementId}/home-topics",
            [
                'topic_id' => $topicId,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.placement_id',
                $placementId
            )
            ->assertJsonPath(
                'data.topic_id',
                $topicId
            )
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            );
    }

    public function test_home_topic_from_different_version_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $versionOne = $this->version('draft');
        $versionTwo = $this->version('draft');

        $skillId = $this->skill();

        $placement = $this->postJson(
            "/api/admin/curriculum-versions/{$versionOne}/skill-placements",
            [
                'skill_id' => $skillId,
            ]
        )->assertCreated();

        $placementId = $placement->json('data.id');
        $wrongTopicId = $this->topic($versionTwo);

        $this->postJson(
            "/api/admin/skill-placements/{$placementId}/home-topics",
            [
                'topic_id' => $wrongTopicId,
            ]
        )->assertStatus(409);
    }

    public function test_placement_mutation_is_frozen_after_publish(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $skillOne = $this->skill();
        $skillTwo = $this->skill();

        $placement = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements",
            [
                'skill_id' => $skillOne,
            ]
        )->assertCreated();

        $placementId = $placement->json('data.id');

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements",
            [
                'skill_id' => $skillTwo,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );

        $this->deleteJson(
            "/api/admin/skill-placements/{$placementId}"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_admin_can_remove_home_topic_then_placement_in_draft(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->version('draft');
        $skillId = $this->skill();
        $topicId = $this->topic($versionId);

        $placement = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/skill-placements",
            [
                'skill_id' => $skillId,
            ]
        )->assertCreated();

        $placementId = $placement->json('data.id');

        $homeTopic = $this->postJson(
            "/api/admin/skill-placements/{$placementId}/home-topics",
            [
                'topic_id' => $topicId,
            ]
        )->assertCreated();

        $homeTopicId = $homeTopic->json('data.id');

        $this->deleteJson(
            "/api/admin/skill-placements/{$placementId}/home-topics/{$homeTopicId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.deleted',
                true
            );

        $this->deleteJson(
            "/api/admin/skill-placements/{$placementId}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.deleted',
                true
            );
    }

    public function test_placement_management_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/curriculum-versions/{$id}/skill-placements"
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

    private function skill(): string
    {
        $id = (string) Str::uuid();

        DB::table('skills')->insert([
            'id' => $id,
            'name' => 'Skill '.Str::random(12),
            'description' => null,
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

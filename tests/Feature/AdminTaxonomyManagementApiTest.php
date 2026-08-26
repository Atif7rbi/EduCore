<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTaxonomyManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_list_topic_in_draft_version(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->curriculumVersion('draft');

        $response = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/topics",
            [
                'name' => 'Ratios',
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
                'data.name',
                'Ratios'
            )
            ->assertJsonPath(
                'data.display_order',
                2
            );

        $topicId = $response->json('data.id');

        $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/topics"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $topicId,
                'name' => 'Ratios',
            ]);
    }

    public function test_topic_defaults_display_order_to_zero(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->curriculumVersion('draft');

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/topics",
            [
                'name' => 'Percentages',
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.display_order',
                0
            );
    }

    public function test_admin_can_update_topic_in_draft_version(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->curriculumVersion('draft');
        $topicId = $this->topic($versionId);

        $this->putJson(
            "/api/admin/topics/{$topicId}",
            [
                'name' => 'Advanced Ratios',
                'display_order' => 5,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Advanced Ratios'
            )
            ->assertJsonPath(
                'data.display_order',
                5
            );
    }

    public function test_topics_cannot_be_added_or_edited_in_published_version(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->curriculumVersion(
            'draft'
        );

        $topicId = $this->topic($versionId);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/topics",
            [
                'name' => 'Forbidden Topic',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );

        $this->putJson(
            "/api/admin/topics/{$topicId}",
            [
                'name' => 'Changed',
                'display_order' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_admin_can_create_list_and_update_stable_skill(): void
    {
        $this->actingAs($this->admin());

        $response = $this->postJson(
            '/api/admin/skills',
            [
                'name' => 'Ratio Reasoning',
                'description' =>
                    'Solve proportional relationships.',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.name',
                'Ratio Reasoning'
            );

        $skillId = $response->json('data.id');

        $this->getJson('/api/admin/skills')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $skillId,
                'name' => 'Ratio Reasoning',
            ]);

        $this->putJson(
            "/api/admin/skills/{$skillId}",
            [
                'name' => 'Ratio and Proportion',
                'description' =>
                    'Clarified skill description.',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Ratio and Proportion'
            );
    }

    public function test_topic_validation_rejects_negative_display_order(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->curriculumVersion('draft');

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/topics",
            [
                'name' => 'Invalid',
                'display_order' => -1,
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            );
    }

    public function test_taxonomy_management_rejects_guest(): void
    {
        $this->getJson('/api/admin/skills')
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

    private function curriculumVersion(
        string $status,
    ): string {
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
            'label' => 'Version One',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    private function topic(
        string $curriculumVersionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $id,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'name' => 'Topic '.Str::random(8),
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

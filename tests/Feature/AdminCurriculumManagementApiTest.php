<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCurriculumManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_subject(): void
    {
        $this->actingAs($this->admin());

        $response = $this->postJson(
            '/api/admin/subjects',
            [
                'name' => 'Mathematics',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.name',
                'Mathematics'
            );

        $subjectId = $response->json('data.id');

        $this->putJson(
            "/api/admin/subjects/{$subjectId}",
            [
                'name' => 'Quantitative Mathematics',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Quantitative Mathematics'
            );
    }

    public function test_subject_name_must_be_unique(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(
            '/api/admin/subjects',
            ['name' => 'Mathematics']
        )->assertCreated();

        $this->postJson(
            '/api/admin/subjects',
            ['name' => 'Mathematics']
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            );
    }

    public function test_admin_can_create_and_update_curriculum(): void
    {
        $this->actingAs($this->admin());

        $subjectId = $this->subject();

        $response = $this->postJson(
            "/api/admin/subjects/{$subjectId}/curricula",
            [
                'name' => 'Qudrat Quantitative',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.subject_id',
                $subjectId
            );

        $curriculumId = $response->json('data.id');

        $this->putJson(
            "/api/admin/curricula/{$curriculumId}",
            [
                'name' => 'Qudrat Quantitative Core',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Qudrat Quantitative Core'
            );
    }

    public function test_admin_can_create_draft_curriculum_version(): void
    {
        $this->actingAs($this->admin());

        $curriculumId = $this->curriculum();

        $response = $this->postJson(
            "/api/admin/curricula/{$curriculumId}/versions",
            [
                'version_number' => 1,
                'label' => 'Initial Version',
                'status' => 'published',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.curriculum_id',
                $curriculumId
            )
            ->assertJsonPath(
                'data.version_number',
                1
            )
            ->assertJsonPath(
                'data.status',
                'draft'
            );
    }

    public function test_curriculum_version_number_is_unique_within_curriculum(): void
    {
        $this->actingAs($this->admin());

        $curriculumId = $this->curriculum();

        $this->postJson(
            "/api/admin/curricula/{$curriculumId}/versions",
            [
                'version_number' => 1,
                'label' => 'Version One',
            ]
        )->assertCreated();

        $this->postJson(
            "/api/admin/curricula/{$curriculumId}/versions",
            [
                'version_number' => 1,
                'label' => 'Duplicate',
            ]
        )->assertStatus(409);
    }

    public function test_draft_curriculum_version_can_be_updated(): void
    {
        $this->actingAs($this->admin());

        $curriculumId = $this->curriculum();

        $version = $this->postJson(
            "/api/admin/curricula/{$curriculumId}/versions",
            [
                'version_number' => 1,
                'label' => 'Draft',
            ]
        )->assertCreated();

        $versionId = $version->json('data.id');

        $this->putJson(
            "/api/admin/curriculum-versions/{$versionId}",
            [
                'version_number' => 2,
                'label' => 'Draft Revised',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.version_number',
                2
            )
            ->assertJsonPath(
                'data.label',
                'Draft Revised'
            )
            ->assertJsonPath(
                'data.status',
                'draft'
            );
    }

    public function test_published_curriculum_version_cannot_be_edited(): void
    {
        $this->actingAs($this->admin());

        $curriculumId = $this->curriculum();

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Published',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson(
            "/api/admin/curriculum-versions/{$versionId}",
            [
                'version_number' => 2,
                'label' => 'Changed',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_admin_management_endpoints_reject_guest(): void
    {
        $this->postJson(
            '/api/admin/subjects',
            ['name' => 'Mathematics']
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

    private function subject(): string
    {
        $id = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $id,
            'name' => 'Subject '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function curriculum(): string
    {
        $subjectId = $this->subject();

        $id = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $id,
            'subject_id' => $subjectId,
            'name' => 'Curriculum '.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class AdminCurriculumContentReadOnlyBoundaryTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_admin_can_read_teacher_owned_curriculum_content(): void
    {
        $curriculum = $this->createOwnedCurriculumFixture(
            'PE-001 Read Boundary'
        );

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'Read Only',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $this->getJson(
            "/api/admin/curricula/{$curriculum->id}/versions"
        )->assertOk();

        $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/readiness"
        )->assertOk();
    }

    public function test_admin_curriculum_descendant_mutation_surface_is_fail_closed(): void
    {
        $this->actingAs($this->admin());

        $id = (string) Str::uuid();
        $childId = (string) Str::uuid();

        $routes = [
            [
                'POST',
                "/api/admin/curricula/{$id}/versions",
            ],
            [
                'PUT',
                "/api/admin/curriculum-versions/{$id}",
            ],
            [
                'POST',
                "/api/admin/curriculum-versions/{$id}/topics",
            ],
            [
                'PUT',
                "/api/admin/topics/{$id}",
            ],
            [
                'POST',
                "/api/admin/curriculum-versions/{$id}/skill-placements",
            ],
            [
                'DELETE',
                "/api/admin/skill-placements/{$id}",
            ],
            [
                'POST',
                "/api/admin/skill-placements/{$id}/home-topics",
            ],
            [
                'DELETE',
                "/api/admin/skill-placements/{$id}/home-topics/{$childId}",
            ],
            [
                'POST',
                "/api/admin/curriculum-versions/{$id}/exam-templates",
            ],
            [
                'PUT',
                "/api/admin/exam-templates/{$id}",
            ],
            [
                'POST',
                "/api/admin/exam-templates/{$id}/archive",
            ],
            [
                'POST',
                "/api/admin/exam-templates/{$id}/activate",
            ],
            [
                'POST',
                "/api/admin/exam-templates/{$id}/versions",
            ],
            [
                'PUT',
                "/api/admin/exam-template-versions/{$id}",
            ],
            [
                'POST',
                "/api/admin/exam-template-versions/{$id}/publish",
            ],
            [
                'POST',
                "/api/admin/exam-template-versions/{$id}/retire",
            ],
            [
                'POST',
                "/api/admin/curriculum-versions/{$id}/practice-activities",
            ],
            [
                'PUT',
                "/api/admin/practice-activities/{$id}",
            ],
            [
                'POST',
                "/api/admin/practice-activities/{$id}/activate",
            ],
            [
                'POST',
                "/api/admin/practice-activities/{$id}/archive",
            ],
            [
                'POST',
                "/api/admin/practice-activities/{$id}/items",
            ],
            [
                'DELETE',
                "/api/admin/practice-activities/{$id}/items/{$childId}",
            ],
            [
                'POST',
                "/api/admin/curriculum-versions/{$id}/assessment-items",
            ],
            [
                'PUT',
                "/api/admin/assessment-items/{$id}",
            ],
            [
                'POST',
                "/api/admin/assessment-items/{$id}/revisions",
            ],
            [
                'POST',
                "/api/admin/assessment-item-revisions/{$id}/skills",
            ],
            [
                'DELETE',
                "/api/admin/assessment-item-revisions/{$id}/skills/{$childId}",
            ],
            [
                'POST',
                "/api/admin/curriculum-versions/{$id}/lessons",
            ],
            [
                'PUT',
                "/api/admin/lessons/{$id}",
            ],
            [
                'POST',
                "/api/admin/lessons/{$id}/revisions",
            ],
            [
                'POST',
                "/api/admin/lesson-revisions/{$id}/skills",
            ],
            [
                'DELETE',
                "/api/admin/lesson-revisions/{$id}/skills/{$childId}",
            ],
            [
                'POST',
                "/api/curriculum-versions/{$id}/publish",
            ],
            [
                'POST',
                "/api/curriculum-versions/{$id}/retire",
            ],
            [
                'POST',
                "/api/lesson-revisions/{$id}/release",
            ],
            [
                'POST',
                "/api/lessons/{$id}/publish",
            ],
            [
                'POST',
                "/api/lessons/{$id}/unpublish",
            ],
            [
                'POST',
                "/api/assessment-item-revisions/{$id}/release",
            ],
            [
                'POST',
                "/api/assessment-items/{$id}/publish",
            ],
            [
                'POST',
                "/api/assessment-items/{$id}/retire",
            ],
            [
                'POST',
                "/api/practice-activities/{$id}/items",
            ],
            [
                'DELETE',
                "/api/practice-activities/{$id}/items/{$childId}",
            ],
            [
                'POST',
                "/api/exam-template-versions/{$id}/generations",
            ],
        ];

        foreach ($routes as [$method, $uri]) {
            $this->json(
                $method,
                $uri,
                [],
            )
                ->assertStatus(403)
                ->assertJsonPath(
                    'error.code',
                    'admin_curriculum_content_read_only',
                );
        }
    }

    public function test_rejected_admin_mutations_leave_owned_curriculum_state_unchanged(): void
    {
        $curriculum = $this->createOwnedCurriculumFixture(
            'PE-001 State Preservation'
        );

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'Frozen Draft',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $beforeCount = DB::table('curriculum_versions')
            ->where('curriculum_id', $curriculum->id)
            ->count();

        $this->postJson(
            "/api/admin/curricula/{$curriculum->id}/versions",
            [
                'version_number' => 2,
                'label' => 'Forbidden Version',
            ],
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_content_read_only',
            );

        $this->putJson(
            "/api/admin/curriculum-versions/{$versionId}",
            [
                'version_number' => 9,
                'label' => 'Forbidden Update',
            ],
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_content_read_only',
            );

        $this->postJson(
            "/api/curriculum-versions/{$versionId}/publish",
            [],
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_content_read_only',
            );

        $this->assertSame(
            $beforeCount,
            DB::table('curriculum_versions')
                ->where('curriculum_id', $curriculum->id)
                ->count(),
        );

        $row = DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(
            'Frozen Draft',
            $row->label,
        );
        $this->assertSame(
            'draft',
            $row->status,
        );
        $this->assertSame(
            1,
            (int) $row->version_number,
        );
    }

    public function test_existing_curriculum_root_admin_write_contract_is_preserved(): void
    {
        $this->actingAs($this->admin());

        $subjectId = $this->canonicalSubjectId();

        $this->postJson(
            "/api/admin/subjects/{$subjectId}/curricula",
            [
                'name' => 'Still Disabled',
            ],
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_authoring_disabled',
            );

        $this->putJson(
            '/api/admin/curricula/'.Str::uuid(),
            [
                'name' => 'Still Disabled',
            ],
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_authoring_disabled',
            );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}

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

    public function test_admin_curriculum_read_model_exposes_teacher_ownership_provenance(): void
    {
        $curriculum =
            $this->createOwnedCurriculumFixture(
                'G4-C3 Ownership Provenance'
            );

        $assignment = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $curriculum
                    ->teacher_subject_assignment_id,
            )
            ->first();

        $this->assertNotNull($assignment);

        $teacher = DB::table('users')
            ->where(
                'id',
                $assignment->teacher_id,
            )
            ->first();

        $this->assertNotNull($teacher);

        $subject = DB::table('subjects')
            ->where(
                'id',
                $curriculum->subject_id,
            )
            ->first();

        $this->assertNotNull($subject);

        $this->actingAs(
            $this->admin()
        );

        $response = $this->getJson(
            '/api/admin/subjects/'
            .$curriculum->subject_id
            .'/curricula'
        )
            ->assertOk();

        $row = collect(
            $response->json('data')
        )->firstWhere(
            'id',
            $curriculum->id,
        );

        $this->assertIsArray($row);

        $this->assertSame(
            'teacher_owned',
            $row['ownership_kind'] ?? null,
        );

        $this->assertSame(
            $curriculum
                ->teacher_subject_assignment_id,
            $row[
                'teacher_subject_assignment_id'
            ] ?? null,
        );

        $this->assertSame(
            $assignment->teacher_id,
            $row['teacher_user_id'] ?? null,
        );

        $this->assertSame(
            $assignment->status,
            $row[
                'teacher_subject_assignment_status'
            ] ?? null,
        );

        $this->assertSame(
            $teacher->id,
            $row['teacher']['user_id']
                ?? null,
        );

        $this->assertSame(
            $teacher->name,
            $row['teacher']['name']
                ?? null,
        );

        $this->assertSame(
            $teacher->email,
            $row['teacher']['email']
                ?? null,
        );

        $this->assertSame(
            $teacher->status,
            $row['teacher']['status']
                ?? null,
        );

        $this->assertSame(
            $subject->id,
            $row['subject']['id']
                ?? null,
        );

        $this->assertSame(
            $subject->code,
            $row['subject']['code']
                ?? null,
        );

        $this->assertSame(
            $subject->name,
            $row['subject']['name']
                ?? null,
        );

        $this->assertSame(
            $subject->status,
            $row['subject']['status']
                ?? null,
        );
    }

    public function test_admin_curriculum_read_model_explicitly_classifies_legacy_ownerless_content(): void
    {
        $identity = DB::selectOne(
            'SELECT '
            .'current_database() AS database_name, '
            .'current_user AS database_user'
        );

        $this->assertSame(
            'sewaellf_educore_test',
            $identity->database_name
                ?? null,
        );

        $this->assertSame(
            'sewaellf_educore_Admin',
            $identity->database_user
                ?? null,
        );

        $subjectId =
            $this->canonicalSubjectId();

        $curriculumId =
            (string) Str::uuid();

        DB::statement(
            'ALTER TABLE curricula '
            .'DISABLE TRIGGER '
            .'trg_curricula_ownership_integrity'
        );

        try {
            DB::table('curricula')
                ->insert([
                    'id' => $curriculumId,
                    'subject_id' => $subjectId,
                    'education_stage_id' => null,
                    'teacher_subject_assignment_id' => null,
                    'name' => 'Historical Ownerless',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        } finally {
            DB::statement(
                'ALTER TABLE curricula '
                .'ENABLE TRIGGER '
                .'trg_curricula_ownership_integrity'
            );
        }

        $this->actingAs(
            $this->admin()
        );

        $response = $this->getJson(
            '/api/admin/subjects/'
            .$subjectId
            .'/curricula'
        )
            ->assertOk();

        $row = collect(
            $response->json('data')
        )->firstWhere(
            'id',
            $curriculumId,
        );

        $this->assertIsArray($row);

        $this->assertSame(
            'legacy_ownerless',
            $row['ownership_kind'] ?? null,
        );

        $this->assertNull(
            $row[
                'teacher_subject_assignment_id'
            ] ?? null,
        );

        $this->assertNull(
            $row['teacher_user_id'] ?? null,
        );

        $this->assertNull(
            $row[
                'teacher_subject_assignment_status'
            ] ?? null,
        );

        $this->assertNull(
            $row['teacher'] ?? null,
        );

        $this->assertSame(
            $subjectId,
            $row['subject']['id']
                ?? null,
        );

        $this->assertSame(
            'mathematics',
            $row['subject']['code']
                ?? null,
        );
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

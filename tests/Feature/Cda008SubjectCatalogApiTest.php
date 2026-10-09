<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\TestCase;

class Cda008SubjectCatalogApiTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use RefreshDatabase;

    public function test_subject_catalog_exposes_only_canonical_subjects_in_frozen_order(): void
    {
        $this->actingAs($this->admin());

        $this->legacySubject('Legacy Hidden');

        $this->createOwnedCurriculumFixture(
            'Canonical Curriculum',
            'mathematics',
        );

        DB::table('subjects')
            ->where('code', 'physics')
            ->update([
                'status' => 'inactive',
            ]);

        $response = $this->getJson(
            '/api/admin/subjects'
        );

        $response
            ->assertOk()
            ->assertJsonCount(6, 'data');

        $data = $response->json('data');

        $this->assertSame(
            [
                'mathematics',
                'physics',
                'biology',
                'chemistry',
                'english_language',
                'arabic_language',
            ],
            array_column($data, 'code'),
        );

        $this->assertSame(
            [
                'الرياضيات',
                'الفيزياء',
                'الأحياء',
                'الكيمياء',
                'اللغة الإنجليزية',
                'اللغة العربية',
            ],
            array_column($data, 'name'),
        );

        $this->assertNotContains(
            'Legacy Hidden',
            array_column($data, 'name'),
        );

        $this->assertSame(
            1,
            $data[0]['curricula_count'],
        );

        $this->assertSame(
            'inactive',
            $data[1]['status'],
        );

        $this->assertNotNull(
            $data[0]['icon_key'],
        );

        $this->assertNotNull(
            $data[0]['thumbnail_key'],
        );
    }

    public function test_education_stage_catalog_exposes_frozen_order_and_derived_counts(): void
    {
        $this->actingAs($this->admin());

        $this->createOwnedCurriculumFixture(
            'Primary Mathematics',
            'mathematics',
            $this->stageId('primary'),
        );

        $response = $this->getJson(
            '/api/admin/education-stages'
        );

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $data = $response->json('data');

        $this->assertSame(
            [
                'primary',
                'middle',
                'secondary',
            ],
            array_column($data, 'code'),
        );

        $this->assertSame(
            [
                'المرحلة الابتدائية',
                'المرحلة المتوسطة',
                'المرحلة الثانوية',
            ],
            array_column($data, 'name'),
        );

        $this->assertSame(
            1,
            $data[0]['curricula_count'],
        );

        $this->assertSame(
            0,
            $data[1]['curricula_count'],
        );
    }

    public function test_admin_curriculum_create_and_update_are_disabled_while_update_validation_remains_explicit(): void
    {
        $this->actingAs($this->admin());

        $subjectId = $this->subjectId(
            'mathematics'
        );

        $primaryId = $this->stageId(
            'primary'
        );

        $this->postJson(
            "/api/admin/subjects/{$subjectId}/curricula",
            [
                'name' => 'Primary Mathematics',
                'education_stage_id' => $primaryId,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_authoring_disabled',
            );

        $this->putJson(
            '/api/admin/curricula/'.Str::uuid(),
            [
                'name' => 'Primary Mathematics Core',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_authoring_disabled',
            );

        /*
         * UpdateCurriculumRequest rejects EducationStage mutation
         * before the disabled compatibility controller executes.
         */
        $this->putJson(
            '/api/admin/curricula/'.Str::uuid(),
            [
                'name' => 'Illegal Reclassification',
                'education_stage_id' => $this->stageId(
                    'middle'
                ),
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            );

        $this->assertDatabaseMissing(
            'curricula',
            [
                'name' => 'Primary Mathematics',
            ],
        );
    }

    public function test_teacher_owned_curriculum_stage_remains_optional(): void
    {
        $curriculum =
            $this->createOwnedCurriculumFixture(
                'Unclassified Curriculum',
                'mathematics',
                null,
            );

        $this->assertNull(
            $curriculum->education_stage_id
        );

        $this->assertSame(
            $this->subjectId('mathematics'),
            $curriculum->subject_id
        );

        $this->assertNotNull(
            $curriculum->teacher_subject_assignment_id
        );
    }

    public function test_legacy_subject_cannot_receive_teacher_assignment_for_new_content(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $legacyId = $this->legacySubject(
            'Legacy Compatibility Subject'
        );

        $this->expectException(
            ModelNotFoundException::class
        );

        app(AssignTeacherSubject::class)
            ->execute(
                actorUserId: $admin->id,
                teacherUserId: $teacher->id,
                subjectId: $legacyId,
                operationId: (string) Str::uuid(),
                reason: 'Reject legacy Subject assignment.',
            );
    }

    public function test_inactive_canonical_subject_cannot_receive_new_owned_curriculum(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $mathId = $this->subjectId(
            'mathematics'
        );

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $mathId,
            operationId: (string) Str::uuid(),
            reason: 'CDA owned Curriculum eligibility.',
        );

        DB::table('subjects')
            ->where('id', $mathId)
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(CreateOwnedCurriculum::class)
            ->execute(
                actorUserId: $teacher->id,
                teacherSubjectAssignmentId: $assignment->id,
                name: 'Inactive Subject Curriculum',
                educationStageId: null,
            );
    }

    public function test_inactive_education_stage_is_rejected_for_new_curriculum(): void
    {
        $this->actingAs($this->admin());

        $primaryId = $this->stageId(
            'primary'
        );

        DB::table('education_stages')
            ->where('id', $primaryId)
            ->update([
                'status' => 'inactive',
            ]);

        $this->postJson(
            '/api/admin/subjects/'
            .$this->subjectId('mathematics')
            .'/curricula',
            [
                'name' => 'Invalid Stage Curriculum',
                'education_stage_id' => $primaryId,
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            );
    }

    public function test_canonical_subject_rename_is_blocked_while_legacy_rename_remains_compatible(): void
    {
        $this->actingAs($this->admin());

        $mathId = $this->subjectId(
            'mathematics'
        );

        $this->putJson(
            "/api/admin/subjects/{$mathId}",
            [
                'name' => 'Renamed Mathematics',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'canonical_subject_immutable',
            );

        $legacy = $this->postJson(
            '/api/admin/subjects',
            [
                'name' => 'Legacy Editable Subject',
            ]
        );

        $legacy
            ->assertCreated()
            ->assertJsonPath(
                'data.code',
                null,
            )
            ->assertJsonPath(
                'data.status',
                'active',
            )
            ->assertJsonPath(
                'data.sort_order',
                0,
            );

        $legacyId = $legacy->json(
            'data.id'
        );

        $this->putJson(
            "/api/admin/subjects/{$legacyId}",
            [
                'name' => 'Legacy Renamed Subject',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Legacy Renamed Subject',
            );
    }

    public function test_compatibility_subject_endpoint_rejects_canonical_catalog_metadata(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(
            '/api/admin/subjects',
            [
                'name' => 'Unauthorized Canonical Subject',
                'code' => 'unauthorized_subject',
                'status' => 'inactive',
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            );

        $legacyId = $this->legacySubject(
            'Legacy Protected Metadata'
        );

        $this->putJson(
            "/api/admin/subjects/{$legacyId}",
            [
                'name' => 'Legacy Protected Metadata',
                'code' => 'promoted_legacy',
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            );

        $this->assertDatabaseHas(
            'subjects',
            [
                'id' => $legacyId,
                'code' => null,
            ],
        );
    }

    public function test_catalog_read_routes_reject_guest_and_student(): void
    {
        $this->getJson(
            '/api/admin/education-stages'
        )
            ->assertStatus(401)
            ->assertJsonPath(
                'error.code',
                'unauthenticated',
            );

        $this->actingAs(
            User::factory()->create([
                'role' => 'student',
                'status' => 'active',
            ])
        );

        $this->getJson(
            '/api/admin/education-stages'
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'management_forbidden',
            );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function teacher(): User
    {
        return User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
    }

    private function subjectId(
        string $code,
    ): string {
        $id = DB::table('subjects')
            ->where('code', $code)
            ->value('id');

        $this->assertNotNull(
            $id,
            "Canonical Subject {$code} missing."
        );

        return (string) $id;
    }

    private function stageId(
        string $code,
    ): string {
        $id = DB::table('education_stages')
            ->where('code', $code)
            ->value('id');

        $this->assertNotNull(
            $id,
            "EducationStage {$code} missing."
        );

        return (string) $id;
    }

    private function legacySubject(
        string $name,
    ): string {
        $id = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

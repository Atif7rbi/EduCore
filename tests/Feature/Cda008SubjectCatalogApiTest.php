<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Cda008SubjectCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_catalog_exposes_only_canonical_subjects_in_frozen_order(): void
    {
        $this->actingAs($this->admin());

        $this->legacySubject('Legacy Hidden');

        $mathId = $this->subjectId('mathematics');

        $this->curriculum(
            $mathId,
            'Canonical Curriculum',
            null,
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

        $this->curriculum(
            $this->subjectId('mathematics'),
            'Primary Mathematics',
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

    public function test_admin_can_create_curriculum_with_active_stage_and_only_rename_it_later(): void
    {
        $this->actingAs($this->admin());

        $subjectId = $this->subjectId(
            'mathematics'
        );

        $primaryId = $this->stageId(
            'primary'
        );

        $middleId = $this->stageId(
            'middle'
        );

        $response = $this->postJson(
            "/api/admin/subjects/{$subjectId}/curricula",
            [
                'name' => 'Primary Mathematics',
                'education_stage_id' => $primaryId,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.subject_id',
                $subjectId,
            )
            ->assertJsonPath(
                'data.education_stage_id',
                $primaryId,
            );

        $curriculumId = $response->json(
            'data.id'
        );

        $this->putJson(
            "/api/admin/curricula/{$curriculumId}",
            [
                'name' => 'Primary Mathematics Core',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.education_stage_id',
                $primaryId,
            );

        $this->putJson(
            "/api/admin/curricula/{$curriculumId}",
            [
                'name' => 'Illegal Reclassification',
                'education_stage_id' => $middleId,
            ]
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            );

        $this->assertDatabaseHas(
            'curricula',
            [
                'id' => $curriculumId,
                'name' => 'Primary Mathematics Core',
                'education_stage_id' => $primaryId,
            ],
        );
    }

    public function test_curriculum_stage_is_optional_at_creation(): void
    {
        $this->actingAs($this->admin());

        $subjectId = $this->subjectId(
            'mathematics'
        );

        $this->postJson(
            "/api/admin/subjects/{$subjectId}/curricula",
            [
                'name' => 'Unclassified Curriculum',
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.education_stage_id',
                null,
            );
    }

    public function test_new_curriculum_requires_active_canonical_subject(): void
    {
        $this->actingAs($this->admin());

        $legacyId = $this->legacySubject(
            'Legacy Compatibility Subject'
        );

        $this->postJson(
            "/api/admin/subjects/{$legacyId}/curricula",
            [
                'name' => 'Legacy Curriculum',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'subject_not_available_for_new_content',
            );

        $mathId = $this->subjectId(
            'mathematics'
        );

        DB::table('subjects')
            ->where('id', $mathId)
            ->update([
                'status' => 'inactive',
            ]);

        $this->postJson(
            "/api/admin/subjects/{$mathId}/curricula",
            [
                'name' => 'Inactive Subject Curriculum',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'subject_not_available_for_new_content',
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

    private function curriculum(
        string $subjectId,
        string $name,
        ?string $educationStageId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $id,
            'subject_id' => $subjectId,
            'education_stage_id' => $educationStageId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

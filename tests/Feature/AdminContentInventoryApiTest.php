<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminContentInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_can_list_canonical_subject_catalog_in_stable_order(): void
    {
        $this->actingAs($this->admin());

        $this->createSubject('Legacy Subject');

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
            'Legacy Subject',
            array_column($data, 'name'),
        );

        $this->assertSame(
            'inactive',
            $data[1]['status'],
        );
    }

    public function test_admin_curricula_are_scoped_to_subject(): void
    {
        $this->actingAs($this->admin());

        $subjectOne = $this->createSubject('Subject One');
        $subjectTwo = $this->createSubject('Subject Two');

        $curriculumOne = $this->createCurriculum(
            $subjectOne,
            'Curriculum One',
        );

        $this->createCurriculum(
            $subjectTwo,
            'Other Curriculum',
        );

        $response = $this->getJson(
            "/api/admin/subjects/{$subjectOne}/curricula"
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.id',
                $curriculumOne
            )
            ->assertJsonPath(
                'data.0.subject_id',
                $subjectOne
            );
    }

    public function test_admin_versions_expose_lifecycle_state_in_version_order(): void
    {
        $this->actingAs($this->admin());

        $subjectId = $this->createSubject('Mathematics');

        $curriculumId = $this->createCurriculum(
            $subjectId,
            'Qudrat Quantitative',
        );

        $versionTwo = $this->createVersion(
            $curriculumId,
            2,
            'v2',
            'draft',
        );

        $versionOne = $this->createVersion(
            $curriculumId,
            1,
            'v1',
            'published',
        );

        $response = $this->getJson(
            "/api/admin/curricula/{$curriculumId}/versions"
        );

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(2, $data);

        $this->assertSame(
            $versionOne,
            $data[0]['id']
        );

        $this->assertSame(
            'published',
            $data[0]['status']
        );

        $this->assertSame(
            $versionTwo,
            $data[1]['id']
        );

        $this->assertSame(
            'draft',
            $data[1]['status']
        );
    }

    public function test_missing_admin_inventory_parent_returns_not_found(): void
    {
        $this->actingAs($this->admin());

        $missingId = (string) Str::uuid();

        $this->getJson(
            "/api/admin/subjects/{$missingId}/curricula"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );

        $this->getJson(
            "/api/admin/curricula/{$missingId}/versions"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_admin_inventory_rejects_guest_and_student(): void
    {
        $this->getJson('/api/admin/subjects')
            ->assertStatus(401)
            ->assertJsonPath(
                'error.code',
                'unauthenticated'
            );

        $this->actingAs(
            User::factory()->create([
                'role' => 'student',
                'status' => 'active',
            ])
        );

        $this->getJson('/api/admin/subjects')
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'management_forbidden'
            );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createSubject(
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

    private function createCurriculum(
        string $subjectId,
        string $name,
    ): string {
        $id = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $id,
            'subject_id' => $subjectId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createVersion(
        string $curriculumId,
        int $versionNumber,
        string $label,
        string $status,
    ): string {
        $id = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $id,
            'curriculum_id' => $curriculumId,
            'version_number' => $versionNumber,
            'label' => $label,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

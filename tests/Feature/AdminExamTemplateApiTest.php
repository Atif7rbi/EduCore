<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminExamTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_update_active_template(): void
    {
        $this->actingAs($this->admin());

        $versionId = $this->curriculumVersion(
            'draft'
        );

        $response = $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/exam-templates",
            [
                'name' => 'Qudrat Quant Mock',
                'description' =>
                    'Quantitative mock exam',
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            )
            ->assertJsonPath(
                'data.status',
                'active'
            )
            ->assertJsonPath(
                'data.published_version_id',
                null
            )
            ->assertJsonPath(
                'data.versions_count',
                0
            );

        $templateId =
            $response->json('data.id');

        $this->getJson(
            "/api/admin/curriculum-versions/{$versionId}/exam-templates"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $templateId,
                'name' => 'Qudrat Quant Mock',
            ]);

        $this->putJson(
            "/api/admin/exam-templates/{$templateId}",
            [
                'name' => 'Qudrat Quant Mock Updated',
                'description' =>
                    'Updated description',
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Qudrat Quant Mock Updated'
            );
    }

    public function test_admin_can_create_list_and_update_draft_version(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion('draft');

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $response = $this->postJson(
            "/api/admin/exam-templates/{$templateId}/versions",
            [
                'version_number' => 1,
                'label' => 'Mock v1',
                'rules_payload' => [
                    'question_count' => 20,
                    'time_limit_minutes' => 30,
                ],
                'rules_schema_version' => 1,
            ]
        );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.exam_template_id',
                $templateId
            )
            ->assertJsonPath(
                'data.curriculum_version_id',
                $curriculumVersionId
            )
            ->assertJsonPath(
                'data.version_number',
                1
            )
            ->assertJsonPath(
                'data.status',
                'draft'
            );

        $templateVersionId =
            $response->json('data.id');

        $this->getJson(
            "/api/admin/exam-templates/{$templateId}/versions"
        )
            ->assertOk()
            ->assertJsonFragment([
                'id' => $templateVersionId,
                'version_number' => 1,
                'status' => 'draft',
            ]);

        $this->putJson(
            "/api/admin/exam-template-versions/{$templateVersionId}",
            [
                'label' => 'Mock v1 Updated',
                'rules_payload' => [
                    'question_count' => 25,
                    'time_limit_minutes' => 35,
                ],
                'rules_schema_version' => 1,
            ]
        )
            ->assertOk()
            ->assertJsonPath(
                'data.label',
                'Mock v1 Updated'
            )
            ->assertJsonPath(
                'data.rules_payload.question_count',
                25
            );
    }

    public function test_version_number_is_unique_per_template(): void
    {
        $this->actingAs($this->admin());

        $versionId =
            $this->curriculumVersion('draft');

        $templateId =
            $this->template($versionId);

        $payload = [
            'version_number' => 1,
            'label' => 'v1',
            'rules_payload' => [
                'question_count' => 20,
            ],
            'rules_schema_version' => 1,
        ];

        $this->postJson(
            "/api/admin/exam-templates/{$templateId}/versions",
            $payload
        )->assertCreated();

        $this->postJson(
            "/api/admin/exam-templates/{$templateId}/versions",
            $payload
        )->assertStatus(409);
    }

    public function test_published_version_cannot_be_edited(): void
    {
        $this->actingAs($this->admin());

        $versionId =
            $this->curriculumVersion('draft');

        $templateId =
            $this->template($versionId);

        $templateVersionId =
            $this->templateVersion(
                $templateId,
                $versionId,
                'draft'
            );

        DB::table('exam_template_versions')
            ->where('id', $templateVersionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->putJson(
            "/api/admin/exam-template-versions/{$templateVersionId}",
            [
                'label' => 'Changed',
                'rules_payload' => [
                    'question_count' => 99,
                ],
                'rules_schema_version' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'exam_template_version_not_draft'
            );
    }

    public function test_template_can_be_archived_and_reactivated_in_draft_curriculum(): void
    {
        $this->actingAs($this->admin());

        $versionId =
            $this->curriculumVersion('draft');

        $templateId =
            $this->template($versionId);

        $this->postJson(
            "/api/admin/exam-templates/{$templateId}/archive"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'archived'
            );

        $this->postJson(
            "/api/admin/exam-templates/{$templateId}/activate"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'active'
            );
    }

    public function test_archived_template_cannot_be_edited_or_receive_new_version(): void
    {
        $this->actingAs($this->admin());

        $versionId =
            $this->curriculumVersion('draft');

        $templateId =
            $this->template(
                $versionId,
                'archived'
            );

        $this->putJson(
            "/api/admin/exam-templates/{$templateId}",
            [
                'name' => 'Changed',
                'description' => null,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'exam_template_not_active'
            );

        $this->postJson(
            "/api/admin/exam-templates/{$templateId}/versions",
            [
                'version_number' => 1,
                'label' => 'v1',
                'rules_payload' => [
                    'question_count' => 10,
                ],
                'rules_schema_version' => 1,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'exam_template_not_active'
            );
    }

    public function test_authoring_is_frozen_after_curriculum_publish(): void
    {
        $this->actingAs($this->admin());

        $versionId =
            $this->curriculumVersion('draft');

        $templateId =
            $this->template($versionId);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/curriculum-versions/{$versionId}/exam-templates",
            [
                'name' => 'Forbidden',
                'description' => null,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );

        $this->putJson(
            "/api/admin/exam-templates/{$templateId}",
            [
                'name' => 'Forbidden',
                'description' => null,
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_exam_template_authoring_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->getJson(
            "/api/admin/curriculum-versions/{$id}/exam-templates"
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

    private function curriculumVersion(
        string $status,
    ): string {
        $subjectId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' =>
                'Subject '.Str::random(12),
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
            'curriculum_id' =>
                $curriculumId,
            'version_number' => 1,
            'label' =>
                'Version '.Str::random(8),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    private function template(
        string $curriculumVersionId,
        string $status = 'active',
    ): string {
        $id = (string) Str::uuid();

        DB::table('exam_templates')->insert([
            'id' => $id,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'name' =>
                'Template '.Str::random(12),
            'description' => null,
            'status' => $status,
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function templateVersion(
        string $templateId,
        string $curriculumVersionId,
        string $status,
    ): string {
        $id = (string) Str::uuid();

        DB::table(
            'exam_template_versions'
        )->insert([
            'id' => $id,
            'exam_template_id' =>
                $templateId,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => $status,
            'rules_payload' =>
                json_encode([
                    'question_count' => 20,
                ]),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

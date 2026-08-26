<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminExamTemplateLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_version_can_be_published_and_becomes_current(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion();

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $versionId =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                1,
            );

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionId}/publish"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.version.status',
                'published'
            )
            ->assertJsonPath(
                'data.template.published_version_id',
                $versionId
            );

        $this->assertDatabaseHas(
            'exam_template_versions',
            [
                'id' => $versionId,
                'status' => 'published',
            ]
        );

        $this->assertDatabaseHas(
            'exam_templates',
            [
                'id' => $templateId,
                'published_version_id' =>
                    $versionId,
            ]
        );
    }

    public function test_publishing_new_version_switches_current_pointer_without_rewriting_old_version(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion();

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $versionOne =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                1,
            );

        $versionTwo =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                2,
            );

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionOne}/publish"
        )->assertOk();

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionTwo}/publish"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.template.published_version_id',
                $versionTwo
            );

        $this->assertDatabaseHas(
            'exam_template_versions',
            [
                'id' => $versionOne,
                'status' => 'published',
            ]
        );

        $this->assertDatabaseHas(
            'exam_template_versions',
            [
                'id' => $versionTwo,
                'status' => 'published',
            ]
        );
    }

    public function test_previous_published_version_can_be_retired_after_pointer_switch(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion();

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $versionOne =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                1,
            );

        $versionTwo =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                2,
            );

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionOne}/publish"
        )->assertOk();

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionTwo}/publish"
        )->assertOk();

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionOne}/retire"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'retired'
            );

        $this->assertDatabaseHas(
            'exam_templates',
            [
                'id' => $templateId,
                'published_version_id' =>
                    $versionTwo,
            ]
        );
    }

    public function test_current_published_version_cannot_be_retired(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion();

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $versionId =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                1,
            );

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionId}/publish"
        )->assertOk();

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionId}/retire"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'exam_template_version_is_current'
            );

        $this->assertDatabaseHas(
            'exam_template_versions',
            [
                'id' => $versionId,
                'status' => 'published',
            ]
        );
    }

    public function test_draft_version_cannot_be_retired(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion();

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $versionId =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                1,
            );

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionId}/retire"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'exam_template_version_not_published'
            );
    }

    public function test_retired_version_cannot_be_republished(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion();

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $versionOne =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                1,
            );

        $versionTwo =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                2,
            );

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionOne}/publish"
        )->assertOk();

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionTwo}/publish"
        )->assertOk();

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionOne}/retire"
        )->assertOk();

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionOne}/publish"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'exam_template_version_retired'
            );
    }

    public function test_lifecycle_is_frozen_after_curriculum_publish(): void
    {
        $this->actingAs($this->admin());

        $curriculumVersionId =
            $this->curriculumVersion();

        $templateId =
            $this->template(
                $curriculumVersionId
            );

        $versionId =
            $this->templateVersion(
                $templateId,
                $curriculumVersionId,
                1,
            );

        DB::table('curriculum_versions')
            ->where(
                'id',
                $curriculumVersionId
            )
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->postJson(
            "/api/admin/exam-template-versions/{$versionId}/publish"
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'curriculum_version_not_draft'
            );
    }

    public function test_exam_template_lifecycle_rejects_guest(): void
    {
        $id = (string) Str::uuid();

        $this->postJson(
            "/api/admin/exam-template-versions/{$id}/publish"
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

    private function curriculumVersion(): string
    {
        $subjectId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' =>
                'Subject '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $curriculumId =
            (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' =>
                'Curriculum '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId =
            (string) Str::uuid();

        DB::table(
            'curriculum_versions'
        )->insert([
            'id' => $versionId,
            'curriculum_id' =>
                $curriculumId,
            'version_number' => 1,
            'label' =>
                'Version '.Str::random(8),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $versionId;
    }

    private function template(
        string $curriculumVersionId,
    ): string {
        $id = (string) Str::uuid();

        DB::table('exam_templates')->insert([
            'id' => $id,
            'curriculum_version_id' =>
                $curriculumVersionId,
            'name' =>
                'Template '.Str::random(12),
            'description' => null,
            'status' => 'active',
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function templateVersion(
        string $templateId,
        string $curriculumVersionId,
        int $versionNumber,
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
            'version_number' =>
                $versionNumber,
            'label' =>
                'v'.$versionNumber,
            'status' => 'draft',
            'rules_payload' =>
                json_encode([
                    'question_count' =>
                        20 + $versionNumber,
                ]),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExamGenerationApiTest extends TestCase
{
    public function test_published_template_can_build_generation_via_api(): void
    {
        [$templateVersionId, $versionId, $rules] =
            $this->createTemplateVersion(published: true);

        [$revisionOne, $itemOne] = $this->createAssessmentRevision(
            $versionId,
            0,
            released: true,
        );

        [$revisionTwo, $itemTwo] = $this->createAssessmentRevision(
            $versionId,
            1,
            released: true,
        );

        $response = $this->postJson(
            "/api/exam-template-versions/{$templateVersionId}/generations",
            [
                'generator_version' => 'generator-v1',
                'seed' => 'seed-123',
                'items' => [
                    [
                        'assessment_item_revision_id' => $revisionOne,
                        'assessment_item_id' => $itemOne,
                    ],
                    [
                        'assessment_item_revision_id' => $revisionTwo,
                        'assessment_item_id' => $itemTwo,
                    ],
                ],
            ],
        );

        $response
            ->assertStatus(201)
            ->assertJsonPath(
                'data.exam_template_version_id',
                $templateVersionId
            )
            ->assertJsonPath(
                'data.curriculum_version_id',
                $versionId
            )
            ->assertJsonPath(
                'data.rules_snapshot.question_count',
                $rules['question_count']
            )
            ->assertJsonPath(
                'data.rules_snapshot.difficulty.easy',
                $rules['difficulty']['easy']
            )
            ->assertJsonPath(
                'data.rules_snapshot.difficulty.medium',
                $rules['difficulty']['medium']
            )
            ->assertJsonPath(
                'data.rules_schema_version',
                1
            )
            ->assertJsonPath(
                'data.generator_version',
                'generator-v1'
            )
            ->assertJsonPath(
                'data.seed',
                'seed-123'
            )
            ->assertJsonPath(
                'data.items.0.assessment_item_revision_id',
                $revisionOne
            )
            ->assertJsonPath(
                'data.items.0.selection_position',
                0
            )
            ->assertJsonPath(
                'data.items.1.assessment_item_revision_id',
                $revisionTwo
            )
            ->assertJsonPath(
                'data.items.1.selection_position',
                1
            );

        $this->assertNotNull(
            $response->json('data.generated_at')
        );

        $generationId = $response->json('data.id');

        $this->assertDatabaseHas('exam_generations', [
            'id' => $generationId,
            'exam_template_version_id' => $templateVersionId,
            'curriculum_version_id' => $versionId,
            'generator_version' => 'generator-v1',
            'seed' => 'seed-123',
        ]);

        $this->assertSame(
            2,
            DB::table('exam_generation_items')
                ->where('exam_generation_id', $generationId)
                ->count()
        );
    }

    public function test_request_requires_non_empty_items(): void
    {
        [$templateVersionId] =
            $this->createTemplateVersion(published: true);

        $this->postJson(
            "/api/exam-template-versions/{$templateVersionId}/generations",
            [
                'generator_version' => 'generator-v1',
                'seed' => 'seed-empty',
                'items' => [],
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'items',
                    ],
                ],
            ]);
    }

    public function test_request_validates_item_identifiers(): void
    {
        [$templateVersionId] =
            $this->createTemplateVersion(published: true);

        $this->postJson(
            "/api/exam-template-versions/{$templateVersionId}/generations",
            [
                'generator_version' => 'generator-v1',
                'seed' => 'seed-invalid',
                'items' => [
                    [
                        'assessment_item_revision_id' => 'bad',
                        'assessment_item_id' => 'bad',
                    ],
                ],
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'items.0.assessment_item_revision_id',
                        'items.0.assessment_item_id',
                    ],
                ],
            ]);
    }

    public function test_unpublished_template_cannot_build_generation_via_api(): void
    {
        [$templateVersionId] =
            $this->createTemplateVersion(published: false);

        $this->postJson(
            "/api/exam-template-versions/{$templateVersionId}/generations",
            [
                'generator_version' => 'generator-v1',
                'seed' => 'seed-draft-template',
                'items' => [
                    [
                        'assessment_item_revision_id' => (string) Str::uuid(),
                        'assessment_item_id' => (string) Str::uuid(),
                    ],
                ],
            ],
        )
            ->assertStatus(409)
            ->assertExactJson([
                'error' => [
                    'code' => 'integrity_conflict',
                    'message' => 'The requested operation violates the current resource state.',
                ],
            ]);
    }

    public function test_unreleased_revision_cannot_survive_generation_seal_via_api(): void
    {
        [$templateVersionId, $versionId] =
            $this->createTemplateVersion(published: true);

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            0,
            released: false,
        );

        $before = DB::table('exam_generations')->count();

        $this->postJson(
            "/api/exam-template-versions/{$templateVersionId}/generations",
            [
                'generator_version' => 'generator-v1',
                'seed' => 'seed-unreleased',
                'items' => [
                    [
                        'assessment_item_revision_id' => $revisionId,
                        'assessment_item_id' => $itemId,
                    ],
                ],
            ],
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'integrity_conflict'
            );

        $this->assertSame(
            $before,
            DB::table('exam_generations')->count()
        );
    }

    public function test_missing_template_version_returns_not_found(): void
    {
        $templateVersionId = (string) Str::uuid();

        $this->postJson(
            "/api/exam-template-versions/{$templateVersionId}/generations",
            [
                'generator_version' => 'generator-v1',
                'seed' => 'seed-missing',
                'items' => [
                    [
                        'assessment_item_revision_id' => (string) Str::uuid(),
                        'assessment_item_id' => (string) Str::uuid(),
                    ],
                ],
            ],
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    /**
     * @return array{string, string, array<string, mixed>}
     */
    private function createTemplateVersion(bool $published): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $templateId = (string) Str::uuid();
        $templateVersionId = (string) Str::uuid();

        $rules = [
            'question_count' => 2,
            'difficulty' => [
                'easy' => 1,
                'medium' => 1,
            ],
        ];

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Exam API Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Exam API Curriculum {$curriculumId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_templates')->insert([
            'id' => $templateId,
            'curriculum_version_id' => $versionId,
            'name' => "Exam API Template {$templateId}",
            'description' => null,
            'status' => 'active',
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_template_versions')->insert([
            'id' => $templateVersionId,
            'exam_template_id' => $templateId,
            'curriculum_version_id' => $versionId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'rules_payload' => json_encode(
                $rules,
                JSON_THROW_ON_ERROR
            ),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($published) {
            DB::table('exam_template_versions')
                ->where('id', $templateVersionId)
                ->update([
                    'status' => 'published',
                    'updated_at' => now(),
                ]);
        }

        return [
            $templateVersionId,
            $versionId,
            $rules,
        ];
    }

    /**
     * @return array{string, string}
     */
    private function createAssessmentRevision(
        string $versionId,
        int $displayOrder,
        bool $released,
    ): array {
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Exam API Topic {$topicId}",
            'display_order' => $displayOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Exam API Skill {$skillId}",
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skill_version_placements')->insert([
            'id' => $placementId,
            'skill_id' => $skillId,
            'curriculum_version_id' => $versionId,
            'created_at' => now(),
        ]);

        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' => $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' => "Exam API Item {$itemId}",
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('assessment_item_revisions')->insert([
            'id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'difficulty' => 'easy',
            'content_payload' => json_encode([
                'stem' => '6 + 6 = ?',
                'options' => [10, 11, 12, 13],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'scoring_payload' => json_encode([
                'correct_option' => 2,
            ], JSON_THROW_ON_ERROR),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        DB::table('assessment_item_revision_skills')->insert([
            'id' => (string) Str::uuid(),
            'assessment_item_revision_id' => $revisionId,
            'skill_version_placement_id' => $placementId,
            'curriculum_version_id' => $versionId,
            'role' => 'primary',
            'created_at' => now(),
        ]);

        if ($released) {
            $service = new ReleaseAssessmentItemRevision(
                new TransactionManager(
                    new PostgresExceptionTranslator()
                )
            );

            $service->execute($revisionId);
        }

        return [$revisionId, $itemId];
    }
}

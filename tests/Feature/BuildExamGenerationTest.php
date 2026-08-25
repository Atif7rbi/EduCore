<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Exam\BuildExamGeneration;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildExamGenerationTest extends TestCase
{
    public function test_published_template_can_build_and_seal_generation(): void
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

        $generation = $this->service()->execute(
            $templateVersionId,
            'generator-v1',
            'seed-123',
            [
                [
                    'assessment_item_revision_id' => $revisionOne,
                    'assessment_item_id' => $itemOne,
                ],
                [
                    'assessment_item_revision_id' => $revisionTwo,
                    'assessment_item_id' => $itemTwo,
                ],
            ],
        );

        $this->assertSame(
            $templateVersionId,
            $generation->exam_template_version_id
        );
        $this->assertSame($versionId, $generation->curriculum_version_id);
        $this->assertEquals($rules, $generation->rules_snapshot);
        $this->assertSame(1, $generation->rules_schema_version);
        $this->assertSame('generator-v1', $generation->generator_version);
        $this->assertSame('seed-123', $generation->seed);
        $this->assertNotNull($generation->generated_at);

        $this->assertDatabaseHas('exam_generation_items', [
            'exam_generation_id' => $generation->id,
            'assessment_item_revision_id' => $revisionOne,
            'assessment_item_id' => $itemOne,
            'selection_position' => 0,
        ]);

        $this->assertDatabaseHas('exam_generation_items', [
            'exam_generation_id' => $generation->id,
            'assessment_item_revision_id' => $revisionTwo,
            'assessment_item_id' => $itemTwo,
            'selection_position' => 1,
        ]);

        $this->assertSame(
            2,
            DB::table('exam_generation_items')
                ->where('exam_generation_id', $generation->id)
                ->count()
        );
    }

    public function test_empty_generation_is_rejected_and_rolled_back(): void
    {
        [$templateVersionId] = $this->createTemplateVersion(
            published: true
        );

        $before = DB::table('exam_generations')->count();

        try {
            $this->service()->execute(
                $templateVersionId,
                'generator-v1',
                'empty-seed',
                [],
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertSame(
            $before,
            DB::table('exam_generations')->count()
        );
    }

    public function test_unpublished_template_version_cannot_build_generation(): void
    {
        [$templateVersionId] = $this->createTemplateVersion(
            published: false
        );

        $before = DB::table('exam_generations')->count();

        try {
            $this->service()->execute(
                $templateVersionId,
                'generator-v1',
                'draft-template-seed',
                [
                    [
                        'assessment_item_revision_id' => (string) Str::uuid(),
                        'assessment_item_id' => (string) Str::uuid(),
                    ],
                ],
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertSame(
            $before,
            DB::table('exam_generations')->count()
        );
    }

    public function test_unreleased_revision_cannot_survive_generation_seal(): void
    {
        [$templateVersionId, $versionId] =
            $this->createTemplateVersion(published: true);

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            0,
            released: false,
        );

        $before = DB::table('exam_generations')->count();

        try {
            $this->service()->execute(
                $templateVersionId,
                'generator-v1',
                'unreleased-seed',
                [
                    [
                        'assessment_item_revision_id' => $revisionId,
                        'assessment_item_id' => $itemId,
                    ],
                ],
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertSame(
            $before,
            DB::table('exam_generations')->count()
        );

        $this->assertDatabaseMissing('exam_generation_items', [
            'assessment_item_revision_id' => $revisionId,
        ]);
    }

    private function service(): BuildExamGeneration
    {
        return new BuildExamGeneration(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
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
            'name' => "Exam Build Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Exam Build Curriculum {$curriculumId}",
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
            'name' => "Exam Template {$templateId}",
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

        return [$templateVersionId, $versionId, $rules];
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
            'name' => "Exam Build Topic {$topicId}",
            'display_order' => $displayOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Exam Build Skill {$skillId}",
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
            'internal_label' => "Exam Build Item {$itemId}",
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

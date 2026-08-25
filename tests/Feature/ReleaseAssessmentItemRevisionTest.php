<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleaseAssessmentItemRevisionTest extends TestCase
{
    public function test_revision_with_primary_skill_can_be_released(): void
    {
        [$revisionId] = $this->createAssessmentFixture(
            withPrimarySkill: true
        );

        $result = $this->service()->execute($revisionId);

        $this->assertSame($revisionId, $result->id);
        $this->assertNotNull($result->released_at);

        $this->assertNotNull(
            DB::table('assessment_item_revisions')
                ->where('id', $revisionId)
                ->value('released_at')
        );
    }

    public function test_revision_without_primary_skill_cannot_be_released(): void
    {
        [$revisionId] = $this->createAssessmentFixture(
            withPrimarySkill: false
        );

        try {
            $this->service()->execute($revisionId);

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertNull(
            DB::table('assessment_item_revisions')
                ->where('id', $revisionId)
                ->value('released_at')
        );
    }

    public function test_released_revision_cannot_be_released_again(): void
    {
        [$revisionId] = $this->createAssessmentFixture(
            withPrimarySkill: true
        );

        $first = $this->service()->execute($revisionId);
        $releasedAt = $first->released_at;

        try {
            $this->service()->execute($revisionId);

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $persisted = DB::table('assessment_item_revisions')
            ->where('id', $revisionId)
            ->value('released_at');

        $this->assertSame(
            $releasedAt->format('Y-m-d H:i:sP'),
            \Carbon\CarbonImmutable::parse($persisted)
                ->format('Y-m-d H:i:sP')
        );
    }

    private function service(): ReleaseAssessmentItemRevision
    {
        return new ReleaseAssessmentItemRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    /**
     * @return array{string, string}
     */
    private function createAssessmentFixture(
        bool $withPrimarySkill,
    ): array {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Assessment Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Assessment Curriculum {$curriculumId}",
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

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Assessment Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Assessment Skill {$skillId}",
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
            'internal_label' => "Assessment Item {$itemId}",
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
                'stem' => '1 + 1 = ?',
                'options' => [1, 2, 3, 4],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'scoring_payload' => json_encode([
                'correct_option' => 1,
            ], JSON_THROW_ON_ERROR),
            'scoring_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        if ($withPrimarySkill) {
            DB::table('assessment_item_revision_skills')->insert([
                'id' => (string) Str::uuid(),
                'assessment_item_revision_id' => $revisionId,
                'skill_version_placement_id' => $placementId,
                'curriculum_version_id' => $versionId,
                'role' => 'primary',
                'created_at' => now(),
            ]);
        }

        return [$revisionId, $itemId];
    }
}

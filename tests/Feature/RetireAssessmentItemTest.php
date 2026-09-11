<?php

namespace Tests\Feature;

use App\Application\Assessment\PublishAssessmentItem;
use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Assessment\RetireAssessmentItem;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetireAssessmentItemTest extends TestCase
{
    public function test_published_assessment_item_can_be_retired(): void
    {
        [$itemId, $revisionId] = $this->createAssessmentFixture();

        $this->releaseRevision($revisionId);
        $this->publishItem($itemId, $revisionId);

        $result = $this->service()->execute($itemId);

        $this->assertSame($itemId, $result->id);
        $this->assertSame('retired', $result->status);

        $this->assertDatabaseHas('assessment_items', [
            'id' => $itemId,
            'status' => 'retired',
            'published_revision_id' => $revisionId,
        ]);
    }

    public function test_draft_assessment_item_cannot_be_retired(): void
    {
        [$itemId] = $this->createAssessmentFixture();

        try {
            $this->service()->execute($itemId);

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertDatabaseHas('assessment_items', [
            'id' => $itemId,
            'status' => 'draft',
            'published_revision_id' => null,
        ]);
    }

    private function service(): RetireAssessmentItem
    {
        return new RetireAssessmentItem(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    private function releaseRevision(string $revisionId): void
    {
        $service = new ReleaseAssessmentItemRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        $service->execute($revisionId);
    }

    private function publishItem(
        string $itemId,
        string $revisionId,
    ): void {
        $service = new PublishAssessmentItem(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        $service->execute($itemId, $revisionId);
    }

    /**
     * @return array{string, string}
     */
    private function createAssessmentFixture(): array
    {
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
            'name' => "Retire Assessment Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Retire Assessment Curriculum {$curriculumId}",
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
            'name' => "Retire Assessment Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Retire Assessment Skill {$skillId}",
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
            'internal_label' => "Retire Assessment Item {$itemId}",
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
                'stem' => '3 + 3 = ?',
                'options' => [5, 6, 7, 8],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'scoring_payload' => json_encode([
                'correct_option' => 1,
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

        return [$itemId, $revisionId];
    }
}

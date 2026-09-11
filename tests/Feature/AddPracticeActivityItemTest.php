<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Practice\AddPracticeActivityItem;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AddPracticeActivityItemTest extends TestCase
{
    public function test_released_revision_can_be_added_to_active_activity(): void
    {
        [$activityId, $versionId] = $this->createActiveActivity();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            released: true,
        );

        $result = $this->service()->execute(
            $activityId,
            $revisionId,
            $itemId,
            1,
        );

        $this->assertSame($activityId, $result->practice_activity_id);
        $this->assertSame($revisionId, $result->assessment_item_revision_id);
        $this->assertSame($itemId, $result->assessment_item_id);
        $this->assertSame($versionId, $result->curriculum_version_id);
        $this->assertSame(1, $result->display_order);

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $result->id,
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
            'assessment_item_id' => $itemId,
            'display_order' => 1,
        ]);
    }

    public function test_unreleased_revision_cannot_be_added_to_active_activity(): void
    {
        [$activityId, $versionId] = $this->createActiveActivity();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            released: false,
        );

        try {
            $this->service()->execute(
                $activityId,
                $revisionId,
                $itemId,
                1,
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertDatabaseMissing('practice_activity_items', [
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
        ]);
    }

    public function test_item_provenance_mismatch_is_rejected(): void
    {
        [$activityId, $versionId] = $this->createActiveActivity();

        [$revisionId] = $this->createAssessmentRevision(
            $versionId,
            released: true,
        );

        [, $otherItemId] = $this->createAssessmentRevision(
            $versionId,
            released: true,
        );

        try {
            $this->service()->execute(
                $activityId,
                $revisionId,
                $otherItemId,
                1,
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('23503', $exception->sqlState);
        }

        $this->assertDatabaseMissing('practice_activity_items', [
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
            'assessment_item_id' => $otherItemId,
        ]);
    }

    private function service(): AddPracticeActivityItem
    {
        return new AddPracticeActivityItem(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    /**
     * @return array{string, string}
     */
    private function createActiveActivity(): array
    {
        [$versionId] = $this->createCurriculumVersion();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId,
            released: true,
        );

        $activityId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => "Practice Activity {$activityId}",
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('practice_activity_items')->insert([
            'id' => (string) Str::uuid(),
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'display_order' => 0,
            'created_at' => now(),
        ]);

        DB::table('practice_activities')
            ->where('id', $activityId)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        return [$activityId, $versionId];
    }

    /**
     * @return array{string, string}
     */
    private function createAssessmentRevision(
        string $versionId,
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
            'name' => "Practice Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Practice Skill {$skillId}",
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
            'internal_label' => "Practice Item {$itemId}",
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
                'stem' => '4 + 4 = ?',
                'options' => [6, 7, 8, 9],
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

    /**
     * @return array{string, string, string}
     */
    private function createCurriculumVersion(): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Practice Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Practice Curriculum {$curriculumId}",
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

        return [$versionId, $curriculumId, $subjectId];
    }
}

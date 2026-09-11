<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Practice\RemovePracticeActivityItem;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemovePracticeActivityItemTest extends TestCase
{
    public function test_item_can_be_removed_when_active_activity_keeps_another_item(): void
    {
        [$activityId, $versionId] = $this->createActiveActivity();

        [$revisionId, $itemId] = $this->createAssessmentRevision(
            $versionId
        );

        $membershipId = $this->addMembership(
            $activityId,
            $versionId,
            $revisionId,
            $itemId,
            1,
        );

        $this->service()->execute(
            $activityId,
            $membershipId
        );

        $this->assertDatabaseMissing('practice_activity_items', [
            'id' => $membershipId,
        ]);

        $this->assertSame(
            1,
            DB::table('practice_activity_items')
                ->where('practice_activity_id', $activityId)
                ->count()
        );
    }

    public function test_last_item_cannot_be_removed_from_active_activity(): void
    {
        [$activityId] = $this->createActiveActivity();

        $membershipId = DB::table('practice_activity_items')
            ->where('practice_activity_id', $activityId)
            ->value('id');

        $this->assertNotNull($membershipId);

        try {
            $this->service()->execute(
                $activityId,
                $membershipId
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $membershipId,
            'practice_activity_id' => $activityId,
        ]);

        $this->assertSame(
            1,
            DB::table('practice_activity_items')
                ->where('practice_activity_id', $activityId)
                ->count()
        );
    }

    private function service(): RemovePracticeActivityItem
    {
        return new RemovePracticeActivityItem(
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
            $versionId
        );

        $activityId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => "Remove Practice {$activityId}",
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->addMembership(
            $activityId,
            $versionId,
            $revisionId,
            $itemId,
            0,
        );

        DB::table('practice_activities')
            ->where('id', $activityId)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        return [$activityId, $versionId];
    }

    private function addMembership(
        string $activityId,
        string $versionId,
        string $revisionId,
        string $itemId,
        int $displayOrder,
    ): string {
        $membershipId = (string) Str::uuid();

        DB::table('practice_activity_items')->insert([
            'id' => $membershipId,
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'display_order' => $displayOrder,
            'created_at' => now(),
        ]);

        return $membershipId;
    }

    /**
     * @return array{string, string}
     */
    private function createAssessmentRevision(
        string $versionId,
    ): array {
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Remove Practice Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Remove Practice Skill {$skillId}",
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
            'internal_label' => "Remove Practice Item {$itemId}",
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
                'stem' => '5 + 5 = ?',
                'options' => [8, 9, 10, 11],
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

        $service = new ReleaseAssessmentItemRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        $service->execute($revisionId);

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
            'name' => "Remove Practice Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Remove Practice Curriculum {$curriculumId}",
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

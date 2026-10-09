<?php

namespace Tests\Feature\Concurrency;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Exceptions\TeacherAuthoringConflict;
use App\Application\Practice\RemovePracticeActivityItem;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class TeacherPracticeAttemptConcurrencyTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_practice_add_and_remove_serialize(): void
    {
        $fixture = $this->fixture(published: false);
        $before = $this->membershipRevisionSet($fixture['practice_id']);

        [$add, $remove] = $this->raceUnderPracticeLock(
            [
                'action' => 'practice_add_item',
                'practice_id' => $fixture['practice_id'],
                'revision_id' => $fixture['revisions']['c']['revision_id'],
                'item_id' => $fixture['revisions']['c']['item_id'],
                'display_order' => 2,
            ],
            [
                'action' => 'practice_remove_item',
                'practice_id' => $fixture['practice_id'],
                'membership_id' => $fixture['memberships']['b'],
            ],
        );

        $this->assertWorkerSucceeded($add, 'practice_add_item');
        $this->assertWorkerSucceeded($remove, 'practice_remove_item');
        $this->assertSame([
            $fixture['revisions']['a']['revision_id'],
            $fixture['revisions']['c']['revision_id'],
        ], $this->membershipRevisionSet($fixture['practice_id']));
        $this->assertSame(
            $fixture['revisions']['c']['revision_id'],
            $add['result']['assessment_item_revision_id'],
        );
        $this->assertSame(
            0,
            DB::table('practice_activity_items')
                ->where('id', $fixture['memberships']['b'])
                ->count(),
        );
        $this->assertCount(2, $before);
    }

    public function test_active_practice_never_loses_its_last_membership(): void
    {
        $fixture = $this->fixture(published: false, initialMemberships: ['a']);

        try {
            (new RemovePracticeActivityItem(
                new TransactionManager(new PostgresExceptionTranslator),
            ))->execute($fixture['practice_id'], $fixture['memberships']['a']);
            $this->fail('Expected active-practice last-membership rejection.');
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertSame(
            'active',
            DB::table('practice_activities')
                ->where('id', $fixture['practice_id'])
                ->value('status'),
        );
        $this->assertSame(1, DB::table('practice_activity_items')
            ->where('practice_activity_id', $fixture['practice_id'])
            ->count());
        $this->assertSame(
            [$fixture['revisions']['a']['revision_id']],
            $this->membershipRevisionSet($fixture['practice_id']),
        );
    }

    public function test_published_practice_rejects_teacher_mutation_while_attempt_uses_complete_pre_snapshot(): void
    {
        $fixture = $this->fixture(published: true);
        $pre = $this->membershipRevisionSet($fixture['practice_id']);

        [$add, $build] = $this->raceUnderPracticeLock(
            [
                'action' => 'teacher_practice_add_items',
                'actor_user_id' => $fixture['actor_user_id'],
                'assignment_id' => $fixture['assignment_id'],
                'curriculum_id' => $fixture['curriculum_id'],
                'curriculum_version_id' => $fixture['version_id'],
                'practice_id' => $fixture['practice_id'],
                'revision_ids' => [$fixture['revisions']['c']['revision_id']],
                'display_order' => 2,
            ],
            [
                'action' => 'build_practice_attempt',
                'authenticated_user_id' => $fixture['learner_user_id'],
                'learner_profile_id' => $fixture['learner_profile_id'],
                'practice_id' => $fixture['practice_id'],
            ],
        );

        $this->assertWorkerExpectedDomainFailure(
            $add,
            'teacher_practice_add_items',
            TeacherAuthoringConflict::class,
        );
        $this->assertSame(
            'Teacher authoring requires a draft curriculum version.',
            $add['message'],
        );
        $this->assertWorkerSucceeded($build, 'build_practice_attempt');
        $this->assertSame($pre, $this->membershipRevisionSet($fixture['practice_id']));
        $this->assertSame(0, DB::table('practice_activity_items')
            ->where('practice_activity_id', $fixture['practice_id'])
            ->where(
                'assessment_item_revision_id',
                $fixture['revisions']['c']['revision_id'],
            )
            ->count());
        $this->assertSame('active', DB::table('practice_activities')
            ->where('id', $fixture['practice_id'])
            ->value('status'));

        $attemptId = $build['result']['attempt_id'];
        $snapshot = $this->attemptRevisionSet($attemptId);
        $this->assertSame($pre, $snapshot);
        $this->assertSame(count($snapshot), count(array_unique($snapshot)));
        $this->assertSame(
            count($snapshot),
            DB::table('attempt_items')->where('attempt_id', $attemptId)->count(),
        );
        $this->assertSame($fixture['practice_id'], DB::table('attempts')
            ->where('id', $attemptId)->value('practice_activity_id'));
        $this->assertSame($fixture['version_id'], DB::table('attempts')
            ->where('id', $attemptId)->value('curriculum_version_id'));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function raceUnderPracticeLock(array $firstPayload, array $secondPayload): array
    {
        $first = null;
        $second = null;
        DB::beginTransaction();

        try {
            DB::table('practice_activities')
                ->where('id', $firstPayload['practice_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $first = PostgresProcessBarrier::start($firstPayload, 'phase_h5_concurrency_worker.php');
            $second = PostgresProcessBarrier::start($secondPayload, 'phase_h5_concurrency_worker.php');
            $firstReady = $first->awaitReady();
            $secondReady = $second->awaitReady();
            $first->release();
            $first->awaitBlockedByCurrentConnection($firstReady['pid']);
            $second->release();
            $second->awaitBlockedByProcess(
                $firstReady['pid'],
                $secondReady['pid'],
            );
            DB::commit();

            $firstResult = $first->finish();
            $secondResult = $second->finish();

            return [$firstResult, $secondResult];
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $first?->cleanup();
            $second?->cleanup();
        }
    }

    /** @param array<string, mixed> $result */
    private function assertWorkerExpectedDomainFailure(
        array $result,
        string $action,
        string $exceptionClass,
    ): void {
        foreach ([
            'ok',
            'operation',
            'failure_type',
            'result',
            'exception_class',
            'message',
            'sqlstate',
            '_exit_code',
        ] as $field) {
            $this->assertArrayHasKey($field, $result);
        }

        $this->assertFalse($result['ok']);
        $this->assertSame($action, $result['operation']);
        $this->assertSame('domain', $result['failure_type']);
        $this->assertNull($result['result']);
        $this->assertSame($exceptionClass, $result['exception_class']);
        $this->assertNull($result['sqlstate']);
        $this->assertSame(0, $result['_exit_code']);
    }

    /** @param array<string, mixed> $result */
    private function assertWorkerSucceeded(array $result, string $action): void
    {
        foreach (['ok', 'operation', 'failure_type', 'result', 'exception_class', 'message', 'sqlstate', '_exit_code'] as $field) {
            $this->assertArrayHasKey($field, $result);
        }
        $this->assertTrue($result['ok'], json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame($action, $result['operation']);
        $this->assertNull($result['failure_type']);
        $this->assertSame(0, $result['_exit_code']);
    }

    /** @return list<string> */
    private function membershipRevisionSet(string $practiceId): array
    {
        return DB::table('practice_activity_items')
            ->where('practice_activity_id', $practiceId)
            ->orderBy('display_order')
            ->orderBy('assessment_item_revision_id')
            ->pluck('assessment_item_revision_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /** @return list<string> */
    private function attemptRevisionSet(string $attemptId): array
    {
        return DB::table('attempt_items')
            ->where('attempt_id', $attemptId)
            ->orderBy('presentation_position')
            ->orderBy('assessment_item_revision_id')
            ->pluck('assessment_item_revision_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @param  list<string>  $initialMemberships
     * @return array<string, mixed>
     */
    private function fixture(bool $published, array $initialMemberships = ['a', 'b']): array
    {
        $curriculum = $this->createOwnedCurriculumFixture('H5 R2 '.Str::uuid());
        $versionId = (string) Str::uuid();
        $learnerUserId = (string) Str::uuid();
        $learnerProfileId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $practiceId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $learnerUserId,
            'name' => 'H5 R2 learner '.$learnerUserId,
            'email' => 'h5-r2-'.$learnerUserId.'@example.test',
            'password' => 'not-used',
            'status' => 'active',
            'role' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learner_profiles')->insert([
            'id' => $learnerProfileId,
            'user_id' => $learnerUserId,
            'created_at' => now(),
        ]);
        $enrollment = app(RequestStudentEnrollment::class)->execute(
            $learnerUserId,
            $learnerProfileId,
            $curriculum->teacher_subject_assignment_id,
            (string) Str::uuid(),
            'H5 R2 fixture',
        );
        $teacherId = DB::table('teacher_subject_assignments')
            ->where('id', $curriculum->teacher_subject_assignment_id)
            ->value('teacher_id');
        $this->assertIsString($teacherId);
        app(AcceptStudentEnrollment::class)->execute(
            $teacherId,
            $enrollment->id,
            (string) Str::uuid(),
            'H5 R2 fixture',
        );

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'h5-r2',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => 'H5 R2 topic '.$topicId,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => 'H5 R2 skill '.$skillId,
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

        $revisions = [];
        foreach (['a', 'b', 'c'] as $index => $label) {
            $revisions[$label] = $this->releasedRevision($versionId, $topicId, $placementId, $label, $index + 1);
        }

        DB::table('practice_activities')->insert([
            'id' => $practiceId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => 'H5 R2 practice '.$practiceId,
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $memberships = [];
        foreach ($initialMemberships as $position => $label) {
            $memberships[$label] = $this->addMembership($practiceId, $versionId, $revisions[$label], $position);
        }
        DB::table('practice_activities')->where('id', $practiceId)->update([
            'status' => 'active',
            'updated_at' => now(),
        ]);
        if ($published) {
            DB::table('curriculum_versions')->where('id', $versionId)->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);
        }

        return compact('versionId', 'learnerUserId', 'learnerProfileId', 'practiceId', 'revisions', 'memberships') + [
            'actor_user_id' => $teacherId,
            'assignment_id' => $curriculum->teacher_subject_assignment_id,
            'curriculum_id' => $curriculum->id,
            'version_id' => $versionId,
            'learner_user_id' => $learnerUserId,
            'learner_profile_id' => $learnerProfileId,
            'practice_id' => $practiceId,
        ];
    }

    /** @return array{revision_id: string, item_id: string} */
    private function releasedRevision(string $versionId, string $topicId, string $placementId, string $label, int $number): array
    {
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();
        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' => $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' => 'H5 R2 item '.$label,
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
            'content_payload' => json_encode(['stem' => 'H5 R2 '.$label], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'scoring_payload' => json_encode(['correct' => true], JSON_THROW_ON_ERROR),
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
        app(ReleaseAssessmentItemRevision::class)->execute($revisionId);

        return ['revision_id' => $revisionId, 'item_id' => $itemId];
    }

    /** @param array{revision_id: string, item_id: string} $revision */
    private function addMembership(string $practiceId, string $versionId, array $revision, int $position): string
    {
        $id = (string) Str::uuid();
        DB::table('practice_activity_items')->insert([
            'id' => $id,
            'practice_activity_id' => $practiceId,
            'assessment_item_revision_id' => $revision['revision_id'],
            'assessment_item_id' => $revision['item_id'],
            'curriculum_version_id' => $versionId,
            'display_order' => $position,
            'created_at' => now(),
        ]);

        return $id;
    }
}

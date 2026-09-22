<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Attempt\BuildPracticeAttempt;
use App\Application\Authorization\LockActiveLearnerCurriculumGrant;
use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class BuildPracticeAttemptTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_active_practice_activity_builds_exact_attempt_snapshot(): void
    {
        [
            $learnerId,
            $activityId,
            $revisionId,
            $itemId,
            $skillId,
            $versionId,
        ] = $this->createPracticeFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $activityId,
        );

        $this->assertSame($learnerId, $attempt->learner_profile_id);
        $this->assertNull($attempt->exam_generation_id);
        $this->assertSame($activityId, $attempt->practice_activity_id);
        $this->assertSame($versionId, $attempt->curriculum_version_id);
        $this->assertSame('in_progress', $attempt->status);
        $this->assertNotNull($attempt->started_at);
        $this->assertNull($attempt->finalized_at);

        $attemptItem = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->first();

        $this->assertNotNull($attemptItem);
        $this->assertSame($revisionId, $attemptItem->assessment_item_revision_id);
        $this->assertSame($itemId, $attemptItem->assessment_item_id);
        $this->assertNull($attemptItem->exam_generation_id);
        $this->assertNull($attemptItem->exam_generation_item_id);
        $this->assertSame(0, $attemptItem->presentation_position);

        $revision = DB::table('assessment_item_revisions')
            ->where('id', $revisionId)
            ->first();

        $this->assertNotNull($revision);

        $this->assertEquals(
            json_decode(
                $revision->content_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
            json_decode(
                $attemptItem->presented_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
        );

        $this->assertEquals(
            json_decode(
                $revision->scoring_payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
            json_decode(
                $attemptItem->scoring_snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            ),
        );

        $this->assertSame(
            $revision->primary_topic_id,
            $attemptItem->primary_topic_id
        );

        $this->assertDatabaseHas(
            'attempt_item_classification_skills',
            [
                'attempt_item_id' => $attemptItem->id,
                'skill_id' => $skillId,
                'role' => 'primary',
            ]
        );

        $this->assertDatabaseHas(
            'attempt_responses',
            [
                'attempt_item_id' => $attemptItem->id,
                'answer_change_count' => 0,
                'time_spent_ms' => 0,
                'original_is_correct' => null,
            ]
        );

        $this->assertSame(
            1,
            DB::table('attempt_items')
                ->where('attempt_id', $attempt->id)
                ->count()
        );
    }

    public function test_archived_practice_activity_is_rejected_inside_attempt_transaction(): void
    {
        [
            $learnerId,
            $activityId,
        ] = $this->createPracticeFixture(
            archiveBeforeCurriculumPublish: true,
        );

        try {
            $this->service()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $activityId,
            );

            $this->fail(
                'Expected ModelNotFoundException was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            0,
            DB::table('attempts')
                ->where(
                    'practice_activity_id',
                    $activityId,
                )
                ->count()
        );
    }

    public function test_unpublished_practice_curriculum_is_rejected_inside_attempt_transaction(): void
    {
        [
            $learnerId,
            $activityId,
            ,
            ,
            ,
            $versionId,
        ] = $this->createPracticeFixture();

        (new RetireCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        ))->execute($versionId);

        try {
            $this->service()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $activityId,
            );

            $this->fail(
                'Expected ModelNotFoundException was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            0,
            DB::table('attempts')
                ->where(
                    'practice_activity_id',
                    $activityId,
                )
                ->count()
        );
    }

    public function test_inactive_enrollment_rejects_new_practice_attempt(): void
    {
        [
            $learnerId,
            $activityId,
            ,
            ,
            ,
            $versionId,
        ] = $this->createPracticeFixture();

        $curriculumId = DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->value('curriculum_id');

        $assignmentId = DB::table('curricula')
            ->where('id', $curriculumId)
            ->value('teacher_subject_assignment_id');

        $enrollmentId = DB::table('student_enrollments')
            ->where('learner_profile_id', $learnerId)
            ->where(
                'teacher_subject_assignment_id',
                $assignmentId,
            )
            ->value('id');

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where('id', $assignmentId)
            ->value('teacher_id');

        $this->assertIsString($enrollmentId);
        $this->assertIsString($teacherId);

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollmentId,
            operationId: (string) Str::uuid(),
            reason: 'Phase F practice authorization revocation.',
        );

        try {
            $this->service()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $activityId,
            );

            $this->fail(
                'Expected ModelNotFoundException was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            0,
            DB::table('attempts')
                ->where('learner_profile_id', $learnerId)
                ->where('practice_activity_id', $activityId)
                ->count()
        );
    }

    public function test_mismatched_authenticated_user_rejects_new_practice_attempt_without_mutation(): void
    {
        [
            $learnerId,
            $activityId,
        ] = $this->createPracticeFixture();

        $otherStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        try {
            $this->service()->execute(
                $otherStudent->id,
                $learnerId,
                $activityId,
            );

            $this->fail(
                'Expected ModelNotFoundException was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseMissing(
            'attempts',
            [
                'learner_profile_id' => $learnerId,
                'practice_activity_id' => $activityId,
            ],
        );
    }

    public function test_disabled_owner_rejects_new_practice_attempt_without_mutation(): void
    {
        [
            $learnerId,
            $activityId,
        ] = $this->createPracticeFixture();

        $userId =
            $this->authenticatedUserId(
                $learnerId
            );

        DB::table('users')
            ->where('id', $userId)
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);

        try {
            $this->service()->execute(
                $userId,
                $learnerId,
                $activityId,
            );

            $this->fail(
                'Expected ModelNotFoundException was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseMissing(
            'attempts',
            [
                'learner_profile_id' => $learnerId,
                'practice_activity_id' => $activityId,
            ],
        );
    }

    public function test_enrollment_deactivation_serializes_before_new_practice_attempt(): void
    {
        [
            $learnerId,
            $activityId,
            ,
            ,
            ,
            $versionId,
        ] = $this->createPracticeFixture();

        $curriculumId =
            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->value('curriculum_id');

        $assignmentId =
            DB::table('curricula')
                ->where('id', $curriculumId)
                ->value(
                    'teacher_subject_assignment_id'
                );

        $enrollmentId =
            DB::table('student_enrollments')
                ->where(
                    'learner_profile_id',
                    $learnerId,
                )
                ->where(
                    'teacher_subject_assignment_id',
                    $assignmentId,
                )
                ->value('id');

        $teacherId =
            DB::table(
                'teacher_subject_assignments'
            )
                ->where('id', $assignmentId)
                ->value('teacher_id');

        $this->assertIsString($enrollmentId);
        $this->assertIsString($teacherId);

        $barrier = null;

        DB::beginTransaction();

        try {
            app(
                DeactivateStudentEnrollment::class
            )->execute(
                actorUserId: $teacherId,
                enrollmentId: $enrollmentId,
                operationId: (string) Str::uuid(),
                reason: 'Practice deactivation-wins race.',
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where(
                        'id',
                        $enrollmentId,
                    )
                    ->value('status'),
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'build_practice_attempt',
                    'authenticated_user_id' => $this->authenticatedUserId(
                        $learnerId
                    ),
                    'learner_profile_id' => $learnerId,
                    'practice_activity_id' => $activityId,
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $this->assertPostgresBlockedByParent(
                $barrier,
                $ready['pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                0,
                DB::table('attempts')
                    ->where(
                        'learner_profile_id',
                        $learnerId,
                    )
                    ->where(
                        'practice_activity_id',
                        $activityId,
                    )
                    ->count(),
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where(
                        'id',
                        $enrollmentId,
                    )
                    ->value('status'),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    public function test_new_practice_attempt_serializes_before_enrollment_deactivation(): void
    {
        [
            $learnerId,
            $activityId,
            ,
            ,
            ,
            $versionId,
        ] = $this->createPracticeFixture();

        $curriculumId =
            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->value('curriculum_id');

        $assignmentId =
            DB::table('curricula')
                ->where('id', $curriculumId)
                ->value(
                    'teacher_subject_assignment_id'
                );

        $enrollmentId =
            DB::table('student_enrollments')
                ->where(
                    'learner_profile_id',
                    $learnerId,
                )
                ->where(
                    'teacher_subject_assignment_id',
                    $assignmentId,
                )
                ->value('id');

        $teacherId =
            DB::table(
                'teacher_subject_assignments'
            )
                ->where('id', $assignmentId)
                ->value('teacher_id');

        $this->assertIsString($enrollmentId);
        $this->assertIsString($teacherId);

        $barrier = null;

        DB::beginTransaction();

        try {
            $attempt =
                $this->service()->execute(
                    $this->authenticatedUserId(
                        $learnerId
                    ),
                    $learnerId,
                    $activityId,
                );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'deactivate_student_enrollment',
                    'actor_user_id' => $teacherId,
                    'enrollment_id' => $enrollmentId,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Practice attempt-wins race.',
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $this->assertPostgresBlockedByParent(
                $barrier,
                $ready['pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'success',
                $result['result'] ?? null,
            );

            $this->assertSame(
                'inactive',
                $result['data']['status']
                    ?? null,
            );

            $this->assertDatabaseHas(
                'attempts',
                [
                    'id' => $attempt->id,
                    'learner_profile_id' => $learnerId,
                    'practice_activity_id' => $activityId,
                ],
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where(
                        'id',
                        $enrollmentId,
                    )
                    ->value('status'),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    public function test_curriculum_retirement_serializes_against_practice_attempt_construction(): void
    {
        [
            $learnerId,
            $activityId,
            ,
            ,
            ,
            $versionId,
        ] = $this->createPracticeFixture();

        $barrier = null;

        DB::beginTransaction();

        try {
            $lockedVersion =
                DB::table('curriculum_versions')
                    ->where('id', $versionId)
                    ->lockForUpdate()
                    ->first();

            $this->assertNotNull(
                $lockedVersion
            );

            $this->assertSame(
                'published',
                $lockedVersion->status,
            );

            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->update([
                    'status' => 'retired',
                    'updated_at' => now(),
                ]);

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'build_practice_attempt',
                    'authenticated_user_id' => $this->authenticatedUserId(
                        $learnerId
                    ),
                    'learner_profile_id' => $learnerId,
                    'practice_activity_id' => $activityId,
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $this->assertPostgresBlockedByParent(
                $barrier,
                $ready['pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                0,
                DB::table('attempts')
                    ->where(
                        'practice_activity_id',
                        $activityId,
                    )
                    ->count(),
            );

            $this->assertSame(
                'retired',
                DB::table('curriculum_versions')
                    ->where('id', $versionId)
                    ->value('status'),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    private function assertPostgresBlockedByParent(
        PostgresProcessBarrier $barrier,
        int $childPid,
    ): void {
        $wait =
            $barrier
                ->awaitBlockedByCurrentConnection(
                    $childPid
                );

        $this->assertSame(
            'Lock',
            $wait['wait_event_type'],
        );

        $this->assertTrue(
            $wait['blocked_by_parent'],
        );

        $this->assertSame(
            $childPid,
            $wait['child_pid'],
        );
    }

    private function authenticatedUserId(
        string $learnerId,
    ): string {
        $userId = DB::table('learner_profiles')
            ->where('id', $learnerId)
            ->value('user_id');

        $this->assertIsString(
            $userId
        );

        return $userId;
    }

    private function service(): BuildPracticeAttempt
    {
        return new BuildPracticeAttempt(
            new TransactionManager(
                new PostgresExceptionTranslator
            ),
            new LockActiveLearnerCurriculumGrant,
        );
    }

    /**
     * @return array{string, string, string, string, string, string}
     */
    private function createPracticeFixture(
        bool $archiveBeforeCurriculumPublish = false,
    ): array {
        $userId = (string) Str::uuid();
        $learnerId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();
        $activityId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => "Practice User {$userId}",
            'email' => "practice-{$userId}@example.test",
            'password' => 'not-used',
            'status' => 'active',
            'role' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('learner_profiles')->insert([
            'id' => $learnerId,
            'user_id' => $userId,
            'created_at' => now(),
        ]);

        $curriculum =
            $this->createOwnedCurriculumFixture(
                'Owned Curriculum '.Str::uuid()
            );

        $curriculumId = $curriculum->id;
        $subjectId = $curriculum->subject_id;

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $userId,
            learnerProfileId: $learnerId,
            assignmentId: $curriculum->teacher_subject_assignment_id,
            operationId: (string) Str::uuid(),
            reason: 'Practice Attempt authorization fixture.',
        );

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $curriculum->teacher_subject_assignment_id,
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Practice Attempt authorization fixture acceptance.',
        );

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
                'stem' => '9 + 6 = ?',
                'options' => [13, 14, 15, 16],
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

        (new ReleaseAssessmentItemRevision(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        ))->execute($revisionId);

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

        /*
         * Fixture-only state construction.
         *
         * Curriculum publishing readiness is covered separately.
         * This suite exercises PracticeAttempt source and snapshot
         * semantics against an already-published curriculum.
         */
        if ($archiveBeforeCurriculumPublish) {
            DB::table('practice_activities')
                ->where('id', $activityId)
                ->update([
                    'status' => 'archived',
                    'updated_at' => now(),
                ]);
        }

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        return [
            $learnerId,
            $activityId,
            $revisionId,
            $itemId,
            $skillId,
            $versionId,
        ];
    }
}

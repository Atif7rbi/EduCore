<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Attempt\AddRegradeCorrection;
use App\Application\Attempt\BuildExamAttempt;
use App\Application\Attempt\FinalizeAttempt;
use App\Application\Attempt\SaveAttemptResponse;
use App\Application\Authorization\LockActiveLearnerCurriculumGrant;
use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exam\BuildExamGeneration;
use App\Application\Exceptions\IntegrityConstraintViolation;
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

class BuildExamAttemptTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_exam_attempt_copies_exact_generation_and_revision_truth(): void
    {
        [$learnerId, $generationId, $revisionId, $itemId, $skillId] =
            $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $this->assertSame($learnerId, $attempt->learner_profile_id);
        $this->assertSame($generationId, $attempt->exam_generation_id);
        $this->assertNull($attempt->practice_activity_id);
        $this->assertSame('in_progress', $attempt->status);
        $this->assertNotNull($attempt->started_at);
        $this->assertNull($attempt->finalized_at);

        $attemptItem = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->first();

        $this->assertNotNull($attemptItem);
        $this->assertSame($revisionId, $attemptItem->assessment_item_revision_id);
        $this->assertSame($itemId, $attemptItem->assessment_item_id);
        $this->assertSame($generationId, $attemptItem->exam_generation_id);
        $this->assertNotNull($attemptItem->exam_generation_item_id);
        $this->assertSame(0, $attemptItem->presentation_position);

        $revision = DB::table('assessment_item_revisions')
            ->where('id', $revisionId)
            ->first();

        $this->assertNotNull($revision);

        $this->assertEquals(
            json_decode($revision->content_payload, true, 512, JSON_THROW_ON_ERROR),
            json_decode($attemptItem->presented_payload, true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertEquals(
            json_decode($revision->scoring_payload, true, 512, JSON_THROW_ON_ERROR),
            json_decode($attemptItem->scoring_snapshot, true, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertSame(
            $revision->content_schema_version,
            $attemptItem->presented_schema_version
        );

        $this->assertSame(
            $revision->scoring_schema_version,
            $attemptItem->scoring_schema_version
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

        $this->assertDatabaseHas('attempt_responses', [
            'attempt_item_id' => $attemptItem->id,
            'answer_change_count' => 0,
            'time_spent_ms' => 0,
            'original_is_correct' => null,
        ]);

        $this->assertSame(
            1,
            DB::table('attempt_items')
                ->where('attempt_id', $attempt->id)
                ->count()
        );
    }

    public function test_unpublished_exam_curriculum_is_rejected_inside_attempt_transaction(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $generation = DB::table('exam_generations')
            ->where('id', $generationId)
            ->first();

        $this->assertNotNull($generation);

        (new RetireCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        ))->execute(
            $generation->curriculum_version_id
        );

        try {
            $this->service()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $generationId,
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
                    'exam_generation_id',
                    $generationId,
                )
                ->count()
        );
    }

    public function test_second_attempt_for_same_exam_generation_is_rejected(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        try {
            $this->service()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $generationId,
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('23505', $exception->sqlState);
        }

        $this->assertSame(
            1,
            DB::table('attempts')
                ->where('exam_generation_id', $generationId)
                ->count()
        );
    }

    public function test_first_answer_is_saved_without_incrementing_change_count(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $response = $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            1500,
        );

        $this->assertEquals(
            ['selected_option' => 2],
            $response->response_payload
        );
        $this->assertSame(0, $response->answer_change_count);
        $this->assertSame(1500, $response->time_spent_ms);
        $this->assertNull($response->original_is_correct);
    }

    public function test_saving_same_answer_does_not_increment_change_count(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            1000,
        );

        $response = $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            2200,
        );

        $this->assertSame(0, $response->answer_change_count);
        $this->assertSame(2200, $response->time_spent_ms);
    }

    public function test_changing_existing_answer_increments_change_count(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 1],
            1000,
        );

        $response = $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            2500,
        );

        $this->assertEquals(
            ['selected_option' => 2],
            $response->response_payload
        );
        $this->assertSame(1, $response->answer_change_count);
        $this->assertSame(2500, $response->time_spent_ms);
        $this->assertNull($response->original_is_correct);
    }

    public function test_negative_time_spent_is_rejected_and_response_rolls_back(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        try {
            $this->responseService()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $attemptItemId,
                ['selected_option' => 2],
                -1,
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('23514', $exception->sqlState);
        }

        $response = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->first();

        $this->assertNotNull($response);
        $this->assertNull($response->response_payload);
        $this->assertSame(0, $response->answer_change_count);
        $this->assertSame(0, $response->time_spent_ms);
        $this->assertNull($response->original_is_correct);
    }

    public function test_answered_attempt_is_scored_from_server_snapshot_on_submission(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            1800,
        );

        $finalized = $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
        );

        $this->assertSame('submitted', $finalized->status);
        $this->assertNotNull($finalized->finalized_at);

        $response = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->first();

        $this->assertNotNull($response);
        $this->assertTrue($response->original_is_correct);
    }

    public function test_incorrect_answer_is_scored_from_server_snapshot_on_submission(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 1],
            900,
        );

        $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
        );

        $response = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->first();

        $this->assertNotNull($response);
        $this->assertFalse($response->original_is_correct);
    }

    public function test_unanswered_item_remains_without_original_correctness(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $finalized = $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
        );

        $this->assertSame('submitted', $finalized->status);
        $this->assertNotNull($finalized->finalized_at);

        $response = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->first();

        $this->assertNotNull($response);
        $this->assertNull($response->response_payload);
        $this->assertNull($response->original_is_correct);
    }

    public function test_abandoned_attempt_freezes_existing_server_scored_evidence(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 1],
            850,
        );

        $finalized = $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
            'abandoned',
        );

        $this->assertSame(
            'abandoned',
            $finalized->status
        );

        $this->assertNotNull(
            $finalized->finalized_at
        );

        $response = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->first();

        $this->assertNotNull($response);

        $this->assertFalse(
            $response->original_is_correct
        );

        $this->assertSame(
            850,
            $response->time_spent_ms
        );
    }

    public function test_finalized_attempt_cannot_be_finalized_again(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            1000,
        );

        $firstFinalization = $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
        );

        $firstFinalizedAt = $firstFinalization->finalized_at;

        try {
            $this->finalizeService()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $attempt->id,
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $storedAttempt = $attempt->fresh();

        $this->assertSame('submitted', $storedAttempt->status);
        $this->assertTrue(
            $firstFinalizedAt->equalTo($storedAttempt->finalized_at)
        );
    }

    public function test_first_regrade_correction_uses_sequence_one(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 1],
            1000,
        );

        $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
        );

        $responseId = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->value('id');

        $correction = $this->regradeService()->execute(
            $responseId,
            true,
            'Manual review confirmed correct answer.',
        );

        $this->assertSame($responseId, $correction->attempt_response_id);
        $this->assertSame(1, $correction->correction_number);
        $this->assertTrue($correction->corrected_is_correct);
        $this->assertSame(
            'Manual review confirmed correct answer.',
            $correction->reason
        );
        $this->assertNotNull($correction->corrected_at);
    }

    public function test_second_regrade_correction_uses_next_sequence_number(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            1000,
        );

        $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
        );

        $responseId = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->value('id');

        $first = $this->regradeService()->execute(
            $responseId,
            false,
            'First correction.',
        );

        $second = $this->regradeService()->execute(
            $responseId,
            true,
            'Second correction.',
        );

        $this->assertSame(1, $first->correction_number);
        $this->assertSame(2, $second->correction_number);

        $this->assertSame(
            2,
            DB::table('regrade_corrections')
                ->where('attempt_response_id', $responseId)
                ->count()
        );
    }

    public function test_regrade_before_attempt_finalization_is_rejected(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attemptItemId,
            ['selected_option' => 2],
            1000,
        );

        $responseId = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->value('id');

        try {
            $this->regradeService()->execute(
                $responseId,
                false,
                'Should fail.',
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertSame(
            0,
            DB::table('regrade_corrections')
                ->where('attempt_response_id', $responseId)
                ->count()
        );
    }

    public function test_unanswered_finalized_response_cannot_be_regraded(): void
    {
        [$learnerId, $generationId] = $this->createExamFixture();

        $attempt = $this->service()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->finalizeService()->execute(
            $this->authenticatedUserId($learnerId),
            $learnerId,
            $attempt->id,
        );

        $responseId = DB::table('attempt_responses')
            ->where('attempt_item_id', $attemptItemId)
            ->value('id');

        try {
            $this->regradeService()->execute(
                $responseId,
                true,
                'Should fail.',
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertSame(
            0,
            DB::table('regrade_corrections')
                ->where('attempt_response_id', $responseId)
                ->count()
        );
    }

    public function test_inactive_enrollment_rejects_new_exam_attempt(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $versionId = DB::table('exam_generations')
            ->where('id', $generationId)
            ->value('curriculum_version_id');

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
            reason: 'Phase F exam authorization revocation.',
        );

        try {
            $this->service()->execute(
                $this->authenticatedUserId($learnerId),
                $learnerId,
                $generationId,
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
                ->where('exam_generation_id', $generationId)
                ->count()
        );
    }

    public function test_mismatched_authenticated_user_rejects_new_exam_attempt_without_mutation(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $otherStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        try {
            $this->service()->execute(
                $otherStudent->id,
                $learnerId,
                $generationId,
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
                'exam_generation_id' => $generationId,
            ],
        );
    }

    public function test_mismatched_authenticated_user_rejects_attempt_response_without_mutation(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $userId =
            $this->authenticatedUserId(
                $learnerId
            );

        $attempt = $this->service()->execute(
            $userId,
            $learnerId,
            $generationId,
        );

        $attemptItemId =
            DB::table('attempt_items')
                ->where(
                    'attempt_id',
                    $attempt->id,
                )
                ->value('id');

        $this->assertIsString(
            $attemptItemId
        );

        $otherStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        try {
            $this->responseService()->execute(
                $otherStudent->id,
                $learnerId,
                $attemptItemId,
                [
                    'selected_option' => 2,
                ],
                1900,
            );

            $this->fail(
                'Expected ModelNotFoundException was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas(
            'attempt_responses',
            [
                'attempt_item_id' => $attemptItemId,
                'response_payload' => null,
                'answer_change_count' => 0,
                'time_spent_ms' => 0,
                'original_is_correct' => null,
            ],
        );
    }

    public function test_mismatched_authenticated_user_rejects_attempt_finalization_without_mutation(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $userId =
            $this->authenticatedUserId(
                $learnerId
            );

        $attempt = $this->service()->execute(
            $userId,
            $learnerId,
            $generationId,
        );

        $attemptItemId =
            DB::table('attempt_items')
                ->where(
                    'attempt_id',
                    $attempt->id,
                )
                ->value('id');

        $this->assertIsString(
            $attemptItemId
        );

        $this->responseService()->execute(
            $userId,
            $learnerId,
            $attemptItemId,
            [
                'selected_option' => 2,
            ],
            1200,
        );

        $otherStudent = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        try {
            $this->finalizeService()->execute(
                $otherStudent->id,
                $learnerId,
                $attempt->id,
                'submitted',
            );

            $this->fail(
                'Expected ModelNotFoundException was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas(
            'attempts',
            [
                'id' => $attempt->id,
                'status' => 'in_progress',
                'finalized_at' => null,
            ],
        );

        $this->assertDatabaseHas(
            'attempt_responses',
            [
                'attempt_item_id' => $attemptItemId,
                'original_is_correct' => null,
            ],
        );
    }

    public function test_enrollment_deactivation_serializes_before_new_exam_attempt(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $versionId =
            DB::table('exam_generations')
                ->where('id', $generationId)
                ->value(
                    'curriculum_version_id'
                );

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
                reason: 'Exam deactivation-wins race.',
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'build_exam_attempt',
                    'authenticated_user_id' => $this->authenticatedUserId(
                        $learnerId
                    ),
                    'learner_profile_id' => $learnerId,
                    'exam_generation_id' => $generationId,
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
                        'exam_generation_id',
                        $generationId,
                    )
                    ->count(),
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

    public function test_new_exam_attempt_serializes_before_enrollment_deactivation(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $versionId =
            DB::table('exam_generations')
                ->where('id', $generationId)
                ->value(
                    'curriculum_version_id'
                );

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
                    $generationId,
                );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'deactivate_student_enrollment',
                    'actor_user_id' => $teacherId,
                    'enrollment_id' => $enrollmentId,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Exam attempt-wins race.',
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
                    'exam_generation_id' => $generationId,
                ],
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

    public function test_curriculum_retirement_serializes_against_exam_attempt_construction(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $versionId =
            DB::table('exam_generations')
                ->where('id', $generationId)
                ->value(
                    'curriculum_version_id'
                );

        $this->assertIsString($versionId);

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
                    'action' => 'build_exam_attempt',
                    'authenticated_user_id' => $this->authenticatedUserId(
                        $learnerId
                    ),
                    'learner_profile_id' => $learnerId,
                    'exam_generation_id' => $generationId,
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
                        'exam_generation_id',
                        $generationId,
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

    public function test_enrollment_deactivation_serializes_before_attempt_response_save(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $attempt =
            $this->service()->execute(
                $this->authenticatedUserId(
                    $learnerId
                ),
                $learnerId,
                $generationId,
            );

        $attemptItemId =
            DB::table('attempt_items')
                ->where(
                    'attempt_id',
                    $attempt->id,
                )
                ->value('id');

        $this->assertIsString(
            $attemptItemId
        );

        [
            $enrollmentId,
            $teacherId,
        ] = $this->activeEnrollmentForAttempt(
            $learnerId,
            $attempt->id,
        );

        $barrier = null;

        DB::beginTransaction();

        try {
            app(
                DeactivateStudentEnrollment::class
            )->execute(
                actorUserId: $teacherId,
                enrollmentId: $enrollmentId,
                operationId: (string) Str::uuid(),
                reason: 'F-C5B response deactivation-wins race.',
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'save_attempt_response',
                    'authenticated_user_id' => $this->authenticatedUserId(
                        $learnerId
                    ),
                    'learner_profile_id' => $learnerId,
                    'attempt_item_id' => $attemptItemId,
                    'response_payload' => [
                        'selected_option' => 2,
                    ],
                    'time_spent_ms' => 1800,
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

            $this->assertDatabaseHas(
                'attempt_responses',
                [
                    'attempt_item_id' => $attemptItemId,
                    'response_payload' => null,
                    'answer_change_count' => 0,
                    'time_spent_ms' => 0,
                    'original_is_correct' => null,
                ],
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

    public function test_attempt_response_save_serializes_before_enrollment_deactivation(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $attempt =
            $this->service()->execute(
                $this->authenticatedUserId(
                    $learnerId
                ),
                $learnerId,
                $generationId,
            );

        $attemptItemId =
            DB::table('attempt_items')
                ->where(
                    'attempt_id',
                    $attempt->id,
                )
                ->value('id');

        $this->assertIsString(
            $attemptItemId
        );

        [
            $enrollmentId,
            $teacherId,
        ] = $this->activeEnrollmentForAttempt(
            $learnerId,
            $attempt->id,
        );

        $barrier = null;

        DB::beginTransaction();

        try {
            $response =
                $this
                    ->responseService()
                    ->execute(
                        $this->authenticatedUserId(
                            $learnerId
                        ),
                        $learnerId,
                        $attemptItemId,
                        [
                            'selected_option' => 2,
                        ],
                        1800,
                    );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'deactivate_student_enrollment',
                    'actor_user_id' => $teacherId,
                    'enrollment_id' => $enrollmentId,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'F-C5B response-wins race.',
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
                'attempt_responses',
                [
                    'id' => $response->id,
                    'attempt_item_id' => $attemptItemId,
                    'response_payload' => json_encode([
                        'selected_option' => 2,
                    ]),
                    'answer_change_count' => 0,
                    'time_spent_ms' => 1800,
                    'original_is_correct' => null,
                ],
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

    public function test_enrollment_deactivation_serializes_before_attempt_finalization(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $attempt =
            $this->service()->execute(
                $this->authenticatedUserId(
                    $learnerId
                ),
                $learnerId,
                $generationId,
            );

        $attemptItemId =
            DB::table('attempt_items')
                ->where(
                    'attempt_id',
                    $attempt->id,
                )
                ->value('id');

        $this->assertIsString(
            $attemptItemId
        );

        $this->responseService()->execute(
            $this->authenticatedUserId(
                $learnerId
            ),
            $learnerId,
            $attemptItemId,
            [
                'selected_option' => 2,
            ],
            1200,
        );

        [
            $enrollmentId,
            $teacherId,
        ] = $this->activeEnrollmentForAttempt(
            $learnerId,
            $attempt->id,
        );

        $barrier = null;

        DB::beginTransaction();

        try {
            app(
                DeactivateStudentEnrollment::class
            )->execute(
                actorUserId: $teacherId,
                enrollmentId: $enrollmentId,
                operationId: (string) Str::uuid(),
                reason: 'F-C5B finalization deactivation-wins race.',
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'finalize_attempt',
                    'authenticated_user_id' => $this->authenticatedUserId(
                        $learnerId
                    ),
                    'learner_profile_id' => $learnerId,
                    'attempt_id' => $attempt->id,
                    'final_status' => 'submitted',
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

            $this->assertDatabaseHas(
                'attempts',
                [
                    'id' => $attempt->id,
                    'status' => 'in_progress',
                    'finalized_at' => null,
                ],
            );

            $this->assertDatabaseHas(
                'attempt_responses',
                [
                    'attempt_item_id' => $attemptItemId,
                    'original_is_correct' => null,
                ],
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

    public function test_attempt_finalization_serializes_before_enrollment_deactivation(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $attempt =
            $this->service()->execute(
                $this->authenticatedUserId(
                    $learnerId
                ),
                $learnerId,
                $generationId,
            );

        $attemptItemId =
            DB::table('attempt_items')
                ->where(
                    'attempt_id',
                    $attempt->id,
                )
                ->value('id');

        $this->assertIsString(
            $attemptItemId
        );

        $this->responseService()->execute(
            $this->authenticatedUserId(
                $learnerId
            ),
            $learnerId,
            $attemptItemId,
            [
                'selected_option' => 2,
            ],
            1200,
        );

        [
            $enrollmentId,
            $teacherId,
        ] = $this->activeEnrollmentForAttempt(
            $learnerId,
            $attempt->id,
        );

        $barrier = null;

        DB::beginTransaction();

        try {
            $finalized =
                $this
                    ->finalizeService()
                    ->execute(
                        $this->authenticatedUserId(
                            $learnerId
                        ),
                        $learnerId,
                        $attempt->id,
                        'submitted',
                    );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'deactivate_student_enrollment',
                    'actor_user_id' => $teacherId,
                    'enrollment_id' => $enrollmentId,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'F-C5B finalization-wins race.',
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
                    'id' => $finalized->id,
                    'status' => 'submitted',
                ],
            );

            $this->assertDatabaseHas(
                'attempt_responses',
                [
                    'attempt_item_id' => $attemptItemId,
                    'original_is_correct' => true,
                ],
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

    /**
     * @return array{string, string}
     */
    private function activeEnrollmentForAttempt(
        string $learnerId,
        string $attemptId,
    ): array {
        $versionId = DB::table('attempts')
            ->where('id', $attemptId)
            ->value(
                'curriculum_version_id'
            );

        $this->assertIsString(
            $versionId
        );

        $curriculumId = DB::table(
            'curriculum_versions'
        )
            ->where('id', $versionId)
            ->value('curriculum_id');

        $this->assertIsString(
            $curriculumId
        );

        $assignmentId = DB::table(
            'curricula'
        )
            ->where(
                'id',
                $curriculumId,
            )
            ->value(
                'teacher_subject_assignment_id'
            );

        $this->assertIsString(
            $assignmentId
        );

        $enrollmentId = DB::table(
            'student_enrollments'
        )
            ->where(
                'learner_profile_id',
                $learnerId,
            )
            ->where(
                'teacher_subject_assignment_id',
                $assignmentId,
            )
            ->where(
                'status',
                'active',
            )
            ->value('id');

        $this->assertIsString(
            $enrollmentId
        );

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $assignmentId,
            )
            ->value('teacher_id');

        $this->assertIsString(
            $teacherId
        );

        return [
            $enrollmentId,
            $teacherId,
        ];
    }

    private function regradeService(): AddRegradeCorrection
    {
        return new AddRegradeCorrection(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        );
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

    private function finalizeService(): FinalizeAttempt
    {
        return new FinalizeAttempt(
            new TransactionManager(
                new PostgresExceptionTranslator
            ),
            new LockActiveLearnerCurriculumGrant,
        );
    }

    private function responseService(): SaveAttemptResponse
    {
        return new SaveAttemptResponse(
            new TransactionManager(
                new PostgresExceptionTranslator
            ),
            new LockActiveLearnerCurriculumGrant,
        );
    }

    private function service(): BuildExamAttempt
    {
        return new BuildExamAttempt(
            new TransactionManager(
                new PostgresExceptionTranslator
            ),
            new LockActiveLearnerCurriculumGrant,
        );
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function createExamFixture(): array
    {
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
        $templateId = (string) Str::uuid();
        $templateVersionId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => "Attempt User {$userId}",
            'email' => "attempt-{$userId}@example.test",
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
            reason: 'Exam Attempt authorization fixture.',
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
            reason: 'Exam Attempt authorization fixture acceptance.',
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
            'name' => "Attempt Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Attempt Skill {$skillId}",
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
            'internal_label' => "Attempt Item {$itemId}",
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
                'stem' => '7 + 7 = ?',
                'options' => [12, 13, 14, 15],
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

        DB::table('exam_templates')->insert([
            'id' => $templateId,
            'curriculum_version_id' => $versionId,
            'name' => "Attempt Template {$templateId}",
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
            'rules_payload' => json_encode([
                'question_count' => 1,
            ], JSON_THROW_ON_ERROR),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exam_template_versions')
            ->where('id', $templateVersionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $generation = (new BuildExamGeneration(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        ))->execute(
            $templateVersionId,
            'generator-v1',
            'attempt-seed-'.Str::uuid(),
            [
                [
                    'assessment_item_revision_id' => $revisionId,
                    'assessment_item_id' => $itemId,
                ],
            ],
        );

        /*
         * Fixture-only state construction.
         *
         * Curriculum publishing readiness is covered by the
         * dedicated publishing tests. This suite exercises the
         * ExamAttempt aggregate against an already-published
         * source.
         */
        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        return [
            $learnerId,
            $generation->id,
            $revisionId,
            $itemId,
            $skillId,
        ];
    }
}

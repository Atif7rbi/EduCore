<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Attempt\AddRegradeCorrection;
use App\Application\Attempt\BuildExamAttempt;
use App\Application\Attempt\FinalizeAttempt;
use App\Application\Attempt\SaveAttemptResponse;
use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Exam\BuildExamGeneration;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildExamAttemptTest extends TestCase
{
    public function test_exam_attempt_copies_exact_generation_and_revision_truth(): void
    {
        [$learnerId, $generationId, $revisionId, $itemId, $skillId] =
            $this->createExamFixture();

        $attempt = $this->service()->execute(
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
                new PostgresExceptionTranslator()
            )
        ))->execute(
            $generation->curriculum_version_id
        );

        try {
            $this->service()->execute(
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
            $learnerId,
            $generationId,
        );

        try {
            $this->service()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $response = $this->responseService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 2],
            1000,
        );

        $response = $this->responseService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 1],
            1000,
        );

        $response = $this->responseService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        try {
            $this->responseService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 2],
            1800,
        );

        $finalized = $this->finalizeService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 1],
            900,
        );

        $this->finalizeService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $finalized = $this->finalizeService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 1],
            850,
        );

        $finalized = $this->finalizeService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 2],
            1000,
        );

        $firstFinalization = $this->finalizeService()->execute(
            $attempt->id,
        );

        $firstFinalizedAt = $firstFinalization->finalized_at;

        try {
            $this->finalizeService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 1],
            1000,
        );

        $this->finalizeService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
            $attemptItemId,
            ['selected_option' => 2],
            1000,
        );

        $this->finalizeService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->responseService()->execute(
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
            $learnerId,
            $generationId,
        );

        $attemptItemId = DB::table('attempt_items')
            ->where('attempt_id', $attempt->id)
            ->value('id');

        $this->finalizeService()->execute(
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

    public function test_curriculum_retirement_serializes_against_exam_attempt_construction(): void
    {
        [
            $learnerId,
            $generationId,
        ] = $this->createExamFixture();

        $generation = DB::table('exam_generations')
            ->where('id', $generationId)
            ->first();

        $this->assertNotNull($generation);

        $versionId =
            $generation->curriculum_version_id;

        $signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-exam-race-'
        );

        if ($signalFile === false) {
            $this->fail(
                'Unable to allocate concurrency signal file.'
            );
        }

        @unlink($signalFile);

        $process = null;
        $pipes = [];

        DB::beginTransaction();

        try {
            $lockedVersion = DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->lockForUpdate()
                ->first();

            $this->assertNotNull($lockedVersion);
            $this->assertSame(
                'published',
                $lockedVersion->status
            );

            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->update([
                    'status' => 'retired',
                    'updated_at' => now(),
                ]);

            $childCode = sprintf(
                <<<'PHP'
try {
    app(\App\Application\Attempt\BuildExamAttempt::class)
        ->execute(%s, %s);

    file_put_contents(
        %s,
        json_encode(
            ['result' => 'unexpected_success'],
            JSON_THROW_ON_ERROR
        )
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode(
            [
                'result' => 'exception',
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ],
            JSON_THROW_ON_ERROR
        )
    );
}
PHP,
                var_export($learnerId, true),
                var_export($generationId, true),
                var_export($signalFile, true),
                var_export($signalFile, true),
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(
                [
                    PHP_BINARY,
                    base_path('artisan'),
                    'tinker',
                    '--env=testing',
                    '--execute='.$childCode,
                ],
                $descriptors,
                $pipes,
                base_path(),
            );

            if (! is_resource($process)) {
                $this->fail(
                    'Unable to start independent PostgreSQL Session B.'
                );
            }

            fclose($pipes[0]);

            /*
             * Session B must remain blocked on the same
             * CurriculumVersion lifecycle row.
             */
            usleep(700000);

            $statusWhileLocked =
                proc_get_status($process);

            $this->assertTrue(
                $statusWhileLocked['running'],
                'Exam Attempt process exited before the lifecycle lock was released.'
            );

            $this->assertFileDoesNotExist(
                $signalFile,
                'Exam Attempt construction completed before the lifecycle lock was released.'
            );

            DB::commit();

            $deadline = microtime(true) + 8.0;

            do {
                $statusAfterCommit =
                    proc_get_status($process);

                if (! $statusAfterCommit['running']) {
                    break;
                }

                usleep(100000);
            } while (microtime(true) < $deadline);

            $this->assertFalse(
                $statusAfterCommit['running'],
                'Exam Attempt process did not finish after retirement committed.'
            );

            $stdout =
                stream_get_contents($pipes[1]);

            $stderr =
                stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertFileExists(
                $signalFile,
                "Session B produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
            );

            $result = json_decode(
                (string) file_get_contents($signalFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
                "Unexpected Session B result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null
            );

            $this->assertSame(
                0,
                DB::table('attempts')
                    ->where(
                        'exam_generation_id',
                        $generationId,
                    )
                    ->count()
            );

            $this->assertSame(
                'retired',
                DB::table('curriculum_versions')
                    ->where('id', $versionId)
                    ->value('status')
            );
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);

                if ($status['running']) {
                    proc_terminate($process);
                }

                proc_close($process);
            }

            @unlink($signalFile);
        }
    }

    private function regradeService(): AddRegradeCorrection
    {
        return new AddRegradeCorrection(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    private function finalizeService(): FinalizeAttempt
    {
        return new FinalizeAttempt(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    private function responseService(): SaveAttemptResponse
    {
        return new SaveAttemptResponse(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    private function service(): BuildExamAttempt
    {
        return new BuildExamAttempt(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
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

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Attempt Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Attempt Curriculum {$curriculumId}",
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
                new PostgresExceptionTranslator()
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
                new PostgresExceptionTranslator()
            )
        ))->execute(
            $templateVersionId,
            'generator-v1',
            'attempt-seed-' . Str::uuid(),
            [
                [
                    'assessment_item_revision_id' => $revisionId,
                    'assessment_item_id' => $itemId,
                ],
            ],
        );

        (new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($versionId);

        return [
            $learnerId,
            $generation->id,
            $revisionId,
            $itemId,
            $skillId,
        ];
    }
}

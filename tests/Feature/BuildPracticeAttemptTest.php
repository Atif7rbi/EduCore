<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Attempt\BuildPracticeAttempt;
use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildPracticeAttemptTest extends TestCase
{
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
        ] = $this->createPracticeFixture();

        DB::table('practice_activities')
            ->where('id', $activityId)
            ->update([
                'status' => 'archived',
                'updated_at' => now(),
            ]);

        try {
            $this->service()->execute(
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
                new PostgresExceptionTranslator()
            )
        ))->execute($versionId);

        try {
            $this->service()->execute(
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

        $signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-practice-race-'
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
    app(\App\Application\Attempt\BuildPracticeAttempt::class)
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
                var_export($activityId, true),
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
             * Session B is a completely separate PHP process.
             * It must block on CurriculumVersion FOR UPDATE
             * while Session A owns the lifecycle row.
             */
            usleep(700000);

            $statusWhileLocked =
                proc_get_status($process);

            $this->assertTrue(
                $statusWhileLocked['running'],
                'Attempt construction process exited before the lifecycle lock was released.'
            );

            $this->assertFileDoesNotExist(
                $signalFile,
                'Attempt construction completed before the lifecycle lock was released.'
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
                'Attempt construction process did not finish after retirement committed.'
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
                        'practice_activity_id',
                        $activityId,
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

    private function service(): BuildPracticeAttempt
    {
        return new BuildPracticeAttempt(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    /**
     * @return array{string, string, string, string, string, string}
     */
    private function createPracticeFixture(): array
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
                new PostgresExceptionTranslator()
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

        (new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($versionId);

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

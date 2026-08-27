<?php

namespace Tests\Feature\Concurrency;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ParentLockProtocolTest extends TestCase
{
    public function test_c1_curriculum_publish_serializes_with_structural_child_mutation(): void
    {
        [
            $versionId,
        ] = $this->createCurriculumVersion();

        $topicId = (string) Str::uuid();

        DB::beginTransaction();

        try {
            DB::table('topics')->insert([
                'id' => $topicId,
                'curriculum_version_id' => $versionId,
                'name' => "C1 Topic {$topicId}",
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $result = app(
        \App\Application\Curriculum\PublishCurriculumVersion::class
    )->execute(%s);

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'status' => $result->status,
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export($versionId, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C1 publish escaped CurriculumVersion structural parent lock.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertSame(
                'published',
                $result['status'] ?? null
            );

            $this->assertSame(
                'published',
                DB::table('curriculum_versions')
                    ->where('id', $versionId)
                    ->value('status')
            );

            $this->assertDatabaseHas('topics', [
                'id' => $topicId,
                'curriculum_version_id' => $versionId,
            ]);
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c2_lesson_release_serializes_with_classification_mutation(): void
    {
        $fixture = $this->createLessonRevisionFixture();

        $classificationId =
            (string) Str::uuid();

        DB::beginTransaction();

        try {
            DB::table('lesson_revision_skills')->insert([
                'id' => $classificationId,
                'lesson_revision_id' =>
                    $fixture['revision_id'],
                'skill_version_placement_id' =>
                    $fixture['placement_id'],
                'curriculum_version_id' =>
                    $fixture['version_id'],
                'created_at' => now(),
            ]);

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $result = app(
        \App\Application\Learning\ReleaseLessonRevision::class
    )->execute(%s);

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'released' => $result->released_at !== null,
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export(
                        $fixture['revision_id'],
                        true
                    ),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C2 release escaped LessonRevision classification parent lock.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertTrue(
                $result['released'] ?? false
            );

            $this->assertNotNull(
                DB::table('lesson_revisions')
                    ->where(
                        'id',
                        $fixture['revision_id']
                    )
                    ->value('released_at')
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c3_assessment_release_serializes_with_classification_mutation(): void
    {
        $fixture =
            $this->createAssessmentRevisionFixture();

        /*
         * Release requires at least one primary Skill.
         * Install that committed baseline first.
         */
        DB::table(
            'assessment_item_revision_skills'
        )->insert([
            'id' => (string) Str::uuid(),
            'assessment_item_revision_id' =>
                $fixture['revision_id'],
            'skill_version_placement_id' =>
                $fixture['primary_placement_id'],
            'curriculum_version_id' =>
                $fixture['version_id'],
            'role' => 'primary',
            'created_at' => now(),
        ]);

        $classificationId =
            (string) Str::uuid();

        DB::beginTransaction();

        try {
            DB::table(
                'assessment_item_revision_skills'
            )->insert([
                'id' => $classificationId,
                'assessment_item_revision_id' =>
                    $fixture['revision_id'],
                'skill_version_placement_id' =>
                    $fixture['supporting_placement_id'],
                'curriculum_version_id' =>
                    $fixture['version_id'],
                'role' => 'supporting',
                'created_at' => now(),
            ]);

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $result = app(
        \App\Application\Assessment\ReleaseAssessmentItemRevision::class
    )->execute(%s);

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'released' => $result->released_at !== null,
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export(
                        $fixture['revision_id'],
                        true
                    ),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C3 release escaped AssessmentItemRevision classification parent lock.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertTrue(
                $result['released'] ?? false
            );

            $this->assertNotNull(
                DB::table(
                    'assessment_item_revisions'
                )
                    ->where(
                        'id',
                        $fixture['revision_id']
                    )
                    ->value('released_at')
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c4_lesson_progress_creation_serializes_on_lesson_revision_parent(): void
    {
        $fixture = $this->createLessonRevisionFixture();

        $this->releaseLesson(
            $fixture['revision_id']
        );

        [
            $learnerId,
        ] = $this->createLearner();

        DB::beginTransaction();

        try {
            DB::table('lesson_revisions')
                ->where(
                    'id',
                    $fixture['revision_id']
                )
                ->lockForUpdate()
                ->firstOrFail();

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $result = app(
        \App\Application\Learning\RecordLessonProgress::class
    )->execute(%s, %s);

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'status' => $result->status,
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export($learnerId, true),
                    var_export(
                        $fixture['revision_id'],
                        true
                    ),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C4 LessonProgress creation escaped LessonRevision parent lock.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertSame(
                'in_progress',
                $result['status'] ?? null
            );

            $this->assertSame(
                1,
                DB::table('lesson_progresses')
                    ->where(
                        'learner_profile_id',
                        $learnerId
                    )
                    ->where(
                        'lesson_revision_id',
                        $fixture['revision_id']
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c5_practice_attempt_serializes_with_membership_mutation(): void
    {
        [
            $versionId,
        ] = $this->createCurriculumVersion();

        $first =
            $this->createAssessmentRevisionFixture(
                $versionId
            );

        $second =
            $this->createAssessmentRevisionFixture(
                $versionId
            );

        $this->installPrimaryAndReleaseAssessment(
            $first
        );

        $this->installPrimaryAndReleaseAssessment(
            $second
        );

        $activityId = (string) Str::uuid();

        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => "C5 Practice {$activityId}",
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('practice_activity_items')->insert([
            'id' => (string) Str::uuid(),
            'practice_activity_id' => $activityId,
            'assessment_item_revision_id' =>
                $first['revision_id'],
            'assessment_item_id' =>
                $first['item_id'],
            'curriculum_version_id' =>
                $versionId,
            'display_order' => 0,
            'created_at' => now(),
        ]);

        DB::table('practice_activities')
            ->where('id', $activityId)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);

        $this->publishCurriculum($versionId);

        [
            $learnerId,
        ] = $this->createLearner();

        DB::beginTransaction();

        try {
            /*
             * Direct membership write intentionally exercises
             * the DB parent-lock trigger. Practice membership is
             * prospective configuration and remains protected by
             * PracticeActivity serialization.
             */
            DB::table('practice_activity_items')->insert([
                'id' => (string) Str::uuid(),
                'practice_activity_id' => $activityId,
                'assessment_item_revision_id' =>
                    $second['revision_id'],
                'assessment_item_id' =>
                    $second['item_id'],
                'curriculum_version_id' =>
                    $versionId,
                'display_order' => 1,
                'created_at' => now(),
            ]);

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $result = app(
        \App\Application\Attempt\BuildPracticeAttempt::class
    )->execute(%s, %s);

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'attempt_id' => $result->id,
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export($learnerId, true),
                    var_export($activityId, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C5 Attempt construction escaped PracticeActivity membership parent lock.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $attemptId =
                $result['attempt_id'] ?? null;

            $this->assertNotNull($attemptId);

            $this->assertSame(
                2,
                DB::table('attempt_items')
                    ->where(
                        'attempt_id',
                        $attemptId
                    )
                    ->count()
            );

            $this->assertSame(
                2,
                DB::table('practice_activity_items')
                    ->where(
                        'practice_activity_id',
                        $activityId
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c6_generation_item_mutation_serializes_on_exam_generation_parent(): void
    {
        $fixture =
            $this->createExamGenerationFixture();

        $before = DB::table(
            'exam_generation_items'
        )
            ->where(
                'exam_generation_id',
                $fixture['generation_id']
            )
            ->count();

        DB::beginTransaction();

        try {
            /*
             * A committed Generation is necessarily sealed.
             *
             * Hold the same ExamGeneration parent row used by
             * sealing/completeness. The competing child mutation
             * must wait for this parent lock and, after release,
             * observe the sealed state and be rejected.
             */
            DB::table('exam_generations')
                ->where(
                    'id',
                    $fixture['generation_id']
                )
                ->lockForUpdate()
                ->firstOrFail();

            $newItemId =
                (string) Str::uuid();

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    \Illuminate\Support\Facades\DB::table(
        'exam_generation_items'
    )->insert([
        'id' => %s,
        'exam_generation_id' => %s,
        'assessment_item_revision_id' => %s,
        'assessment_item_id' => %s,
        'curriculum_version_id' => %s,
        'selection_position' => 99,
        'created_at' => now(),
    ]);

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export($newItemId, true),
                    var_export(
                        $fixture['generation_id'],
                        true
                    ),
                    var_export(
                        $fixture['revision_id'],
                        true
                    ),
                    var_export(
                        $fixture['item_id'],
                        true
                    ),
                    var_export(
                        $fixture['version_id'],
                        true
                    ),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C6 GenerationItem mutation escaped ExamGeneration parent lock.'
            );

            DB::commit();

            $result =
                $this->finishChild($process);

            $this->assertSame(
                'exception',
                $result['result'] ?? null
            );

            $this->assertSame(
                $before,
                DB::table(
                    'exam_generation_items'
                )
                    ->where(
                        'exam_generation_id',
                        $fixture['generation_id']
                    )
                    ->count()
            );

            $this->assertNotNull(
                DB::table('exam_generations')
                    ->where(
                        'id',
                        $fixture['generation_id']
                    )
                    ->value('generated_at')
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c7_attempt_child_mutation_serializes_with_finalization(): void
    {
        $fixture =
            $this->createExamAttemptFixture(
                answer: false,
                finalize: false,
            );

        $existingClassification =
            DB::table(
                'attempt_item_classification_skills'
            )
                ->where(
                    'attempt_item_id',
                    $fixture['attempt_item_id']
                )
                ->first();

        $this->assertNotNull(
            $existingClassification
        );

        $before = DB::table(
            'attempt_item_classification_skills'
        )
            ->where(
                'attempt_item_id',
                $fixture['attempt_item_id']
            )
            ->count();

        DB::beginTransaction();

        try {
            /*
             * FinalizeAttempt acquires the Attempt parent row.
             * Because this test wraps it in an outer transaction,
             * the parent lock remains held until the explicit
             * COMMIT below.
             */
            app(
                \App\Application\Attempt\FinalizeAttempt::class
            )->execute(
                $fixture['attempt_id']
            );

            $classificationId =
                (string) Str::uuid();

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    \Illuminate\Support\Facades\DB::table(
        'attempt_item_classification_skills'
    )->insert([
        'id' => %s,
        'attempt_item_id' => %s,
        'skill_id' => %s,
        'role' => %s,
        'created_at' => now(),
    ]);

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export(
                        $classificationId,
                        true
                    ),
                    var_export(
                        $fixture['attempt_item_id'],
                        true
                    ),
                    var_export(
                        $existingClassification->skill_id,
                        true
                    ),
                    var_export(
                        $existingClassification->role,
                        true
                    ),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C7 Attempt child mutation escaped Attempt finalization parent lock.'
            );

            DB::commit();

            $result =
                $this->finishChild($process);

            $this->assertSame(
                'exception',
                $result['result'] ?? null
            );

            $this->assertSame(
                'submitted',
                DB::table('attempts')
                    ->where(
                        'id',
                        $fixture['attempt_id']
                    )
                    ->value('status')
            );

            $this->assertSame(
                $before,
                DB::table(
                    'attempt_item_classification_skills'
                )
                    ->where(
                        'attempt_item_id',
                        $fixture['attempt_item_id']
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c8_concurrent_regrades_serialize_response_sequence(): void
    {
        $fixture =
            $this->createExamAttemptFixture(
                answer: true,
                finalize: true,
            );

        DB::beginTransaction();

        try {
            /*
             * AddRegradeCorrection locks AttemptResponse before
             * reading MAX(correction_number). The outer
             * transaction deliberately keeps correction #1 and
             * its Response lock uncommitted while Session B starts.
             */
            $first = app(
                \App\Application\Attempt\AddRegradeCorrection::class
            )->execute(
                $fixture['response_id'],
                false,
                'C8 first concurrent correction.',
            );

            $this->assertSame(
                1,
                $first->correction_number
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $result = app(
        \App\Application\Attempt\AddRegradeCorrection::class
    )->execute(
        %s,
        true,
        'C8 second concurrent correction.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'number' => $result->correction_number,
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export(
                        $fixture['response_id'],
                        true
                    ),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C8 second Regrade escaped AttemptResponse parent lock.'
            );

            DB::commit();

            $result =
                $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertSame(
                2,
                $result['number'] ?? null
            );

            $numbers = DB::table(
                'regrade_corrections'
            )
                ->where(
                    'attempt_response_id',
                    $fixture['response_id']
                )
                ->orderBy(
                    'correction_number'
                )
                ->pluck(
                    'correction_number'
                )
                ->map(
                    fn ($number): int =>
                        (int) $number
                )
                ->all();

            $this->assertSame(
                [1, 2],
                $numbers
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_c9_same_analytics_rebuild_key_serializes_with_advisory_lock(): void
    {
        [
            $learnerId,
        ] = $this->createLearner();

        $skillId =
            (string) Str::uuid();

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' =>
                "Concurrency Analytics Skill {$skillId}",
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * The definition remains deliberately opaque.
         * This test establishes only an EvidenceScope identity;
         * it does not define repetition/eligibility policy.
         */
        $scope = app(
            \App\Application\Analytics\CreateEvidenceScope::class
        )->execute(
            'C9 concurrency scope',
            null,
            [
                'purpose' =>
                    'parent-lock-concurrency-proof',
            ],
            1,
        );

        DB::beginTransaction();

        try {
            /*
             * Acquire exactly the same advisory key used by
             * RebuildMaterializedSkillPerformance and by the
             * materialized cache trigger.
             */
            DB::select(
                <<<'SQL'
SELECT pg_advisory_xact_lock(
    hashtext(?),
    hashtext(?)
)
SQL,
                [
                    $learnerId,
                    $skillId
                        .':'
                        .$scope->id,
                ],
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $result = app(
        \App\Application\Analytics\RebuildMaterializedSkillPerformance::class
    )->execute(
        %s,
        %s,
        %s,
        []
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'is_null' => $result === null,
        ], JSON_THROW_ON_ERROR)
    );
} catch (\Throwable $exception) {
    file_put_contents(
        %s,
        json_encode([
            'result' => 'exception',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR)
    );
}
PHP_CODE,
                    var_export(
                        $learnerId,
                        true
                    ),
                    var_export(
                        $skillId,
                        true
                    ),
                    var_export(
                        $scope->id,
                        true
                    ),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'C9 rebuild escaped learner×skill×scope advisory lock.'
            );

            DB::commit();

            $result =
                $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertTrue(
                $result['is_null'] ?? false
            );

            $this->assertSame(
                0,
                DB::table(
                    'materialized_skill_performances'
                )
                    ->where(
                        'learner_profile_id',
                        $learnerId
                    )
                    ->where(
                        'skill_id',
                        $skillId
                    )
                    ->where(
                        'evidence_scope_id',
                        $scope->id
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    private ?string $signalFile = null;

    /** @var resource|null */
    private $childProcess = null;

    /** @var array<int, resource> */
    private array $childPipes = [];

    /**
     * @return resource
     */
    private function startChild(
        string $childCode,
    ) {
        $this->signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-lock-'
        );

        if ($this->signalFile === false) {
            throw new RuntimeException(
                'Unable to allocate concurrency signal file.'
            );
        }

        @unlink($this->signalFile);

        /*
         * childCode was composed before signal allocation in
         * the caller, so substitute the placeholder path used
         * by those generated snippets.
         */
        $childCode = str_replace(
            "NULL_SIGNAL_FILE",
            var_export(
                $this->signalFile,
                true
            ),
            $childCode
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
            throw new RuntimeException(
                'Unable to start independent PHP process.'
            );
        }

        fclose($pipes[0]);

        $this->childProcess = $process;
        $this->childPipes = $pipes;

        return $process;
    }

    /**
     * @param resource $process
     */
    private function assertBlocked(
        $process,
        string $message,
    ): void {
        usleep(700000);

        $status =
            proc_get_status($process);

        $this->assertTrue(
            $status['running'],
            $message
        );

        $this->assertFileDoesNotExist(
            (string) $this->signalFile,
            $message
        );
    }

    /**
     * @param resource $process
     * @return array<string, mixed>
     */
    private function finishChild(
        $process,
    ): array {
        $deadline = microtime(true) + 8.0;

        do {
            $status =
                proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        $this->assertFalse(
            $status['running'],
            'Child process did not finish after parent lock release.'
        );

        $stdout =
            stream_get_contents(
                $this->childPipes[1]
            );

        $stderr =
            stream_get_contents(
                $this->childPipes[2]
            );

        fclose($this->childPipes[1]);
        fclose($this->childPipes[2]);

        $this->childPipes = [];

        $this->assertFileExists(
            (string) $this->signalFile,
            "Child produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
        );

        $result = json_decode(
            (string) file_get_contents(
                (string) $this->signalFile
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        proc_close($process);

        $this->childProcess = null;

        return $result;
    }

    private function cleanupChild(): void
    {
        foreach (
            $this->childPipes
            as $pipe
        ) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->childPipes = [];

        if (
            is_resource(
                $this->childProcess
            )
        ) {
            $status =
                proc_get_status(
                    $this->childProcess
                );

            if ($status['running']) {
                proc_terminate(
                    $this->childProcess
                );
            }

            proc_close(
                $this->childProcess
            );
        }

        $this->childProcess = null;

        if ($this->signalFile !== null) {
            @unlink($this->signalFile);
        }

        $this->signalFile = null;
    }

    private function rollbackIfNeeded(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
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
            'name' => "Concurrency Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Concurrency Curriculum {$curriculumId}",
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

        return [
            $versionId,
            $curriculumId,
            $subjectId,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function createLessonRevisionFixture(): array
    {
        [
            $versionId,
        ] = $this->createCurriculumVersion();

        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Concurrency Lesson Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => "Concurrency Lesson {$lessonId}",
            'description' => null,
            'status' => 'draft',
            'display_order' => 0,
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lesson_revisions')->insert([
            'id' => $revisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' => json_encode([
                'type' => 'lesson',
                'blocks' => [],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => "Concurrency Lesson Skill {$skillId}",
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

        return [
            'version_id' => $versionId,
            'lesson_id' => $lessonId,
            'revision_id' => $revisionId,
            'placement_id' => $placementId,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function createAssessmentRevisionFixture(
        ?string $versionId = null,
    ): array {
        if ($versionId === null) {
            [
                $versionId,
            ] = $this->createCurriculumVersion();
        }

        $topicId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Concurrency Assessment Topic {$topicId}",
            'display_order' => random_int(0, 1000000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $placements = [];

        foreach (
            ['primary', 'supporting']
            as $kind
        ) {
            $skillId = (string) Str::uuid();
            $placementId =
                (string) Str::uuid();

            DB::table('skills')->insert([
                'id' => $skillId,
                'name' =>
                    "Concurrency {$kind} Skill {$skillId}",
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table(
                'skill_version_placements'
            )->insert([
                'id' => $placementId,
                'skill_id' => $skillId,
                'curriculum_version_id' =>
                    $versionId,
                'created_at' => now(),
            ]);

            $placements[$kind] =
                $placementId;
        }

        DB::table('assessment_items')->insert([
            'id' => $itemId,
            'curriculum_version_id' => $versionId,
            'item_type' => 'multiple_choice',
            'internal_label' =>
                "Concurrency Item {$itemId}",
            'status' => 'draft',
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'assessment_item_revisions'
        )->insert([
            'id' => $revisionId,
            'assessment_item_id' => $itemId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'difficulty' => 'easy',
            'content_payload' => json_encode([
                'stem' => 'Concurrency item?',
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

        return [
            'version_id' => $versionId,
            'item_id' => $itemId,
            'revision_id' => $revisionId,
            'primary_placement_id' =>
                $placements['primary'],
            'supporting_placement_id' =>
                $placements['supporting'],
        ];
    }

    private function installPrimaryAndReleaseAssessment(
        array $fixture,
    ): void {
        DB::table(
            'assessment_item_revision_skills'
        )->insert([
            'id' => (string) Str::uuid(),
            'assessment_item_revision_id' =>
                $fixture['revision_id'],
            'skill_version_placement_id' =>
                $fixture['primary_placement_id'],
            'curriculum_version_id' =>
                $fixture['version_id'],
            'role' => 'primary',
            'created_at' => now(),
        ]);

        $this->releaseAssessment(
            $fixture['revision_id']
        );
    }

    /**
     * @return array<string, string>
     */
    private function createExamGenerationFixture(): array
    {
        [
            $versionId,
        ] = $this->createCurriculumVersion();

        $assessment =
            $this->createAssessmentRevisionFixture(
                $versionId
            );

        $this->installPrimaryAndReleaseAssessment(
            $assessment
        );

        $templateId =
            (string) Str::uuid();

        $templateVersionId =
            (string) Str::uuid();

        DB::table('exam_templates')->insert([
            'id' => $templateId,
            'curriculum_version_id' =>
                $versionId,
            'name' =>
                "Concurrency Template {$templateId}",
            'description' => null,
            'status' => 'active',
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'exam_template_versions'
        )->insert([
            'id' => $templateVersionId,
            'exam_template_id' =>
                $templateId,
            'curriculum_version_id' =>
                $versionId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'rules_payload' =>
                json_encode(
                    [
                        'question_count' => 1,
                    ],
                    JSON_THROW_ON_ERROR
                ),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(
            'exam_template_versions'
        )
            ->where(
                'id',
                $templateVersionId
            )
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $generation = app(
            \App\Application\Exam\BuildExamGeneration::class
        )->execute(
            $templateVersionId,
            'concurrency-generator-v1',
            'concurrency-seed-'
                .Str::uuid(),
            [
                [
                    'assessment_item_revision_id' =>
                        $assessment[
                            'revision_id'
                        ],
                    'assessment_item_id' =>
                        $assessment[
                            'item_id'
                        ],
                ],
            ],
        );

        return [
            'version_id' =>
                $versionId,
            'template_version_id' =>
                $templateVersionId,
            'generation_id' =>
                $generation->id,
            'revision_id' =>
                $assessment['revision_id'],
            'item_id' =>
                $assessment['item_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function createExamAttemptFixture(
        bool $answer,
        bool $finalize,
    ): array {
        $generation =
            $this->createExamGenerationFixture();

        $this->publishCurriculum(
            $generation['version_id']
        );

        [
            $learnerId,
        ] = $this->createLearner();

        $attempt = app(
            \App\Application\Attempt\BuildExamAttempt::class
        )->execute(
            $learnerId,
            $generation['generation_id'],
        );

        $attemptItemId =
            DB::table('attempt_items')
                ->where(
                    'attempt_id',
                    $attempt->id
                )
                ->value('id');

        if ($attemptItemId === null) {
            throw new RuntimeException(
                'Concurrency Attempt fixture has no AttemptItem.'
            );
        }

        if ($answer) {
            app(
                \App\Application\Attempt\SaveAttemptResponse::class
            )->execute(
                $attemptItemId,
                [
                    'selected_option' => 1,
                ],
                1000,
            );
        }

        if ($finalize) {
            app(
                \App\Application\Attempt\FinalizeAttempt::class
            )->execute(
                $attempt->id
            );
        }

        $responseId =
            DB::table('attempt_responses')
                ->where(
                    'attempt_item_id',
                    $attemptItemId
                )
                ->value('id');

        if ($responseId === null) {
            throw new RuntimeException(
                'Concurrency Attempt fixture has no AttemptResponse.'
            );
        }

        return [
            'learner_id' =>
                $learnerId,
            'attempt_id' =>
                $attempt->id,
            'attempt_item_id' =>
                $attemptItemId,
            'response_id' =>
                $responseId,
            'generation_id' =>
                $generation['generation_id'],
            'version_id' =>
                $generation['version_id'],
        ];
    }

    /**
     * @return array{string, string}
     */
    private function createLearner(): array
    {
        $userId = (string) Str::uuid();
        $learnerId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => "Concurrency Learner {$userId}",
            'email' =>
                "concurrency-{$userId}@example.test",
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

        return [
            $learnerId,
            $userId,
        ];
    }

    private function publishCurriculum(
        string $versionId,
    ): void {
        (new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($versionId);
    }

    private function releaseLesson(
        string $revisionId,
    ): void {
        (new ReleaseLessonRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($revisionId);
    }

    private function releaseAssessment(
        string $revisionId,
    ): void {
        (new ReleaseAssessmentItemRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        ))->execute($revisionId);
    }
}

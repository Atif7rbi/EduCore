<?php

namespace Tests\Feature;

use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Learning\RecordLessonProgress;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Learning\UnpublishLesson;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class LessonProgressApiTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_authenticated_learner_can_read_current_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();
        [$lessonId, $revisionId] = $this->createPublishedLesson();

        DB::table('lesson_progresses')->insert([
            'id' => (string) Str::uuid(),
            'learner_profile_id' => $learner->id,
            'lesson_revision_id' => $revisionId,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'in_progress'
            );
    }

    public function test_lesson_progress_read_returns_null_when_current_revision_has_no_progress(): void
    {
        [$user] = $this->createLearner();
        [$lessonId] = $this->createPublishedLesson();

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_lesson_progress_read_is_scoped_to_authenticated_learner(): void
    {
        [$user] = $this->createLearner();
        [, $otherLearner] = $this->createLearner();
        [$lessonId, $revisionId] = $this->createPublishedLesson();

        DB::table('lesson_progresses')->insert([
            'id' => (string) Str::uuid(),
            'learner_profile_id' => $otherLearner->id,
            'lesson_revision_id' => $revisionId,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_lesson_progress_read_ignores_progress_for_historical_revision(): void
    {
        [$user, $learner] = $this->createLearner();

        [
            $lessonId,
            $historicalRevisionId,
        ] = $this->createPublishedLessonWithHistoricalRevision();

        DB::table('lesson_progresses')->insert([
            'id' => (string) Str::uuid(),
            'learner_profile_id' => $learner->id,
            'lesson_revision_id' => $historicalRevisionId,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_authenticated_learner_can_start_published_lesson(): void
    {
        [$user, $learner] = $this->createLearner();
        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'in_progress'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'in_progress',
            ]
        );
    }

    public function test_start_does_not_accept_learner_identity_from_body(): void
    {
        [$user, $learner] = $this->createLearner();
        [, $otherLearner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress",
            [
                'learner_profile_id' => $otherLearner->id,
            ]
        )->assertOk();

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
            ]
        );

        $this->assertDatabaseMissing(
            'lesson_progresses',
            [
                'learner_profile_id' => $otherLearner->id,
                'lesson_revision_id' => $revisionId,
            ]
        );
    }

    public function test_authenticated_learner_can_complete_published_lesson(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertOk();

        $response = $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'completed'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'completed',
            ]
        );

        $this->assertNotNull(
            $response->json('data.completed_at')
        );
    }

    public function test_complete_without_prior_start_creates_completed_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'completed'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'completed',
            ]
        );
    }

    public function test_inactive_enrollment_rejects_starting_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $enrollment = DB::table('student_enrollments')
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->first();

        $this->assertNotNull($enrollment);

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $enrollment->teacher_subject_assignment_id,
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Phase F Lesson Progress revocation test.',
        );

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );

        $this->assertDatabaseMissing(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
            ],
        );
    }

    public function test_inactive_enrollment_rejects_completing_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertOk();

        $enrollment = DB::table('student_enrollments')
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->first();

        $this->assertNotNull($enrollment);

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $enrollment->teacher_subject_assignment_id,
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Phase F Lesson completion revocation test.',
        );

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'status' => 'in_progress',
            ],
        );

        $this->assertDatabaseMissing(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'status' => 'completed',
            ],
        );
    }

    public function test_enrollment_deactivation_serializes_before_new_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $enrollment = DB::table('student_enrollments')
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->first();

        $this->assertNotNull($enrollment);

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $enrollment->teacher_subject_assignment_id,
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        $signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-progress-enrollment-race-',
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
            app(
                DeactivateStudentEnrollment::class
            )->execute(
                actorUserId: $teacherId,
                enrollmentId: $enrollment->id,
                operationId: (string) Str::uuid(),
                reason: 'Lesson Progress deactivation-wins race.',
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where('id', $enrollment->id)
                    ->value('status'),
            );

            $childCode = sprintf(
                <<<'PHP'
try {
    app(\App\Application\Learning\RecordLessonProgress::class)
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
                var_export($learner->id, true),
                var_export($lessonId, true),
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

            usleep(700000);

            $statusWhileLocked =
                proc_get_status($process);

            $this->assertTrue(
                $statusWhileLocked['running'],
                'Lesson Progress did not wait for enrollment deactivation locks.',
            );

            $this->assertFileDoesNotExist(
                $signalFile,
                'Lesson Progress completed before deactivation committed.',
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
                'Lesson Progress did not finish after deactivation commit.',
            );

            $stdout =
                stream_get_contents($pipes[1]);

            $stderr =
                stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertFileExists(
                $signalFile,
                "Session B produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
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
                "Unexpected Session B result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                0,
                DB::table('lesson_progresses')
                    ->where(
                        'learner_profile_id',
                        $learner->id,
                    )
                    ->count(),
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where('id', $enrollment->id)
                    ->value('status'),
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

    public function test_new_lesson_progress_serializes_before_enrollment_deactivation(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $enrollment = DB::table('student_enrollments')
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->first();

        $this->assertNotNull($enrollment);

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $enrollment->teacher_subject_assignment_id,
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        $signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-progress-wins-enrollment-',
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
            $progress = app(
                RecordLessonProgress::class
            )->execute(
                $learner->id,
                $lessonId,
            );

            $operationId = (string) Str::uuid();

            $childCode = sprintf(
                <<<'PHP'
try {
    $enrollment = app(
        \App\Application\Enrollment\DeactivateStudentEnrollment::class
    )->execute(
        actorUserId: %s,
        enrollmentId: %s,
        operationId: %s,
        reason: 'Lesson Progress wins enrollment race.',
    );

    file_put_contents(
        %s,
        json_encode(
            [
                'result' => 'success',
                'status' => $enrollment->status,
            ],
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
                var_export($teacherId, true),
                var_export($enrollment->id, true),
                var_export($operationId, true),
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

            usleep(700000);

            $statusWhileLocked =
                proc_get_status($process);

            $this->assertTrue(
                $statusWhileLocked['running'],
                'Enrollment deactivation did not wait for Lesson Progress authorization locks.',
            );

            $this->assertFileDoesNotExist(
                $signalFile,
                'Enrollment deactivation completed before Lesson Progress committed.',
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
                'Enrollment deactivation did not finish after Lesson Progress commit.',
            );

            $stdout =
                stream_get_contents($pipes[1]);

            $stderr =
                stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertFileExists(
                $signalFile,
                "Session B produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );

            $result = json_decode(
                (string) file_get_contents($signalFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(
                'success',
                $result['result'] ?? null,
                "Unexpected Session B result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );

            $this->assertSame(
                'inactive',
                $result['status'] ?? null,
            );

            $this->assertDatabaseHas(
                'lesson_progresses',
                [
                    'id' => $progress->id,
                    'learner_profile_id' => $learner->id,
                    'lesson_revision_id' => $revisionId,
                    'status' => 'in_progress',
                ],
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where('id', $enrollment->id)
                    ->value('status'),
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

    public function test_lesson_unpublish_serializes_before_new_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-progress-unpublish-race-',
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
            app(
                UnpublishLesson::class
            )->execute($lessonId);

            $this->assertSame(
                'unpublished',
                DB::table('lessons')
                    ->where('id', $lessonId)
                    ->value('status'),
            );

            $childCode = sprintf(
                <<<'PHP'
try {
    app(\App\Application\Learning\RecordLessonProgress::class)
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
                var_export($learner->id, true),
                var_export($lessonId, true),
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

            usleep(700000);

            $statusWhileLocked =
                proc_get_status($process);

            $this->assertTrue(
                $statusWhileLocked['running'],
                'Lesson Progress did not wait for Lesson lifecycle lock.',
            );

            $this->assertFileDoesNotExist(
                $signalFile,
                'Lesson Progress completed before unpublish committed.',
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
                'Lesson Progress did not finish after unpublish commit.',
            );

            $stdout =
                stream_get_contents($pipes[1]);

            $stderr =
                stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertFileExists(
                $signalFile,
                "Session B produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
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
                "Unexpected Session B result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                0,
                DB::table('lesson_progresses')
                    ->where(
                        'learner_profile_id',
                        $learner->id,
                    )
                    ->count(),
            );

            $this->assertSame(
                'unpublished',
                DB::table('lessons')
                    ->where('id', $lessonId)
                    ->value('status'),
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

    public function test_new_lesson_progress_serializes_before_lesson_unpublish(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-progress-wins-unpublish-',
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
            $progress = app(
                RecordLessonProgress::class
            )->execute(
                $learner->id,
                $lessonId,
            );

            $childCode = sprintf(
                <<<'PHP'
try {
    $lesson = app(
        \App\Application\Learning\UnpublishLesson::class
    )->execute(%s);

    file_put_contents(
        %s,
        json_encode(
            [
                'result' => 'success',
                'status' => $lesson->status,
            ],
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
                var_export($lessonId, true),
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

            usleep(700000);

            $statusWhileLocked =
                proc_get_status($process);

            $this->assertTrue(
                $statusWhileLocked['running'],
                'Lesson unpublish did not wait for Lesson Progress source locks.',
            );

            $this->assertFileDoesNotExist(
                $signalFile,
                'Lesson unpublish completed before Lesson Progress committed.',
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
                'Lesson unpublish did not finish after Lesson Progress commit.',
            );

            $stdout =
                stream_get_contents($pipes[1]);

            $stderr =
                stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertFileExists(
                $signalFile,
                "Session B produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );

            $result = json_decode(
                (string) file_get_contents($signalFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(
                'success',
                $result['result'] ?? null,
                "Unexpected Session B result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}",
            );

            $this->assertSame(
                'unpublished',
                $result['status'] ?? null,
            );

            $this->assertDatabaseHas(
                'lesson_progresses',
                [
                    'id' => $progress->id,
                    'learner_profile_id' => $learner->id,
                    'lesson_revision_id' => $revisionId,
                    'status' => 'in_progress',
                ],
            );

            $this->assertSame(
                'unpublished',
                DB::table('lessons')
                    ->where('id', $lessonId)
                    ->value('status'),
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

    public function test_progress_routes_require_authentication(): void
    {
        $lessonId = (string) Str::uuid();

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertStatus(401);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertStatus(401);

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )->assertStatus(401);
    }

    public function test_progress_routes_require_learner_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $lessonId = (string) Str::uuid();

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    public function test_draft_lesson_is_not_visible_to_progress_endpoint(): void
    {
        [$user] = $this->createLearner();

        $lessonId = $this->createDraftLesson();

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    /**
     * @return array{User, LearnerProfile}
     */
    private function createLearner(): array
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        return [$user, $learner];
    }

    /**
     * @return array{string, string}
     */
    private function createPublishedLesson(
        ?LearnerProfile $learner = null,
    ): array {
        [
            $lessonId,
            $revisionId,
            $versionId,
        ] = $this->createLessonFixture();

        (new ReleaseLessonRevision(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        ))->execute($revisionId);

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'published',
                'published_revision_id' => $revisionId,
                'updated_at' => now(),
            ]);

        $this->markCurriculumPublishedForFixture(
            $versionId
        );

        if ($learner !== null) {
            $this->activateEnrollmentForVersion(
                $learner,
                $versionId,
            );
        }

        return [
            $lessonId,
            $revisionId,
        ];
    }

    /**
     * @return array{string, string, string}
     */
    private function createPublishedLessonWithHistoricalRevision(): array
    {
        [
            $lessonId,
            $historicalRevisionId,
            $versionId,
        ] = $this->createLessonFixture();

        $historicalRevision = DB::table(
            'lesson_revisions'
        )
            ->where(
                'id',
                $historicalRevisionId
            )
            ->first();

        $currentRevisionId =
            (string) Str::uuid();

        DB::table('lesson_revisions')->insert([
            'id' => $currentRevisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 2,
            'primary_topic_id' => $historicalRevision->primary_topic_id,
            'content_payload' => json_encode([
                'blocks' => [
                    [
                        'type' => 'text',
                        'value' => 'Current progress API content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        $transactions =
            new TransactionManager(
                new PostgresExceptionTranslator
            );

        (new ReleaseLessonRevision(
            $transactions
        ))->execute(
            $historicalRevisionId
        );

        (new ReleaseLessonRevision(
            $transactions
        ))->execute(
            $currentRevisionId
        );

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'published',
                'published_revision_id' => $currentRevisionId,
                'updated_at' => now(),
            ]);

        $this->markCurriculumPublishedForFixture(
            $versionId
        );

        return [
            $lessonId,
            $historicalRevisionId,
            $currentRevisionId,
        ];
    }

    private function activateEnrollmentForVersion(
        LearnerProfile $learner,
        string $versionId,
    ): void {
        $curriculumId = DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->value('curriculum_id');

        $assignmentId = DB::table('curricula')
            ->where('id', $curriculumId)
            ->value('teacher_subject_assignment_id');

        $this->assertIsString($assignmentId);

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $learner->user_id,
            learnerProfileId: $learner->id,
            assignmentId: $assignmentId,
            operationId: (string) Str::uuid(),
            reason: 'Lesson Progress authorization fixture.',
        );

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where('id', $assignmentId)
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Lesson Progress authorization fixture acceptance.',
        );
    }

    private function markCurriculumPublishedForFixture(
        string $versionId,
    ): void {
        /*
         * Progress tests need a visible published Lesson, not a
         * second copy of the curriculum publishing specification.
         */
        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);
    }

    private function createDraftLesson(): string
    {
        [
            $lessonId,
        ] = $this->createLessonFixture();

        return $lessonId;
    }

    /**
     * @return array{string, string, string}
     */
    private function createLessonFixture(): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        $curriculum =
            $this->createOwnedCurriculumFixture(
                'Owned Curriculum '.Str::uuid()
            );

        $curriculumId = $curriculum->id;
        $subjectId = $curriculum->subject_id;

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
            'name' => "Progress API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => 'Progress API Lesson',
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
                'blocks' => [
                    [
                        'type' => 'text',
                        'value' => 'Progress API content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        return [
            $lessonId,
            $revisionId,
            $versionId,
        ];
    }
}

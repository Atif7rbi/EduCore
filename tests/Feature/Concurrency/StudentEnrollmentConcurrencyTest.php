<?php

namespace Tests\Feature\Concurrency;

use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeclineStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exceptions\StudentEnrollmentOperationConflict;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\LearnerProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class StudentEnrollmentConcurrencyTest extends TestCase
{
    private ?string $signalFile = null;

    /** @var resource|null */
    private $childProcess = null;

    /** @var array<int, resource> */
    private array $childPipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::selectOne(
            'SELECT current_database() AS database_name'
        );

        if (
            ! is_object($database)
            || ($database->database_name ?? null)
                !== 'sewaellf_educore_test'
        ) {
            throw new RuntimeException(
                'StudentEnrollment concurrency tests may run only on sewaellf_educore_test.'
            );
        }
    }

    public function test_concurrent_initial_request_reuses_single_durable_pair(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();

        $firstOperation = (string) Str::uuid();
        $secondOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            $parentEnrollment = app(
                RequestStudentEnrollment::class
            )->execute(
                $student,
                $learner,
                $assignment,
                $firstOperation,
                'Concurrent initial request.',
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $enrollment = app(
        \App\Application\Enrollment\RequestStudentEnrollment::class
    )->execute(
        %s,
        %s,
        %s,
        %s,
        'Concurrent second request.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'enrollment_id' => $enrollment->id,
            'status' => $enrollment->status,
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
                    var_export($student, true),
                    var_export($learner, true),
                    var_export($assignment, true),
                    var_export($secondOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Concurrent Request escaped enrollment parent serialization.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertSame(
                $parentEnrollment->id,
                $result['enrollment_id'] ?? null
            );

            $this->assertSame(
                'pending',
                $result['status'] ?? null
            );

            $this->assertSame(
                1,
                DB::table('student_enrollments')
                    ->where('learner_profile_id', $learner)
                    ->where(
                        'teacher_subject_assignment_id',
                        $assignment
                    )
                    ->count()
            );

            $this->assertSame(
                1,
                DB::table('student_enrollment_transitions')
                    ->where(
                        'enrollment_id',
                        $parentEnrollment->id
                    )
                    ->count()
            );

            $this->assertSame(
                0,
                DB::table('student_enrollment_transitions')
                    ->where('operation_id', $secondOperation)
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_same_operation_id_serializes_across_different_enrollment_facts(): void
    {
        [$firstStudent, $firstLearner] =
            $this->studentIdentity();

        [$secondStudent, $secondLearner] =
            $this->studentIdentity();

        [, , $firstAssignment] = $this->teacherAssignment();
        [, , $secondAssignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        DB::beginTransaction();

        try {
            $firstEnrollment = app(
                RequestStudentEnrollment::class
            )->execute(
                $firstStudent,
                $firstLearner,
                $firstAssignment,
                $operationId,
                'Globally serialized enrollment operation.',
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $enrollment = app(
        \App\Application\Enrollment\RequestStudentEnrollment::class
    )->execute(
        %s,
        %s,
        %s,
        %s,
        'Globally serialized enrollment operation.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
            'enrollment_id' => $enrollment->id,
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
                    var_export($secondStudent, true),
                    var_export($secondLearner, true),
                    var_export($secondAssignment, true),
                    var_export($operationId, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Duplicate enrollment operation_id escaped advisory serialization.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'exception',
                $result['result'] ?? null
            );

            $this->assertSame(
                StudentEnrollmentOperationConflict::class,
                $result['class'] ?? null
            );

            $this->assertSame(
                1,
                DB::table('student_enrollment_transitions')
                    ->where('operation_id', $operationId)
                    ->count()
            );

            $this->assertSame(
                $firstEnrollment->id,
                DB::table('student_enrollment_transitions')
                    ->where('operation_id', $operationId)
                    ->value('enrollment_id')
            );

            $this->assertSame(
                0,
                DB::table('student_enrollments')
                    ->where(
                        'learner_profile_id',
                        $secondLearner
                    )
                    ->where(
                        'teacher_subject_assignment_id',
                        $secondAssignment
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_accept_then_concurrent_decline_serializes_to_active(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            $student,
            $learner,
            $assignment,
            (string) Str::uuid(),
            'Pending before race.',
        );

        $acceptOperation = (string) Str::uuid();
        $declineOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            app(AcceptStudentEnrollment::class)
                ->execute(
                    $teacher,
                    $enrollment->id,
                    $acceptOperation,
                    'Accept wins race.',
                );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $enrollment = app(
        \App\Application\Enrollment\DeclineStudentEnrollment::class
    )->execute(
        %s,
        %s,
        %s,
        'Concurrent decline.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
            'status' => $enrollment->status,
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
                    var_export($teacher, true),
                    var_export($enrollment->id, true),
                    var_export($declineOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Concurrent Decline escaped enrollment lifecycle serialization.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'exception',
                $result['result'] ?? null
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null
            );

            $this->assertSame(
                'active',
                DB::table('student_enrollments')
                    ->where('id', $enrollment->id)
                    ->value('status')
            );

            $this->assertSame(
                1,
                DB::table('student_enrollment_transitions')
                    ->where('operation_id', $acceptOperation)
                    ->count()
            );

            $this->assertSame(
                0,
                DB::table('student_enrollment_transitions')
                    ->where('operation_id', $declineOperation)
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_rejoin_then_concurrent_accept_serializes_history_chain(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            $student,
            $learner,
            $assignment,
            (string) Str::uuid(),
            'Initial request.',
        );

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher,
                $enrollment->id,
                (string) Str::uuid(),
                'Initial decline.',
            );

        $rejoinOperation = (string) Str::uuid();
        $acceptOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            app(RequestStudentEnrollment::class)
                ->execute(
                    $student,
                    $learner,
                    $assignment,
                    $rejoinOperation,
                    'Concurrent rejoin.',
                );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $enrollment = app(
        \App\Application\Enrollment\AcceptStudentEnrollment::class
    )->execute(
        %s,
        %s,
        %s,
        'Concurrent acceptance.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'status' => $enrollment->status,
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
                    var_export($teacher, true),
                    var_export($enrollment->id, true),
                    var_export($acceptOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Concurrent Accept escaped rejoin serialization.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertSame(
                'active',
                $result['status'] ?? null
            );

            $history = DB::table(
                'student_enrollment_transitions'
            )
                ->where('enrollment_id', $enrollment->id)
                ->orderBy('sequence_number')
                ->get([
                    'from_status',
                    'to_status',
                    'outcome',
                    'operation_id',
                ]);

            $this->assertCount(4, $history);

            $this->assertSame(
                'inactive',
                $history[2]->from_status
            );
            $this->assertSame(
                'pending',
                $history[2]->to_status
            );
            $this->assertSame(
                'rejoined',
                $history[2]->outcome
            );
            $this->assertSame(
                $rejoinOperation,
                $history[2]->operation_id
            );

            $this->assertSame(
                'pending',
                $history[3]->from_status
            );
            $this->assertSame(
                'active',
                $history[3]->to_status
            );
            $this->assertSame(
                'accepted',
                $history[3]->outcome
            );
            $this->assertSame(
                $acceptOperation,
                $history[3]->operation_id
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_student_disable_wins_lock_and_concurrent_rejoin_rechecks_eligibility(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            $student,
            $learner,
            $assignment,
            (string) Str::uuid(),
            'Initial request.',
        );

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher,
                $enrollment->id,
                (string) Str::uuid(),
                'Initial decline.',
            );

        $rejoinOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            DB::table('users')
                ->where('id', $student)
                ->update([
                    'status' => 'disabled',
                    'updated_at' => now(),
                ]);

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $enrollment = app(
        \App\Application\Enrollment\RequestStudentEnrollment::class
    )->execute(
        %s,
        %s,
        %s,
        %s,
        'Eligibility race rejoin.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
            'status' => $enrollment->status,
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
                    var_export($student, true),
                    var_export($learner, true),
                    var_export($assignment, true),
                    var_export($rejoinOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Rejoin escaped Student eligibility lock.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'exception',
                $result['result'] ?? null
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null
            );

            $this->assertSame(
                'disabled',
                DB::table('users')
                    ->where('id', $student)
                    ->value('status')
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where('id', $enrollment->id)
                    ->value('status')
            );

            $this->assertSame(
                0,
                DB::table('student_enrollment_transitions')
                    ->where('operation_id', $rejoinOperation)
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_teacher_disable_wins_lock_and_concurrent_accept_rechecks_eligibility(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            $student,
            $learner,
            $assignment,
            (string) Str::uuid(),
            'Pending before teacher race.',
        );

        $acceptOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            DB::table('users')
                ->where('id', $teacher)
                ->update([
                    'status' => 'disabled',
                    'updated_at' => now(),
                ]);

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $enrollment = app(
        \App\Application\Enrollment\AcceptStudentEnrollment::class
    )->execute(
        %s,
        %s,
        %s,
        'Eligibility race acceptance.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
            'status' => $enrollment->status,
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
                    var_export($teacher, true),
                    var_export($enrollment->id, true),
                    var_export($acceptOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Accept escaped Teacher eligibility lock.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'exception',
                $result['result'] ?? null
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null
            );

            $this->assertSame(
                'disabled',
                DB::table('users')
                    ->where('id', $teacher)
                    ->value('status')
            );

            $this->assertSame(
                'pending',
                DB::table('student_enrollments')
                    ->where('id', $enrollment->id)
                    ->value('status')
            );

            $this->assertSame(
                0,
                DB::table('student_enrollment_transitions')
                    ->where('operation_id', $acceptOperation)
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_admin_deactivation_waits_on_admin_before_downstream_parent_locks(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            $student,
            $learner,
            $assignment,
            (string) Str::uuid(),
            'Request before lock-order probe.',
        );

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher,
                $enrollment->id,
                (string) Str::uuid(),
                'Accept before lock-order probe.',
            );

        $deactivateOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            DB::table('users')
                ->where('id', $admin)
                ->lockForUpdate()
                ->first();

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $enrollment = app(
        \App\Application\Enrollment\DeactivateStudentEnrollment::class
    )->execute(
        %s,
        %s,
        %s,
        'Admin lock-order probe.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'status' => $enrollment->status,
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
                    var_export($admin, true),
                    var_export($enrollment->id, true),
                    var_export($deactivateOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Admin deactivation did not wait on ADMIN parent lock.'
            );

            /*
             * If the child had inverted the protocol and locked
             * learner / teacher / assignment before waiting on ADMIN,
             * one of these NOWAIT probes would fail immediately.
             */
            DB::selectOne(
                'SELECT id FROM learner_profiles WHERE id = ? FOR UPDATE NOWAIT',
                [$learner]
            );

            DB::selectOne(
                'SELECT id FROM users WHERE id = ? FOR UPDATE NOWAIT',
                [$teacher]
            );

            DB::selectOne(
                'SELECT id FROM teacher_subject_assignments WHERE id = ? FOR UPDATE NOWAIT',
                [$assignment]
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertSame(
                'inactive',
                $result['status'] ?? null
            );

            $this->assertSame(
                'inactive',
                DB::table('student_enrollments')
                    ->where('id', $enrollment->id)
                    ->value('status')
            );

            $this->assertSame(
                1,
                DB::table('student_enrollment_transitions')
                    ->where(
                        'operation_id',
                        $deactivateOperation
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function studentIdentity(): array
    {
        $student = $this->createUser('student');

        $learner = LearnerProfile::query()->create([
            'user_id' => $student,
        ]);

        return [$student, $learner->id];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function teacherAssignment(): array
    {
        $admin = $this->createUser('admin');
        $teacher = $this->createUser('teacher');

        $subject = DB::table('subjects')
            ->whereNotNull('code')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->value('id');

        if (! is_string($subject) || $subject === '') {
            throw new RuntimeException(
                'Expected one active canonical subject.'
            );
        }

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            $admin,
            $teacher,
            $subject,
            (string) Str::uuid(),
            'Concurrency enrollment assignment.',
        );

        return [$admin, $teacher, $assignment->id];
    }

    private function createUser(string $role): string
    {
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'name' => "Enrollment Concurrency {$role} {$id}",
            'email' => "enrollment-concurrency-{$id}@example.test",
            'password' => 'not-used',
            'status' => 'active',
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return resource
     */
    private function startChild(
        string $childCode,
    ) {
        $this->signalFile = tempnam(
            sys_get_temp_dir(),
            'educore-enrollment-'
        );

        if ($this->signalFile === false) {
            throw new RuntimeException(
                'Unable to allocate enrollment concurrency signal file.'
            );
        }

        @unlink($this->signalFile);

        $childCode = str_replace(
            'NULL_SIGNAL_FILE',
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
                'Unable to start independent enrollment PHP process.'
            );
        }

        fclose($pipes[0]);

        $this->childProcess = $process;
        $this->childPipes = $pipes;

        return $process;
    }

    /**
     * @param  resource  $process
     */
    private function assertBlocked(
        $process,
        string $message,
    ): void {
        usleep(700000);

        $status = proc_get_status($process);

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
     * @param  resource  $process
     * @return array<string, mixed>
     */
    private function finishChild(
        $process,
    ): array {
        $deadline = microtime(true) + 8.0;

        do {
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        $this->assertFalse(
            $status['running'],
            'Enrollment child process did not finish after parent lock release.'
        );

        $stdout = stream_get_contents(
            $this->childPipes[1]
        );

        $stderr = stream_get_contents(
            $this->childPipes[2]
        );

        fclose($this->childPipes[1]);
        fclose($this->childPipes[2]);

        $this->childPipes = [];

        $this->assertFileExists(
            (string) $this->signalFile,
            "Enrollment child produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
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
        foreach ($this->childPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->childPipes = [];

        if (is_resource($this->childProcess)) {
            $status = proc_get_status(
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
}

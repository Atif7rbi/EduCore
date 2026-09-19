<?php

namespace Tests\Feature\Concurrency;

use App\Application\Exceptions\TeacherSubjectAssignmentOperationConflict;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class TeacherSubjectAssignmentConcurrencyTest extends TestCase
{
    use ResetsDedicatedTestDatabase;

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
                'TeacherSubjectAssignment concurrency tests may run only on sewaellf_educore_test.'
            );
        }
    }

    public function test_concurrent_initial_assignment_reuses_single_durable_pair(): void
    {
        $firstAdmin = $this->createUser('admin');
        $secondAdmin = $this->createUser('admin');
        $teacher = $this->createUser('teacher');
        [$subject] = $this->canonicalSubjects(1);

        $firstOperation = (string) Str::uuid();
        $secondOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            $parentAssignment = app(
                AssignTeacherSubject::class
            )->execute(
                $firstAdmin,
                $teacher,
                $subject,
                $firstOperation,
                'Concurrent initial assignment.',
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $assignment = app(
        \App\Application\TeacherAssignment\AssignTeacherSubject::class
    )->execute(
        %s,
        %s,
        %s,
        %s,
        'Concurrent second assignment.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'assignment_id' => $assignment->id,
            'status' => $assignment->status,
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
                    var_export($secondAdmin, true),
                    var_export($teacher, true),
                    var_export($subject, true),
                    var_export($secondOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Concurrent Assign escaped Teacher/Subject serialization.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'success',
                $result['result'] ?? null
            );

            $this->assertSame(
                $parentAssignment->id,
                $result['assignment_id'] ?? null
            );

            $this->assertSame(
                1,
                DB::table('teacher_subject_assignments')
                    ->where('teacher_id', $teacher)
                    ->where('subject_id', $subject)
                    ->count()
            );

            $this->assertSame(
                1,
                DB::table(
                    'teacher_subject_assignment_transitions'
                )
                    ->where(
                        'assignment_id',
                        $parentAssignment->id
                    )
                    ->count()
            );

            $this->assertSame(
                0,
                DB::table(
                    'teacher_subject_assignment_transitions'
                )
                    ->where(
                        'operation_id',
                        $secondOperation
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_same_operation_id_serializes_across_different_assignment_facts(): void
    {
        $firstAdmin = $this->createUser('admin');
        $secondAdmin = $this->createUser('admin');
        $firstTeacher = $this->createUser('teacher');
        $secondTeacher = $this->createUser('teacher');

        [$firstSubject, $secondSubject] =
            $this->canonicalSubjects(2);

        $operationId = (string) Str::uuid();

        DB::beginTransaction();

        try {
            $firstAssignment = app(
                AssignTeacherSubject::class
            )->execute(
                $firstAdmin,
                $firstTeacher,
                $firstSubject,
                $operationId,
                'Globally serialized operation.',
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $assignment = app(
        \App\Application\TeacherAssignment\AssignTeacherSubject::class
    )->execute(
        %s,
        %s,
        %s,
        %s,
        'Globally serialized operation.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
            'assignment_id' => $assignment->id,
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
                    var_export($secondAdmin, true),
                    var_export($secondTeacher, true),
                    var_export($secondSubject, true),
                    var_export($operationId, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Duplicate operation_id escaped advisory serialization.'
            );

            DB::commit();

            $result = $this->finishChild($process);

            $this->assertSame(
                'exception',
                $result['result'] ?? null
            );

            $this->assertSame(
                TeacherSubjectAssignmentOperationConflict::class,
                $result['class'] ?? null
            );

            $this->assertSame(
                1,
                DB::table(
                    'teacher_subject_assignment_transitions'
                )
                    ->where(
                        'operation_id',
                        $operationId
                    )
                    ->count()
            );

            $this->assertSame(
                $firstAssignment->id,
                DB::table(
                    'teacher_subject_assignment_transitions'
                )
                    ->where(
                        'operation_id',
                        $operationId
                    )
                    ->value('assignment_id')
            );

            $this->assertSame(
                0,
                DB::table('teacher_subject_assignments')
                    ->where(
                        'teacher_id',
                        $secondTeacher
                    )
                    ->where(
                        'subject_id',
                        $secondSubject
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_deactivate_then_concurrent_reactivate_serializes_history_chain(): void
    {
        $firstAdmin = $this->createUser('admin');
        $secondAdmin = $this->createUser('admin');
        $teacher = $this->createUser('teacher');
        [$subject] = $this->canonicalSubjects(1);

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            $firstAdmin,
            $teacher,
            $subject,
            (string) Str::uuid(),
            'Initial assignment.',
        );

        $deactivateOperation = (string) Str::uuid();
        $reactivateOperation = (string) Str::uuid();

        DB::beginTransaction();

        try {
            app(
                DeactivateTeacherSubjectAssignment::class
            )->execute(
                $firstAdmin,
                $assignment->id,
                $deactivateOperation,
                'Concurrent deactivation.',
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $assignment = app(
        \App\Application\TeacherAssignment\ReactivateTeacherSubjectAssignment::class
    )->execute(
        %s,
        %s,
        %s,
        'Concurrent reactivation.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
            'assignment_id' => $assignment->id,
            'status' => $assignment->status,
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
                    var_export($secondAdmin, true),
                    var_export($assignment->id, true),
                    var_export($reactivateOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Concurrent Reactivate escaped assignment lifecycle serialization.'
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

            $this->assertSame(
                'active',
                DB::table('teacher_subject_assignments')
                    ->where('id', $assignment->id)
                    ->value('status')
            );

            $history = DB::table(
                'teacher_subject_assignment_transitions'
            )
                ->where(
                    'assignment_id',
                    $assignment->id
                )
                ->orderBy('sequence_number')
                ->get([
                    'from_status',
                    'to_status',
                    'operation_id',
                ]);

            $this->assertCount(3, $history);

            $this->assertNull(
                $history[0]->from_status
            );
            $this->assertSame(
                'active',
                $history[0]->to_status
            );

            $this->assertSame(
                'active',
                $history[1]->from_status
            );
            $this->assertSame(
                'inactive',
                $history[1]->to_status
            );
            $this->assertSame(
                $deactivateOperation,
                $history[1]->operation_id
            );

            $this->assertSame(
                'inactive',
                $history[2]->from_status
            );
            $this->assertSame(
                'active',
                $history[2]->to_status
            );
            $this->assertSame(
                $reactivateOperation,
                $history[2]->operation_id
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_teacher_disable_wins_lock_and_concurrent_reactivation_rechecks_eligibility(): void
    {
        $firstAdmin = $this->createUser('admin');
        $secondAdmin = $this->createUser('admin');
        $teacher = $this->createUser('teacher');
        [$subject] = $this->canonicalSubjects(1);

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            $firstAdmin,
            $teacher,
            $subject,
            (string) Str::uuid(),
            'Initial assignment.',
        );

        app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            $firstAdmin,
            $assignment->id,
            (string) Str::uuid(),
            'Deactivate before eligibility race.',
        );

        $reactivateOperation = (string) Str::uuid();

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
    $assignment = app(
        \App\Application\TeacherAssignment\ReactivateTeacherSubjectAssignment::class
    )->execute(
        %s,
        %s,
        %s,
        'Eligibility race reactivation.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
            'status' => $assignment->status,
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
                    var_export($secondAdmin, true),
                    var_export($assignment->id, true),
                    var_export($reactivateOperation, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Reactivate escaped Teacher eligibility lock.'
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
                'inactive',
                DB::table('teacher_subject_assignments')
                    ->where('id', $assignment->id)
                    ->value('status')
            );

            $this->assertSame(
                0,
                DB::table(
                    'teacher_subject_assignment_transitions'
                )
                    ->where(
                        'operation_id',
                        $reactivateOperation
                    )
                    ->count()
            );
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    /**
     * @return list<string>
     */
    private function canonicalSubjects(int $count): array
    {
        $subjects = DB::table('subjects')
            ->whereNotNull('code')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->limit($count)
            ->pluck('id')
            ->map(
                static fn ($id): string => (string) $id
            )
            ->all();

        if (count($subjects) !== $count) {
            throw new RuntimeException(
                "Expected {$count} active canonical subjects."
            );
        }

        return $subjects;
    }

    private function createUser(string $role): string
    {
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'name' => "Concurrency {$role} {$id}",
            'email' => "teacher-assignment-concurrency-{$id}@example.test",
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
            'educore-teacher-assignment-'
        );

        if ($this->signalFile === false) {
            throw new RuntimeException(
                'Unable to allocate concurrency signal file.'
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
                'Unable to start independent PHP process.'
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
            'Child process did not finish after parent lock release.'
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

<?php

namespace Tests\Feature\Concurrency;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CurriculumOwnershipConcurrencyTest extends TestCase
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
                'Curriculum ownership concurrency tests may run only on sewaellf_educore_test.'
            );
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupChild();

            /*
             * These multiprocess cases must commit real PostgreSQL
             * transactions so the independent child connection can
             * observe authoritative locks and committed state.
             *
             * Restore the dedicated test database after each case so
             * committed concurrency fixtures cannot leak into later
             * PHPUnit tests.
             */
            $database = DB::selectOne(
                'SELECT current_database() AS database_name'
            );

            if (
                ! is_object($database)
                || ($database->database_name ?? null)
                    !== 'sewaellf_educore_test'
            ) {
                throw new RuntimeException(
                    'Refusing concurrency cleanup outside '
                    .'sewaellf_educore_test.'
                );
            }

            $exitCode = Artisan::call(
                'migrate:fresh',
                [
                    '--force' => true,
                ],
            );

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'Failed to restore concurrency test '
                    .'database baseline.'
                );
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_curriculum_creation_wins_then_concurrent_deactivation_preserves_historical_ownership(): void
    {
        [$admin, $teacher, $assignmentId] =
            $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        DB::beginTransaction();

        try {
            $curriculum = app(
                CreateOwnedCurriculum::class
            )->execute(
                actorUserId: $teacher,
                teacherSubjectAssignmentId: $assignmentId,
                name: 'Create Wins Concurrency Curriculum',
                educationStageId: null,
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $assignment = app(
        \App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment::class
    )->execute(
        %s,
        %s,
        %s,
        'Concurrent deactivation after curriculum creation.'
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'success',
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
                    var_export($admin, true),
                    var_export($assignmentId, true),
                    var_export($operationId, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Assignment deactivation escaped Curriculum creation parent locks.'
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
                DB::table('teacher_subject_assignments')
                    ->where('id', $assignmentId)
                    ->value('status')
            );

            $this->assertSame(
                1,
                DB::table('curricula')
                    ->where('id', $curriculum->id)
                    ->where(
                        'teacher_subject_assignment_id',
                        $assignmentId
                    )
                    ->count()
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
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
    }

    public function test_assignment_deactivation_wins_then_concurrent_creation_rechecks_authoritative_status(): void
    {
        [$admin, $teacher, $assignmentId] =
            $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        DB::beginTransaction();

        try {
            app(
                DeactivateTeacherSubjectAssignment::class
            )->execute(
                actorUserId: $admin,
                assignmentId: $assignmentId,
                operationId: $operationId,
                reason: 'Deactivate before concurrent curriculum create.',
            );

            $process = $this->startChild(
                sprintf(
                    <<<'PHP_CODE'
try {
    $curriculum = app(
        \App\Application\Curriculum\CreateOwnedCurriculum::class
    )->execute(
        %s,
        %s,
        'Deactivate Wins Concurrency Curriculum',
        null
    );

    file_put_contents(
        %s,
        json_encode([
            'result' => 'unexpected_success',
            'curriculum_id' => $curriculum->id,
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
                    var_export($assignmentId, true),
                    'NULL_SIGNAL_FILE',
                    'NULL_SIGNAL_FILE',
                )
            );

            $this->assertBlocked(
                $process,
                'Curriculum creation escaped assignment deactivation parent locks.'
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
                'inactive',
                DB::table('teacher_subject_assignments')
                    ->where('id', $assignmentId)
                    ->value('status')
            );

            $this->assertSame(
                0,
                DB::table('curricula')
                    ->where(
                        'teacher_subject_assignment_id',
                        $assignmentId
                    )
                    ->where(
                        'name',
                        'Deactivate Wins Concurrency Curriculum'
                    )
                    ->count()
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
        } finally {
            $this->rollbackIfNeeded();
            $this->cleanupChild();
        }
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
            actorUserId: $admin,
            teacherUserId: $teacher,
            subjectId: $subject,
            operationId: (string) Str::uuid(),
            reason: 'Curriculum ownership concurrency assignment.',
        );

        return [
            $admin,
            $teacher,
            $assignment->id,
        ];
    }

    private function createUser(
        string $role,
    ): string {
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'name' => "Curriculum Ownership {$role} {$id}",
            'email' => "curriculum-ownership-concurrency-{$role}-{$id}@example.test",
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
            'educore-curriculum-ownership-'
        );

        if ($this->signalFile === false) {
            throw new RuntimeException(
                'Unable to allocate Curriculum ownership concurrency signal file.'
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
                'Unable to start independent Curriculum ownership PHP process.'
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
            'Curriculum ownership child process did not finish after parent lock release.'
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
            "Curriculum ownership child produced no result.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
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

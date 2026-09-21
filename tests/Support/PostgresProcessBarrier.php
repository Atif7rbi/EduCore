<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostgresProcessBarrier
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $readyFile;

    private string $goFile;

    private string $resultFile;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function start(
        array $payload,
    ): self {
        self::assertDedicatedTestDatabase();

        $instance = new self;

        $instance->readyFile =
            self::allocateSignalPath(
                'educore-pg-ready-'
            );

        $instance->goFile =
            self::allocateSignalPath(
                'educore-pg-go-'
            );

        $instance->resultFile =
            self::allocateSignalPath(
                'educore-pg-result-'
            );

        $payload['ready_file'] =
            $instance->readyFile;

        $payload['go_file'] =
            $instance->goFile;

        $payload['result_file'] =
            $instance->resultFile;

        $encoded = base64_encode(
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR,
            ),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/'
                    .'phase_f_concurrency_worker.php'
                ),
                $encoded,
            ],
            $descriptors,
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            $instance->cleanupFiles();

            throw new RuntimeException(
                'Unable to start PostgreSQL concurrency worker.'
            );
        }

        $instance->process = $process;
        $instance->pipes = $pipes;

        fclose($instance->pipes[0]);
        unset($instance->pipes[0]);

        return $instance;
    }

    /**
     * @return array{
     *     pid: int,
     *     database: string,
     *     user: string
     * }
     */
    public function awaitReady(
        float $timeoutSeconds = 8.0,
    ): array {
        $deadline =
            microtime(true) + $timeoutSeconds;

        do {
            if (is_file($this->readyFile)) {
                $ready = json_decode(
                    (string) file_get_contents(
                        $this->readyFile
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                if (
                    ! is_array($ready)
                    || ! isset(
                        $ready['pid'],
                        $ready['database'],
                        $ready['user'],
                    )
                ) {
                    throw new RuntimeException(
                        'Concurrency worker READY payload is invalid.'
                    );
                }

                return [
                    'pid' => (int) $ready['pid'],
                    'database' => (string) $ready['database'],
                    'user' => (string) $ready['user'],
                ];
            }

            $this->assertProcessStillRunning(
                'Worker exited before READY.'
            );

            usleep(10_000);
        } while (
            microtime(true) < $deadline
        );

        throw new RuntimeException(
            'Timed out waiting for concurrency worker READY.'
        );
    }

    public function release(): void
    {
        $bytes = file_put_contents(
            $this->goFile,
            "GO\n",
            LOCK_EX,
        );

        if ($bytes === false) {
            throw new RuntimeException(
                'Unable to release concurrency worker.'
            );
        }
    }

    /**
     * Proves that the exact worker PostgreSQL backend
     * is waiting on a lock owned by this exact parent
     * PostgreSQL backend.
     *
     * @return array{
     *     child_pid: int,
     *     parent_pid: int,
     *     state: string|null,
     *     wait_event_type: string,
     *     wait_event: string|null,
     *     blocked_by_parent: bool
     * }
     */
    public function awaitBlockedByCurrentConnection(
        int $childPid,
        float $timeoutSeconds = 8.0,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Parent must hold an open transaction '
                .'while proving PostgreSQL lock wait.'
            );
        }

        $parent = DB::selectOne(
            'SELECT pg_backend_pid() AS pid'
        );

        if ($parent === null) {
            throw new RuntimeException(
                'Unable to resolve parent PostgreSQL backend PID.'
            );
        }

        $parentPid = (int) $parent->pid;

        $deadline =
            microtime(true) + $timeoutSeconds;

        do {
            $row = DB::selectOne(
                <<<'SQL'
SELECT
    a.pid,
    a.state,
    a.wait_event_type,
    a.wait_event,
    (
        ?::integer
        = ANY(pg_blocking_pids(a.pid))
    ) AS blocked_by_parent
FROM pg_stat_activity AS a
WHERE a.pid = ?::integer
SQL,
                [
                    $parentPid,
                    $childPid,
                ],
            );

            if ($row !== null) {
                $blockedByParent =
                    self::postgresBoolean(
                        $row->blocked_by_parent
                            ?? false
                    );

                if (
                    $row->wait_event_type
                        === 'Lock'
                    && $blockedByParent
                ) {
                    return [
                        'child_pid' => (int) $row->pid,
                        'parent_pid' => $parentPid,
                        'state' => isset($row->state)
                                ? (string) $row->state
                                : null,
                        'wait_event_type' => (string)
                            $row->wait_event_type,
                        'wait_event' => isset($row->wait_event)
                                ? (string)
                                $row->wait_event
                                : null,
                        'blocked_by_parent' => true,
                    ];
                }
            }

            $this->assertProcessStillRunning(
                'Worker exited before PostgreSQL '
                .'lock wait was observed.'
            );

            usleep(10_000);
        } while (
            microtime(true) < $deadline
        );

        throw new RuntimeException(
            'Timed out waiting for PostgreSQL to '
            .'report worker blocked by parent backend.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function finish(
        float $timeoutSeconds = 8.0,
    ): array {
        if (! is_resource($this->process)) {
            throw new RuntimeException(
                'Concurrency worker is not running.'
            );
        }

        $deadline =
            microtime(true) + $timeoutSeconds;

        do {
            $status =
                proc_get_status($this->process);

            if (! $status['running']) {
                break;
            }

            usleep(10_000);
        } while (
            microtime(true) < $deadline
        );

        if ($status['running']) {
            throw new RuntimeException(
                'Concurrency worker did not finish '
                .'after parent lock release.'
            );
        }

        $stdout = isset($this->pipes[1])
            ? stream_get_contents(
                $this->pipes[1]
            )
            : '';

        $stderr = isset($this->pipes[2])
            ? stream_get_contents(
                $this->pipes[2]
            )
            : '';

        $this->closePipes();

        proc_close($this->process);
        $this->process = null;

        if (! is_file($this->resultFile)) {
            throw new RuntimeException(
                'Concurrency worker produced no result.'
                ."\nSTDOUT:\n{$stdout}"
                ."\nSTDERR:\n{$stderr}"
            );
        }

        $result = json_decode(
            (string) file_get_contents(
                $this->resultFile
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($result)) {
            throw new RuntimeException(
                'Concurrency worker result is invalid.'
            );
        }

        $result['_stdout'] = $stdout;
        $result['_stderr'] = $stderr;

        return $result;
    }

    public function cleanup(): void
    {
        if (is_resource($this->process)) {
            $status =
                proc_get_status($this->process);

            if ($status['running']) {
                proc_terminate(
                    $this->process
                );
            }

            $this->closePipes();

            proc_close($this->process);
            $this->process = null;
        } else {
            $this->closePipes();
        }

        $this->cleanupFiles();
    }

    private function assertProcessStillRunning(
        string $message,
    ): void {
        if (! is_resource($this->process)) {
            throw new RuntimeException(
                $message
            );
        }

        $status =
            proc_get_status($this->process);

        if (! $status['running']) {
            $stdout = isset($this->pipes[1])
                ? stream_get_contents(
                    $this->pipes[1]
                )
                : '';

            $stderr = isset($this->pipes[2])
                ? stream_get_contents(
                    $this->pipes[2]
                )
                : '';

            throw new RuntimeException(
                $message
                ."\nSTDOUT:\n{$stdout}"
                ."\nSTDERR:\n{$stderr}"
            );
        }
    }

    private function closePipes(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];
    }

    private function cleanupFiles(): void
    {
        foreach ([
            $this->readyFile ?? null,
            $this->goFile ?? null,
            $this->resultFile ?? null,
        ] as $path) {
            if (
                is_string($path)
                && $path !== ''
            ) {
                @unlink($path);
            }
        }
    }

    private static function allocateSignalPath(
        string $prefix,
    ): string {
        $path = tempnam(
            sys_get_temp_dir(),
            $prefix,
        );

        if ($path === false) {
            throw new RuntimeException(
                'Unable to allocate concurrency signal path.'
            );
        }

        @unlink($path);

        return $path;
    }

    private static function assertDedicatedTestDatabase(): void
    {
        $identity = DB::selectOne(
            <<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user
SQL
        );

        if (
            $identity === null
            || $identity->database_name
                !== 'sewaellf_educore_test'
            || $identity->database_user
                !== 'sewaellf_educore_Admin'
        ) {
            throw new RuntimeException(
                'Concurrency harness refused '
                .'non-dedicated PostgreSQL identity.'
            );
        }
    }

    private static function postgresBoolean(
        mixed $value,
    ): bool {
        return in_array(
            $value,
            [
                true,
                1,
                '1',
                't',
                'true',
            ],
            true,
        );
    }
}

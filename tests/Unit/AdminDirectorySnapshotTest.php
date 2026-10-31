<?php

namespace Tests\Unit;

use App\Application\AdminDirectory\RunAdminDirectorySnapshot;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminDirectorySnapshotTest extends TestCase
{
    public function test_snapshot_is_repeatable_read_and_read_only(): void
    {
        $before = DB::connection()
            ->transactionLevel();

        $state = app(
            RunAdminDirectorySnapshot::class
        )->execute(
            function (
                Connection $connection,
            ): object {
                return $connection->selectOne(
                    <<<'SQL'
SELECT
    current_setting(
        'transaction_isolation'
    ) AS isolation_level,
    current_setting(
        'transaction_read_only'
    ) AS read_only
SQL
                );
            }
        );

        $this->assertSame(
            'repeatable read',
            $state->isolation_level
        );
        $this->assertSame(
            'on',
            $state->read_only
        );
        $this->assertSame(
            $before,
            DB::connection()->transactionLevel()
        );
    }

    public function test_snapshot_rejects_incompatible_outer_transaction(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $this->expectException(
                RuntimeException::class
            );

            app(
                RunAdminDirectorySnapshot::class
            )->execute(
                fn (): null => null
            );
        } finally {
            $connection->rollBack();
        }
    }

    public function test_production_directory_query_uses_one_snapshot_across_a_concurrent_commit(): void
    {
        config([
            'session.driver' => 'array',
        ]);

        foreach (
            [
                'pcntl_alarm',
                'pcntl_async_signals',
                'pcntl_signal',
                'pcntl_signal_get_handler',
            ] as $function
        ) {
            $this->assertTrue(
                function_exists($function),
                'The bounded concurrency fixture requires '.$function.'.'
            );
        }

        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();
        $disabledId = (string) Str::uuid();
        $adminId = (string) Str::uuid();
        $namespace = (string) Str::uuid();
        $search = 'Snapshot '.$namespace;
        $expectedPattern = '%'.$search.'%';
        $writerName =
            'admin_directory_snapshot_writer';
        $observerName =
            'admin_directory_snapshot_observer';
        $reader = DB::connection();
        $readerName = $reader->getName();
        $originalDispatcher =
            $reader->getEventDispatcher();
        $dispatcherReplaced = false;
        $alarmInstalled = false;
        $previousAsyncSignals = false;
        $previousAlarmHandler = SIG_DFL;
        $readerTimeoutsApplied = false;
        $countObserved = false;
        $writerCommitted = false;
        $observerSawCommit = false;
        $pageRowsObserved = false;

        config([
            'database.connections.'
            .$writerName => config(
                'database.connections.pgsql'
            ),
            'database.connections.'
            .$observerName => config(
                'database.connections.pgsql'
            ),
        ]);

        DB::purge($writerName);
        DB::purge($observerName);

        try {
            $reader->table('users')->insert(
                $this->userRow(
                    $firstId,
                    $namespace,
                    'first',
                )
            );
            $reader->table('users')->insert(
                $this->userRow(
                    $disabledId,
                    $namespace,
                    'disabled',
                    'teacher',
                    'disabled',
                )
            );
            $reader->table('users')->insert(
                $this->userRow(
                    $adminId,
                    $namespace,
                    'admin',
                    'admin',
                )
            );

            $admin = User::query()->findOrFail(
                $adminId
            );
            $writer = DB::connection($writerName);
            $observer = DB::connection($observerName);

            $readerIdentity = $reader->selectOne(
                'SELECT current_database() AS database_name, '
                .'current_user AS database_user'
            );
            $writerIdentity = $writer->selectOne(
                'SELECT current_database() AS database_name, '
                .'current_user AS database_user'
            );
            $observerIdentity = $observer->selectOne(
                'SELECT current_database() AS database_name, '
                .'current_user AS database_user'
            );

            $this->assertEquals(
                $readerIdentity,
                $writerIdentity
            );
            $this->assertEquals(
                $readerIdentity,
                $observerIdentity
            );
            $this->assertSame(
                0,
                $writer->transactionLevel()
            );
            $this->assertSame(
                0,
                $observer->transactionLevel()
            );

            $reader->setEventDispatcher(
                new Dispatcher(app())
            );
            $dispatcherReplaced = true;

            $reader->listen(
                function (
                    QueryExecuted $query,
                ) use (
                    &$countObserved,
                    &$observerSawCommit,
                    &$pageRowsObserved,
                    &$readerTimeoutsApplied,
                    &$writerCommitted,
                    $expectedPattern,
                    $namespace,
                    $observer,
                    $readerName,
                    $secondId,
                    $writer,
                ): void {
                    if (
                        $query->connectionName
                            !== $readerName
                    ) {
                        return;
                    }

                    $sql = strtoupper(
                        ltrim($query->sql)
                    );

                    if (
                        ! $readerTimeoutsApplied
                        && str_starts_with(
                            $sql,
                            'SET TRANSACTION'
                        )
                    ) {
                        $readerTimeoutsApplied = true;
                        $query->connection->statement(
                            "SET LOCAL statement_timeout = '5s'"
                        );
                        $query->connection->statement(
                            "SET LOCAL lock_timeout = '2s'"
                        );

                        return;
                    }

                    if (
                        $query->connection
                            ->transactionLevel() === 0
                    ) {
                        return;
                    }

                    $matchesRequest = in_array(
                        $expectedPattern,
                        $query->bindings,
                        true,
                    );

                    if (
                        ! $writerCommitted
                        && $matchesRequest
                        && str_starts_with(
                            $sql,
                            'SELECT COUNT('
                        )
                    ) {
                        $countObserved = true;

                        $writer->transaction(
                            function (
                                Connection $connection,
                            ) use (
                                $namespace,
                                $secondId,
                            ): void {
                                $connection->statement(
                                    "SET LOCAL statement_timeout = '5s'"
                                );
                                $connection->statement(
                                    "SET LOCAL lock_timeout = '2s'"
                                );
                                $connection->table('users')
                                    ->insert(
                                        $this->userRow(
                                            $secondId,
                                            $namespace,
                                            'second',
                                        )
                                    );
                            }
                        );

                        $this->assertSame(
                            0,
                            $writer->transactionLevel()
                        );

                        $observerSawCommit = $observer
                            ->transaction(
                                function (
                                    Connection $connection,
                                ) use ($secondId): bool {
                                    $connection->statement(
                                        "SET LOCAL statement_timeout = '5s'"
                                    );
                                    $connection->statement(
                                        "SET LOCAL lock_timeout = '2s'"
                                    );

                                    return $connection
                                        ->table('users')
                                        ->where('id', $secondId)
                                        ->exists();
                                }
                            );
                        $this->assertTrue(
                            $observerSawCommit,
                            'The observer did not see the committed writer row.'
                        );

                        $writerCommitted = true;

                        return;
                    }

                    if (
                        $writerCommitted
                        && ! $pageRowsObserved
                        && $matchesRequest
                        && str_starts_with($sql, 'SELECT')
                    ) {
                        $pageRowsObserved = true;
                    }
                }
            );

            $previousAsyncSignals =
                pcntl_async_signals();
            $previousAlarmHandler =
                pcntl_signal_get_handler(SIGALRM);
            pcntl_async_signals(true);
            pcntl_signal(
                SIGALRM,
                static function (): never {
                    throw new RuntimeException(
                        'Admin directory concurrency coordination exceeded 15 seconds.'
                    );
                }
            );
            pcntl_alarm(15);
            $alarmInstalled = true;

            $snapshot = $this->actingAs($admin)
                ->getJson(
                    '/api/admin/directories/teachers?'
                    .http_build_query([
                        'search' => $search,
                        'status' => 'active',
                        'page' => 1,
                        'per_page' => 20,
                    ])
                )
                ->assertOk();

            pcntl_alarm(0);
            pcntl_signal(
                SIGALRM,
                $previousAlarmHandler === false
                    ? SIG_DFL
                    : $previousAlarmHandler
            );
            pcntl_async_signals(
                $previousAsyncSignals
            );
            $alarmInstalled = false;

            if ($originalDispatcher === null) {
                $reader->unsetEventDispatcher();
            } else {
                $reader->setEventDispatcher(
                    $originalDispatcher
                );
            }
            $dispatcherReplaced = false;

            $this->assertTrue(
                $readerTimeoutsApplied,
                'The production reader transaction was not observed.'
            );
            $this->assertTrue(
                $countObserved,
                'The production directory count query was not observed.'
            );
            $this->assertTrue(
                $writerCommitted,
                'The independent writer did not complete.'
            );
            $this->assertTrue(
                $observerSawCommit,
                'The committed row was not independently visible.'
            );
            $this->assertTrue(
                $pageRowsObserved,
                'The production paginated row query was not observed.'
            );

            $snapshot
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath(
                    'data.items.0.user_id',
                    $firstId
                )
                ->assertJsonPath(
                    'data.items.0.role',
                    'teacher'
                )
                ->assertJsonPath(
                    'data.items.0.status',
                    'active'
                )
                ->assertJsonPath(
                    'data.pagination.current_page',
                    1
                )
                ->assertJsonPath(
                    'data.pagination.per_page',
                    20
                )
                ->assertJsonPath(
                    'data.pagination.total',
                    1
                )
                ->assertJsonPath(
                    'data.pagination.last_page',
                    1
                )
                ->assertJsonStructure([
                    'data' => [
                        'items' => [
                            '*' => [
                                'user_id',
                                'name',
                                'email',
                                'role',
                                'status',
                                'provisioning' => [
                                    'state',
                                    'provisioned_by_user_id',
                                    'setup_completed_at',
                                    'created_at',
                                ],
                                'assignment_counts' => [
                                    'active',
                                    'inactive',
                                    'total',
                                ],
                                'created_at',
                            ],
                        ],
                        'pagination' => [
                            'current_page',
                            'per_page',
                            'total',
                            'last_page',
                        ],
                    ],
                ]);

            $fresh = $this->getJson(
                '/api/admin/directories/teachers?'
                .http_build_query([
                    'search' => $search,
                    'status' => 'active',
                    'page' => 1,
                    'per_page' => 20,
                ])
            )->assertOk();

            $this->assertSame(
                2,
                $fresh->json(
                    'data.pagination.total'
                )
            );
            $this->assertSame(
                [$firstId, $secondId],
                array_column(
                    $fresh->json('data.items'),
                    'user_id'
                )
            );
        } finally {
            if ($alarmInstalled) {
                pcntl_alarm(0);
                pcntl_signal(
                    SIGALRM,
                    $previousAlarmHandler === false
                        ? SIG_DFL
                        : $previousAlarmHandler
                );
                pcntl_async_signals(
                    $previousAsyncSignals
                );
            }

            if ($dispatcherReplaced) {
                if ($originalDispatcher === null) {
                    $reader->unsetEventDispatcher();
                } else {
                    $reader->setEventDispatcher(
                        $originalDispatcher
                    );
                }
            }

            DB::purge($writerName);
            DB::purge($observerName);

            $reader->table('sessions')
                ->whereIn(
                    'user_id',
                    [
                        $firstId,
                        $secondId,
                        $disabledId,
                        $adminId,
                    ]
                )
                ->delete();

            $reader->table('users')
                ->whereIn(
                    'id',
                    [
                        $firstId,
                        $secondId,
                        $disabledId,
                        $adminId,
                    ]
                )
                ->delete();
        }
    }

    private function userRow(
        string $id,
        string $namespace,
        string $label,
        string $role = 'teacher',
        string $status = 'active',
    ): array {
        return [
            'id' => $id,
            'name' => 'Snapshot '
                .$namespace.' '.$label,
            'email' => 'snapshot.'
                .$namespace.'.'
                .$label.'@example.test',
            'email_verified_at' => null,
            'password' => 'not-used',
            'status' => $status,
            'role' => $role,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

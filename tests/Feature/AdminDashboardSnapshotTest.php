<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminDashboardSnapshotTest extends TestCase
{
    public function test_dashboard_rejects_incompatible_outer_transaction(): void
    {
        config(['session.driver' => 'array']);

        $connection = DB::connection();
        $this->assertSafeTestDatabase($connection);
        $adminId = (string) Str::uuid();

        $connection->table('users')->insert(
            $this->userRow($adminId, 'admin', 'Outer Guard')
        );

        try {
            $admin = User::query()->findOrFail($adminId);
            $this->actingAs($admin);
            $connection->beginTransaction();

            $this->getJson('/api/admin/dashboard')
                ->assertStatus(500)
                ->assertJsonPath('error.code', 'internal_error')
                ->assertJsonMissingPath('data');
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            $connection->table('sessions')
                ->where('user_id', $adminId)
                ->delete();
            $connection->table('users')
                ->where('id', $adminId)
                ->delete();
        }
    }

    public function test_production_dashboard_uses_one_snapshot_across_a_concurrent_commit(): void
    {
        config(['session.driver' => 'array']);

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
                'The bounded dashboard fixture requires '.$function.'.'
            );
        }

        $reader = DB::connection();
        $this->assertSafeTestDatabase($reader);
        $this->assertSame(0, $reader->transactionLevel());

        $namespace = (string) Str::uuid();
        $adminId = (string) Str::uuid();
        $firstTeacherId = (string) Str::uuid();
        $secondTeacherId = (string) Str::uuid();
        $writerName = 'admin_dashboard_snapshot_writer';
        $observerName = 'admin_dashboard_snapshot_observer';
        $readerName = $reader->getName();
        $originalDispatcher = $reader->getEventDispatcher();
        $dispatcherReplaced = false;
        $alarmInstalled = false;
        $previousAsyncSignals = false;
        $previousAlarmHandler = SIG_DFL;
        $readerTimeoutsApplied = false;
        $snapshotStateVerified = false;
        $productionReadObserved = false;
        $writerCommitted = false;
        $observerSawCommit = false;
        $operationsQueryObserved = false;

        $baselineTeacherActive = $reader->table('users')
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->count();
        $baselineTeacherTotal = $reader->table('users')
            ->where('role', 'teacher')
            ->count();

        $defaultConnection = config('database.default');
        config([
            'database.connections.'.$writerName => config(
                'database.connections.'.$defaultConnection
            ),
            'database.connections.'.$observerName => config(
                'database.connections.'.$defaultConnection
            ),
        ]);

        DB::purge($writerName);
        DB::purge($observerName);

        try {
            $reader->table('users')->insert([
                $this->userRow(
                    $adminId,
                    'admin',
                    'Dashboard Admin '.$namespace
                ),
                $this->userRow(
                    $firstTeacherId,
                    'teacher',
                    'Dashboard Teacher First '.$namespace
                ),
            ]);

            $admin = User::query()->findOrFail($adminId);
            $writer = DB::connection($writerName);
            $observer = DB::connection($observerName);
            $this->assertSame(
                $this->databaseIdentity($reader),
                $this->databaseIdentity($writer)
            );
            $this->assertSame(
                $this->databaseIdentity($reader),
                $this->databaseIdentity($observer)
            );
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertSame(0, $observer->transactionLevel());

            $reader->setEventDispatcher(new Dispatcher(app()));
            $dispatcherReplaced = true;

            $reader->listen(
                function (QueryExecuted $query) use (
                    &$observerSawCommit,
                    &$operationsQueryObserved,
                    &$productionReadObserved,
                    &$readerTimeoutsApplied,
                    &$snapshotStateVerified,
                    &$writerCommitted,
                    $namespace,
                    $observer,
                    $readerName,
                    $secondTeacherId,
                    $writer,
                ): void {
                    if ($query->connectionName !== $readerName) {
                        return;
                    }

                    $sql = strtoupper(
                        preg_replace(
                            '/\s+/',
                            ' ',
                            trim($query->sql)
                        ) ?? ''
                    );

                    if (
                        ! $readerTimeoutsApplied
                        && str_starts_with($sql, 'SET TRANSACTION')
                    ) {
                        $readerTimeoutsApplied = true;
                        $query->connection->statement(
                            "SET LOCAL statement_timeout = '5s'"
                        );
                        $query->connection->statement(
                            "SET LOCAL lock_timeout = '2s'"
                        );
                        $state = $query->connection->selectOne(
                            <<<'SQL'
SELECT
    current_setting('transaction_isolation') AS isolation_level,
    current_setting('transaction_read_only') AS read_only
SQL
                        );
                        $snapshotStateVerified =
                            ($state->isolation_level ?? null)
                                === 'repeatable read'
                            && ($state->read_only ?? null) === 'on';

                        return;
                    }

                    if (
                        $query->connection->transactionLevel() === 0
                    ) {
                        return;
                    }

                    if (
                        ! $writerCommitted
                        && str_contains($sql, 'FROM "SUBJECTS"')
                        && str_contains($sql, 'COUNT(*)')
                    ) {
                        $productionReadObserved = true;
                        $writer->transaction(
                            function (Connection $connection) use (
                                $namespace,
                                $secondTeacherId,
                            ): void {
                                $connection->statement(
                                    "SET LOCAL statement_timeout = '5s'"
                                );
                                $connection->statement(
                                    "SET LOCAL lock_timeout = '2s'"
                                );
                                $connection->table('users')->insert(
                                    $this->userRow(
                                        $secondTeacherId,
                                        'teacher',
                                        'Dashboard Teacher Second '
                                            .$namespace
                                    )
                                );
                            }
                        );
                        $this->assertSame(
                            0,
                            $writer->transactionLevel()
                        );

                        $observerSawCommit = $observer->transaction(
                            function (Connection $connection) use (
                                $secondTeacherId,
                            ): bool {
                                $connection->statement(
                                    "SET LOCAL statement_timeout = '5s'"
                                );
                                $connection->statement(
                                    "SET LOCAL lock_timeout = '2s'"
                                );

                                return $connection->table('users')
                                    ->where('id', $secondTeacherId)
                                    ->exists();
                            }
                        );
                        $this->assertTrue(
                            $observerSawCommit,
                            'The observer did not see the committed Teacher.'
                        );
                        $writerCommitted = true;

                        return;
                    }

                    if (
                        $writerCommitted
                        && str_contains($sql, 'FROM "USERS"')
                        && str_contains($sql, 'FILTER')
                    ) {
                        $operationsQueryObserved = true;
                    }
                }
            );

            $previousAsyncSignals = pcntl_async_signals();
            $previousAlarmHandler =
                pcntl_signal_get_handler(SIGALRM);
            pcntl_async_signals(true);
            pcntl_signal(
                SIGALRM,
                static function (): never {
                    throw new RuntimeException(
                        'Dashboard concurrency coordination exceeded 15 seconds.'
                    );
                }
            );
            pcntl_alarm(15);
            $alarmInstalled = true;

            $snapshot = $this->actingAs($admin)
                ->getJson('/api/admin/dashboard')
                ->assertOk();

            pcntl_alarm(0);
            pcntl_signal(
                SIGALRM,
                $previousAlarmHandler === false
                    ? SIG_DFL
                    : $previousAlarmHandler
            );
            pcntl_async_signals($previousAsyncSignals);
            $alarmInstalled = false;

            if ($originalDispatcher === null) {
                $reader->unsetEventDispatcher();
            } else {
                $reader->setEventDispatcher($originalDispatcher);
            }
            $dispatcherReplaced = false;

            $this->assertTrue($readerTimeoutsApplied);
            $this->assertTrue($snapshotStateVerified);
            $this->assertTrue($productionReadObserved);
            $this->assertTrue($writerCommitted);
            $this->assertTrue($observerSawCommit);
            $this->assertTrue($operationsQueryObserved);
            $this->assertSame(0, $reader->transactionLevel());

            $snapshot
                ->assertJsonPath(
                    'data.operations.teacher_accounts.active',
                    $baselineTeacherActive + 1
                )
                ->assertJsonPath(
                    'data.operations.teacher_accounts.total',
                    $baselineTeacherTotal + 1
                );

            $fresh = $this->getJson('/api/admin/dashboard')
                ->assertOk();
            $fresh
                ->assertJsonPath(
                    'data.operations.teacher_accounts.active',
                    $baselineTeacherActive + 2
                )
                ->assertJsonPath(
                    'data.operations.teacher_accounts.total',
                    $baselineTeacherTotal + 2
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
                pcntl_async_signals($previousAsyncSignals);
            }

            if ($dispatcherReplaced) {
                if ($originalDispatcher === null) {
                    $reader->unsetEventDispatcher();
                } else {
                    $reader->setEventDispatcher($originalDispatcher);
                }
            }

            DB::purge($writerName);
            DB::purge($observerName);

            while ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }

            $ids = [
                $adminId,
                $firstTeacherId,
                $secondTeacherId,
            ];
            $reader->table('sessions')
                ->whereIn('user_id', $ids)
                ->delete();
            $reader->table('users')
                ->whereIn('id', $ids)
                ->delete();
        }
    }

    private function userRow(
        string $id,
        string $role,
        string $name,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'email' => Str::uuid().'@dashboard.snapshot.test',
            'email_verified_at' => now(),
            'password' => Hash::make('DashboardSnapshot@1200'),
            'status' => 'active',
            'role' => $role,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function databaseIdentity(Connection $connection): array
    {
        $identity = $connection->selectOne(
            <<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user
SQL
        );

        return [
            'database' => $identity->database_name ?? null,
            'role' => $identity->database_user ?? null,
        ];
    }

    private function assertSafeTestDatabase(Connection $connection): void
    {
        $identity = $connection->selectOne(
            <<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user,
    current_setting('TimeZone') AS timezone
SQL
        );

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertSame(
            config('database.connections.pgsql.database'),
            $identity->database_name ?? null
        );
        $this->assertSame(
            config('database.connections.pgsql.username'),
            $identity->database_user ?? null
        );
        $this->assertMatchesRegularExpression(
            '/(?:^|[_-])(test|testing)(?:[_-]|$)/i',
            (string) ($identity->database_name ?? '')
        );
        $this->assertSame('UTC', $identity->timezone ?? null);
        $this->assertSame(
            32,
            $connection->table('migrations')->count()
        );
    }
}

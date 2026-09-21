<?php

namespace Tests\Feature\Concurrency;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class PostgresProcessBarrierTest extends TestCase
{
    use ResetsDedicatedTestDatabase;

    public function test_ready_go_barrier_proves_worker_is_blocked_by_exact_parent_postgresql_backend(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $barrier = null;

        DB::beginTransaction();

        try {
            User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'lock_user',
                    'user_id' => $user->id,
                ]);

            $ready =
                $barrier->awaitReady();

            $this->assertSame(
                'sewaellf_educore_test',
                $ready['database'],
            );

            $this->assertSame(
                'sewaellf_educore_Admin',
                $ready['user'],
            );

            $this->assertGreaterThan(
                0,
                $ready['pid'],
            );

            $barrier->release();

            $wait =
                $barrier
                    ->awaitBlockedByCurrentConnection(
                        $ready['pid']
                    );

            $this->assertSame(
                'Lock',
                $wait['wait_event_type'],
            );

            $this->assertTrue(
                $wait['blocked_by_parent'],
            );

            $this->assertSame(
                $ready['pid'],
                $wait['child_pid'],
            );

            $this->assertGreaterThan(
                0,
                $wait['parent_pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'success',
                $result['result'] ?? null,
                "STDOUT:\n"
                .($result['_stdout'] ?? '')
                ."\nSTDERR:\n"
                .($result['_stderr'] ?? ''),
            );

            $this->assertSame(
                'lock_user',
                $result['action'] ?? null,
            );

            $this->assertSame(
                $user->id,
                $result['data']['id']
                    ?? null,
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }
}

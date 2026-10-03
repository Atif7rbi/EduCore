<?php

namespace Tests\Feature\Concurrency;

use App\Application\Exceptions\InvalidPasswordResetToken;
use App\Application\Exceptions\TeacherProvisioningIdentityConflict;
use App\Application\Identity\ProvisionTeacher;
use App\Application\Identity\RedeemPasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class AdminTeacherProvisioningConcurrencyTest extends TestCase
{
    use ResetsDedicatedTestDatabase;

    public function test_same_setup_token_cannot_be_redeemed_concurrently_twice(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $teacher = app(
            ProvisionTeacher::class
        )->execute(
            actorUserId: $admin->id,
            name: 'Concurrent Setup Teacher',
            email: 'setup-race@example.test',
        );

        $token = Password::broker()
            ->createToken($teacher);

        $firstPassword =
            'EduCore!FirstSetup2026';

        $secondPassword =
            'EduCore!SecondSetup2026';

        $barrier = null;

        DB::beginTransaction();

        try {
            $parentUser = app(
                RedeemPasswordResetToken::class
            )->execute(
                email: $teacher->email,
                token: $token,
                password: $firstPassword,
            );

            $this->assertSame(
                'active',
                $parentUser->status,
            );

            $barrier =
                PostgresProcessBarrier::start(
                    [
                        'action' => 'redeem_password_reset_token',
                        'email' => $teacher->email,
                        'token' => $token,
                        'password' => $secondPassword,
                    ],
                    'phase_g_concurrency_worker.php',
                );

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            /*
             * The second redemption must block before
             * it can independently validate/consume the
             * same token.
             */
            $wait = $barrier
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

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
                "STDOUT:\n"
                .($result['_stdout'] ?? '')
                ."\nSTDERR:\n"
                .($result['_stderr'] ?? ''),
            );

            $this->assertSame(
                InvalidPasswordResetToken::class,
                $result['class'] ?? null,
            );

            $teacher->refresh();

            $this->assertSame(
                'active',
                $teacher->status,
            );

            $this->assertTrue(
                Hash::check(
                    $firstPassword,
                    $teacher->password,
                )
            );

            $this->assertFalse(
                Hash::check(
                    $secondPassword,
                    $teacher->password,
                )
            );

            $this->assertSame(
                0,
                DB::table(
                    'password_reset_tokens'
                )
                    ->where(
                        'email',
                        $teacher->email,
                    )
                    ->count(),
            );

            $this->assertNotNull(
                DB::table(
                    'teacher_account_provisionings'
                )
                    ->where(
                        'teacher_user_id',
                        $teacher->id,
                    )
                    ->value(
                        'setup_completed_at'
                    ),
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

    public function test_concurrent_case_insensitive_duplicate_email_is_serialized_by_postgresql_unique_identity(): void
    {
        $firstAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $secondAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $email =
            'phase-g-race@example.test';

        $barrier = null;

        DB::beginTransaction();

        try {
            $parentTeacher = app(
                ProvisionTeacher::class
            )->execute(
                actorUserId: $firstAdmin->id,
                name: 'Parent Teacher',
                email: strtoupper($email),
            );

            $this->assertSame(
                $email,
                $parentTeacher->email,
            );

            $this->assertSame(
                'teacher',
                $parentTeacher->role,
            );

            $this->assertSame(
                'disabled',
                $parentTeacher->status,
            );

            $barrier =
                PostgresProcessBarrier::start(
                    [
                        'action' => 'provision_teacher',
                        'actor_user_id' => $secondAdmin->id,
                        'name' => 'Concurrent Teacher',
                        'email' => '  '.$email.'  ',
                    ],
                    'phase_g_concurrency_worker.php',
                );

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

            /*
             * The child cannot see the parent's
             * uncommitted User row. It reaches INSERT,
             * where PostgreSQL's LOWER(email) unique
             * index must serialize it behind the exact
             * parent backend.
             */
            $wait = $barrier
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

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
                "STDOUT:\n"
                .($result['_stdout'] ?? '')
                ."\nSTDERR:\n"
                .($result['_stderr'] ?? ''),
            );

            $this->assertSame(
                TeacherProvisioningIdentityConflict::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                '23505',
                $result['sql_state'] ?? null,
            );

            $this->assertSame(
                1,
                User::query()
                    ->whereRaw(
                        'LOWER(email) = ?',
                        [$email],
                    )
                    ->count(),
            );

            $storedTeacher = User::query()
                ->whereRaw(
                    'LOWER(email) = ?',
                    [$email],
                )
                ->firstOrFail();

            $this->assertSame(
                $parentTeacher->id,
                $storedTeacher->id,
            );

            $this->assertSame(
                'teacher',
                $storedTeacher->role,
            );

            $this->assertSame(
                'disabled',
                $storedTeacher->status,
            );

            $this->assertSame(
                1,
                DB::table(
                    'teacher_account_provisionings'
                )
                    ->where(
                        'teacher_user_id',
                        $storedTeacher->id,
                    )
                    ->count(),
            );

            $this->assertSame(
                $firstAdmin->id,
                DB::table(
                    'teacher_account_provisionings'
                )
                    ->where(
                        'teacher_user_id',
                        $storedTeacher->id,
                    )
                    ->value(
                        'provisioned_by_user_id'
                    ),
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

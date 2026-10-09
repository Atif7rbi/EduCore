<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherAccountProvisioningIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_insert_rejects_non_admin_actor(): void
    {
        $actor = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $teacher = $this->disabledTeacher();

        $this->assertIntegrityViolation(
            fn () => $this->insertProvisioning(
                $teacher,
                $actor,
            )
        );

        $this->assertProvisioningMissing($teacher);
    }

    public function test_direct_insert_rejects_disabled_admin_actor(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'disabled',
        ]);

        $teacher = $this->disabledTeacher();

        $this->assertIntegrityViolation(
            fn () => $this->insertProvisioning(
                $teacher,
                $admin,
            )
        );

        $this->assertProvisioningMissing($teacher);
    }

    public function test_direct_insert_rejects_non_teacher_target(): void
    {
        $admin = $this->activeAdmin();

        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'disabled',
        ]);

        $this->assertIntegrityViolation(
            fn () => $this->insertProvisioning(
                $student,
                $admin,
            )
        );

        $this->assertProvisioningMissing($student);
    }

    public function test_direct_insert_cannot_start_already_completed(): void
    {
        $admin = $this->activeAdmin();
        $teacher = $this->disabledTeacher();

        $this->assertIntegrityViolation(
            fn () => $this->insertProvisioning(
                $teacher,
                $admin,
                Carbon::now('UTC'),
            )
        );

        $this->assertProvisioningMissing($teacher);
    }

    public function test_provisioning_actor_provenance_is_immutable(): void
    {
        $admin = $this->activeAdmin();

        $secondAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $teacher = $this->disabledTeacher();

        $this->insertProvisioning(
            $teacher,
            $admin,
        );

        $this->assertIntegrityViolation(
            fn () => DB::table(
                'teacher_account_provisionings'
            )
                ->where(
                    'teacher_user_id',
                    $teacher->id,
                )
                ->update([
                    'provisioned_by_user_id' => $secondAdmin->id,
                ])
        );

        $this->assertDatabaseHas(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
                'provisioned_by_user_id' => $admin->id,
            ],
        );
    }

    public function test_teacher_identity_is_immutable(): void
    {
        $admin = $this->activeAdmin();

        $teacher = $this->disabledTeacher();

        $otherTeacher = $this->disabledTeacher();

        $this->insertProvisioning(
            $teacher,
            $admin,
        );

        $this->assertIntegrityViolation(
            fn () => DB::table(
                'teacher_account_provisionings'
            )
                ->where(
                    'teacher_user_id',
                    $teacher->id,
                )
                ->update([
                    'teacher_user_id' => $otherTeacher->id,
                ])
        );

        $this->assertDatabaseHas(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
            ],
        );
    }

    public function test_created_at_is_immutable(): void
    {
        $admin = $this->activeAdmin();
        $teacher = $this->disabledTeacher();

        $createdAt = Carbon::now('UTC');

        $this->insertProvisioning(
            $teacher,
            $admin,
            null,
            $createdAt,
        );

        $this->assertIntegrityViolation(
            fn () => DB::table(
                'teacher_account_provisionings'
            )
                ->where(
                    'teacher_user_id',
                    $teacher->id,
                )
                ->update([
                    'created_at' => $createdAt
                        ->copy()
                        ->addSecond(),
                ])
        );
    }

    public function test_setup_completion_requires_active_teacher(): void
    {
        $admin = $this->activeAdmin();
        $teacher = $this->disabledTeacher();

        $this->insertProvisioning(
            $teacher,
            $admin,
        );

        $this->assertIntegrityViolation(
            fn () => DB::table(
                'teacher_account_provisionings'
            )
                ->where(
                    'teacher_user_id',
                    $teacher->id,
                )
                ->update([
                    'setup_completed_at' => Carbon::now('UTC'),
                ])
        );

        $this->assertDatabaseHas(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
                'setup_completed_at' => null,
            ],
        );
    }

    public function test_setup_completion_succeeds_once_then_becomes_immutable(): void
    {
        $admin = $this->activeAdmin();
        $teacher = $this->disabledTeacher();

        $this->insertProvisioning(
            $teacher,
            $admin,
        );

        DB::table('users')
            ->where('id', $teacher->id)
            ->update([
                'status' => 'active',
            ]);

        $completedAt = Carbon::now('UTC');

        $updated = DB::table(
            'teacher_account_provisionings'
        )
            ->where(
                'teacher_user_id',
                $teacher->id,
            )
            ->update([
                'setup_completed_at' => $completedAt,
            ]);

        $this->assertSame(1, $updated);

        $this->assertDatabaseHas(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
            ],
        );

        $this->assertNotNull(
            DB::table(
                'teacher_account_provisionings'
            )
                ->where(
                    'teacher_user_id',
                    $teacher->id,
                )
                ->value('setup_completed_at')
        );

        $this->assertIntegrityViolation(
            fn () => DB::table(
                'teacher_account_provisionings'
            )
                ->where(
                    'teacher_user_id',
                    $teacher->id,
                )
                ->update([
                    'setup_completed_at' => $completedAt
                        ->copy()
                        ->addSecond(),
                ])
        );
    }

    public function test_provisioning_history_cannot_be_deleted_directly(): void
    {
        $admin = $this->activeAdmin();
        $teacher = $this->disabledTeacher();

        $this->insertProvisioning(
            $teacher,
            $admin,
        );

        $this->assertIntegrityViolation(
            fn () => DB::table(
                'teacher_account_provisionings'
            )
                ->where(
                    'teacher_user_id',
                    $teacher->id,
                )
                ->delete()
        );

        $this->assertDatabaseHas(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
            ],
        );
    }

    private function activeAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function disabledTeacher(): User
    {
        return User::factory()->create([
            'role' => 'teacher',
            'status' => 'disabled',
        ]);
    }

    private function insertProvisioning(
        User $teacher,
        User $admin,
        ?Carbon $setupCompletedAt = null,
        ?Carbon $createdAt = null,
    ): void {
        DB::table(
            'teacher_account_provisionings'
        )->insert([
            'teacher_user_id' => $teacher->id,
            'provisioned_by_user_id' => $admin->id,
            'setup_completed_at' => $setupCompletedAt,
            'created_at' => $createdAt ?? Carbon::now('UTC'),
        ]);
    }

    private function assertProvisioningMissing(
        User $teacher,
    ): void {
        $this->assertDatabaseMissing(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
            ],
        );
    }

    private function assertIntegrityViolation(
        callable $operation,
    ): void {
        try {
            /*
             * RefreshDatabase already owns the outer test
             * transaction. This nested transaction creates a
             * PostgreSQL savepoint, allowing the test to recover
             * after the expected statement-level violation.
             */
            DB::transaction(
                function () use ($operation): void {
                    $operation();
                }
            );
        } catch (QueryException $exception) {
            $this->assertSame(
                '23514',
                $exception->errorInfo[0] ?? null,
                'Expected PostgreSQL check/integrity SQLSTATE 23514.',
            );

            return;
        }

        $this->fail(
            'Expected PostgreSQL integrity violation was not raised.'
        );
    }
}

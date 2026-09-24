<?php

namespace Tests\Feature;

use App\Application\Identity\ProvisionTeacher;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class AdminTeacherProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_provisions_disabled_teacher_and_sends_setup_link(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($admin)
            ->postJson('/api/admin/teachers', [
                'name' => '  Teacher One  ',
                'email' => '  TEACHER.ONE@EXAMPLE.COM  ',
            ])
            ->assertCreated()
            ->assertJsonPath(
                'data.teacher.name',
                'Teacher One',
            )
            ->assertJsonPath(
                'data.teacher.email',
                'teacher.one@example.com',
            )
            ->assertJsonPath(
                'data.teacher.role',
                'teacher',
            )
            ->assertJsonPath(
                'data.teacher.status',
                'disabled',
            )
            ->assertJsonPath(
                'data.setup.delivery',
                'sent',
            );

        $teacher = User::query()
            ->whereKey(
                $response->json('data.teacher.id')
            )
            ->firstOrFail();

        $this->assertTrue(
            $teacher->isTeacher()
        );

        $this->assertFalse(
            $teacher->isActive()
        );

        $this->assertDatabaseHas(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
                'provisioned_by_user_id' => $admin->id,
                'setup_completed_at' => null,
            ],
        );

        Notification::assertSentTo(
            $teacher,
            ResetPassword::class,
        );
    }

    public function test_existing_case_insensitive_identity_conflicts_without_role_conversion(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $existing = User::factory()->create([
            'email' => 'existing@example.com',
            'role' => 'student',
        ]);

        $this
            ->actingAs($admin)
            ->postJson('/api/admin/teachers', [
                'name' => 'Existing User',
                'email' => 'EXISTING@EXAMPLE.COM',
            ])
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'teacher_identity_conflict',
            );

        $this->assertSame(
            'student',
            $existing->refresh()->role,
        );

        $this->assertSame(
            1,
            User::query()
                ->whereRaw(
                    'LOWER(email) = ?',
                    ['existing@example.com'],
                )
                ->count(),
        );
    }

    public function test_non_admin_cannot_provision_teacher(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this
            ->actingAs($teacher)
            ->postJson('/api/admin/teachers', [
                'name' => 'Another Teacher',
                'email' => 'another@example.com',
            ])
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'management_forbidden',
            );
    }

    public function test_provisioning_clears_orphaned_reset_token_before_reusing_email_identity(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $email =
            'reused.identity@example.test';

        $staleToken =
            'stale-reset-token-before-teacher-provisioning';

        DB::table(
            'password_reset_tokens'
        )->insert([
            'email' => $email,
            'token' => Hash::make(
                $staleToken
            ),
            'created_at' => now(),
        ]);

        $this->assertSame(
            1,
            DB::table(
                'password_reset_tokens'
            )
                ->where(
                    'email',
                    $email,
                )
                ->count(),
        );

        $teacher = app(
            ProvisionTeacher::class
        )->execute(
            actorUserId: $admin->id,
            name: 'Reused Identity Teacher',
            email: strtoupper($email),
        );

        $this->assertSame(
            $email,
            $teacher->email,
        );

        $this->assertSame(
            0,
            DB::table(
                'password_reset_tokens'
            )
                ->where(
                    'email',
                    $email,
                )
                ->count(),
        );

        $this->assertFalse(
            Password::broker()->tokenExists(
                $teacher,
                $staleToken,
            )
        );
    }

    public function test_service_revalidates_admin_inside_transaction(): void
    {
        $teacherActor = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(ProvisionTeacher::class)->execute(
            actorUserId: $teacherActor->id,
            name: 'Rejected Teacher',
            email: 'rejected@example.com',
        );
    }

    public function test_disabled_admin_is_rejected_by_service(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'disabled',
        ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(ProvisionTeacher::class)->execute(
            actorUserId: $admin->id,
            name: 'Rejected Teacher',
            email: 'rejected-disabled@example.com',
        );
    }

    public function test_client_cannot_select_role_status_or_password(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this
            ->actingAs($admin)
            ->postJson('/api/admin/teachers', [
                'name' => 'Teacher Two',
                'email' => 'teacher.two@example.com',
                'role' => 'admin',
                'status' => 'active',
                'password' => 'SharedPassword!2026',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'role',
                        'status',
                        'password',
                    ],
                ],
            ]);
    }

    public function test_valid_initial_setup_token_activates_provisioned_teacher_atomically(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($admin)
            ->postJson('/api/admin/teachers', [
                'name' => 'Teacher Setup',
                'email' => 'teacher.setup@example.com',
            ])
            ->assertCreated();

        $teacher = User::query()
            ->whereKey(
                $response->json(
                    'data.teacher.id'
                )
            )
            ->firstOrFail();

        $token = null;

        Notification::assertSentTo(
            $teacher,
            ResetPassword::class,
            function (
                ResetPassword $notification
            ) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        $password =
            'EduCore!Teacher2026';

        $this->postJson(
            '/auth/reset-password',
            [
                'token' => $token,
                'email' => $teacher->email,
                'password' => $password,
                'password_confirmation' => $password,
            ],
        )
            ->assertOk()
            ->assertJsonPath(
                'data.reset',
                true,
            );

        $teacher->refresh();

        $this->assertTrue(
            $teacher->isActive()
        );

        $this->assertTrue(
            Hash::check(
                $password,
                $teacher->password,
            )
        );

        $provisioning = DB::table(
            'teacher_account_provisionings'
        )
            ->where(
                'teacher_user_id',
                $teacher->id,
            )
            ->first();

        $this->assertNotNull(
            $provisioning
        );

        $this->assertNotNull(
            $provisioning
                ->setup_completed_at
        );
    }

    public function test_ordinary_password_reset_does_not_activate_disabled_teacher_without_pending_setup(): void
    {
        Notification::fake();

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'disabled',
            'email' => 'disabled.teacher@example.com',
        ]);

        $this->postJson(
            '/auth/forgot-password',
            [
                'email' => $teacher->email,
            ],
        )->assertOk();

        $token = null;

        Notification::assertSentTo(
            $teacher,
            ResetPassword::class,
            function (
                ResetPassword $notification
            ) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        $password =
            'EduCore!Disabled2026';

        $this->postJson(
            '/auth/reset-password',
            [
                'token' => $token,
                'email' => $teacher->email,
                'password' => $password,
                'password_confirmation' => $password,
            ],
        )->assertOk();

        $teacher->refresh();

        $this->assertSame(
            'disabled',
            $teacher->status,
        );

        $this->assertTrue(
            Hash::check(
                $password,
                $teacher->password,
            )
        );
    }

    public function test_completed_setup_then_admin_disable_is_not_reactivated_by_later_password_reset(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $teacher = app(
            ProvisionTeacher::class
        )->execute(
            actorUserId: $admin->id,
            name: 'Historical Setup Teacher',
            email: 'historical.setup@example.test',
        );

        $setupToken = Password::broker()
            ->createToken($teacher);

        $setupPassword =
            'EduCore!InitialSetup2026';

        $this->postJson(
            '/auth/reset-password',
            [
                'token' => $setupToken,
                'email' => $teacher->email,
                'password' => $setupPassword,
                'password_confirmation' => $setupPassword,
            ],
        )->assertOk();

        $teacher->refresh();

        $this->assertSame(
            'active',
            $teacher->status,
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

        /*
         * Simulates a later authoritative Admin disable.
         * User status is mutable while role remains frozen.
         */
        DB::table('users')
            ->where('id', $teacher->id)
            ->update([
                'status' => 'disabled',
            ]);

        $teacher->refresh();

        $resetToken = Password::broker()
            ->createToken($teacher);

        $laterPassword =
            'EduCore!LaterReset2026';

        $this->postJson(
            '/auth/reset-password',
            [
                'token' => $resetToken,
                'email' => $teacher->email,
                'password' => $laterPassword,
                'password_confirmation' => $laterPassword,
            ],
        )->assertOk();

        $teacher->refresh();

        $this->assertSame(
            'disabled',
            $teacher->status,
        );

        $this->assertTrue(
            Hash::check(
                $laterPassword,
                $teacher->password,
            )
        );
    }

    public function test_setup_link_delivery_failure_leaves_teacher_disabled_and_pending(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Password::shouldReceive('broker')
            ->once()
            ->andThrow(
                new RuntimeException(
                    'Simulated setup delivery failure.'
                )
            );

        $response = $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers',
                [
                    'name' => 'Delivery Failure Teacher',
                    'email' => 'delivery.failure@example.test',
                ],
            )
            ->assertCreated()
            ->assertJsonPath(
                'data.setup.delivery',
                'failed',
            )
            ->assertJsonPath(
                'data.teacher.status',
                'disabled',
            );

        $teacher = User::query()
            ->whereKey(
                $response->json(
                    'data.teacher.id'
                )
            )
            ->firstOrFail();

        $this->assertSame(
            'disabled',
            $teacher->status,
        );

        $this->assertDatabaseHas(
            'teacher_account_provisionings',
            [
                'teacher_user_id' => $teacher->id,
                'provisioned_by_user_id' => $admin->id,
                'setup_completed_at' => null,
            ],
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_student_with_learner_profile_has_learner_capability(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->getJson('/api/attempts/'.Str::uuid())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_active_student_without_learner_profile_has_no_learner_identity(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->postJson('/api/practice-activities/'.Str::uuid().'/attempts')
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    public function test_teacher_has_no_management_capability(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->postJson('/api/curriculum-versions/'.Str::uuid().'/publish')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'management_forbidden');
    }

    public function test_admin_has_management_capability(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->postJson('/api/curriculum-versions/'.Str::uuid().'/publish')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_disabled_student_is_rejected_from_learner_routes(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'disabled',
        ]);

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this
            ->actingAs($user)
            ->getJson('/api/attempts/'.Str::uuid())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_disabled_teacher_is_rejected_before_role_authorization(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'status' => 'disabled',
        ]);

        $this
            ->actingAs($user)
            ->postJson('/api/curriculum-versions/'.Str::uuid().'/publish')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_disabled_admin_is_rejected_from_management_routes(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'disabled',
        ]);

        $this
            ->actingAs($user)
            ->postJson('/api/curriculum-versions/'.Str::uuid().'/publish')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_admin_role_cannot_change_after_provisioning(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->expectException(
            QueryException::class
        );

        $user->forceFill([
            'role' => 'teacher',
        ])->save();
    }

    public function test_teacher_role_cannot_change_after_provisioning(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->expectException(
            QueryException::class
        );

        $user->forceFill([
            'role' => 'admin',
        ])->save();
    }

    public function test_student_role_cannot_change_after_provisioning_even_via_direct_database_update(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->expectException(
            QueryException::class
        );

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'role' => 'teacher',
            ]);
    }

    public function test_status_can_change_after_provisioning_without_changing_role(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'status' => 'disabled',
            ]);

        $user->refresh();

        $this->assertSame(
            'student',
            $user->role
        );

        $this->assertSame(
            'disabled',
            $user->status
        );
    }

    public function test_teacher_cannot_use_student_routes(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->getJson(
                '/api/attempts/'.Str::uuid()
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'student_forbidden'
            );
    }

    public function test_me_exposes_current_role_and_status(): void
    {
        $user = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.role', 'teacher')
            ->assertJsonPath('data.user.status', 'active');
    }
}

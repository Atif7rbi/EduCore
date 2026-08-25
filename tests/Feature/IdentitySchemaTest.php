<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_learner_profile_use_uuid_primary_keys(): void
    {
        $user = User::factory()->create();

        $profile = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->assertIsString($user->id);
        $this->assertIsString($profile->id);
        $this->assertSame(36, strlen($user->id));
        $this->assertSame(36, strlen($profile->id));
    }

    public function test_user_has_at_most_one_learner_profile(): void
    {
        $user = User::factory()->create();

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);
    }

    public function test_email_uniqueness_is_case_insensitive(): void
    {
        User::factory()->create([
            'email' => 'Student@Example.com',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create([
            'email' => 'student@example.com',
        ]);
    }

    public function test_user_role_accepts_v1_roles(): void
    {
        foreach (['student', 'teacher', 'admin'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
            ]);

            $this->assertSame($role, $user->role);
        }
    }

    public function test_user_role_rejects_invalid_value(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create([
            'role' => 'invalid',
        ]);
    }

    public function test_user_status_rejects_invalid_value(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create([
            'status' => 'invalid',
        ]);
    }

    public function test_user_delete_is_restricted_when_learner_profile_exists(): void
    {
        $user = User::factory()->create();

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('users')
            ->where('id', $user->id)
            ->delete();
    }
}

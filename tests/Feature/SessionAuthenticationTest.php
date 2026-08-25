<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_and_read_me_through_session(): void
    {
        $user = User::factory()->create([
            'email' => 'student@example.test',
            'password' => Hash::make('secret-password'),
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this
            ->postJson('/auth/login', [
                'email' => 'student@example.test',
                'password' => 'secret-password',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath(
                'data.user.learner_profile_id',
                $learner->id
            );

        $this
            ->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'email' => 'student@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $this
            ->postJson('/auth/login', [
                'email' => 'student@example.test',
                'password' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'invalid_credentials'
            );
    }

    public function test_disabled_user_cannot_establish_session(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'disabled',
        ]);

        $this
            ->postJson('/auth/login', [
                'email' => 'disabled@example.test',
                'password' => 'secret-password',
            ])
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'account_disabled'
            );

        $this
            ->getJson('/auth/me')
            ->assertStatus(401);
    }

    public function test_logout_invalidates_authenticated_session(): void
    {
        User::factory()->create([
            'email' => 'student@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $this->postJson('/auth/login', [
            'email' => 'student@example.test',
            'password' => 'secret-password',
        ])->assertOk();

        $this->getJson('/auth/me')
            ->assertOk();

        $this->postJson('/auth/logout')
            ->assertOk()
            ->assertJsonPath(
                'data.authenticated',
                false
            );

        $this->getJson('/auth/me')
            ->assertStatus(401);
    }

    public function test_session_stops_working_after_user_is_disabled(): void
    {
        $user = User::factory()->create([
            'email' => 'student@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        $this->postJson('/auth/login', [
            'email' => 'student@example.test',
            'password' => 'secret-password',
        ])->assertOk();

        $user->forceFill([
            'status' => 'disabled',
        ])->save();

        $this
            ->getJson('/auth/me')
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'account_disabled'
            );
    }
}

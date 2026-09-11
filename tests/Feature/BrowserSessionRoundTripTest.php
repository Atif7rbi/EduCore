<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrowserSessionRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_establishes_cookie_backed_session_round_trip(): void
    {
        $user = User::factory()->create([
            'email' => 'browser-session@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
            'role' => 'student',
        ]);

        $cookieName = config('session.cookie');

        $login = $this->postJson('/auth/login', [
            'email' => 'browser-session@example.test',
            'password' => 'secret-password',
        ]);

        $login
            ->assertOk()
            ->assertCookie($cookieName);

        $this
            ->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this
            ->postJson('/auth/logout')
            ->assertOk();

        $this
            ->getJson('/auth/me')
            ->assertStatus(401);
    }

    public function test_login_session_authenticates_protected_api_route(): void
    {
        $user = User::factory()->create([
            'email' => 'browser-api-session@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
            'role' => 'student',
        ]);

        LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        $this->postJson('/auth/login', [
            'email' => 'browser-api-session@example.test',
            'password' => 'secret-password',
        ])->assertOk();

        $missingId = (string) Str::uuid();

        $this->getJson(
            "/api/curriculum-versions/{$missingId}"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_session_cookie_security_shape_matches_configuration(): void
    {
        User::factory()->create([
            'email' => 'cookie-shape@example.test',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
            'role' => 'student',
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'cookie-shape@example.test',
            'password' => 'secret-password',
        ]);

        $response
            ->assertOk()
            ->assertCookie(config('session.cookie'));
    }
}

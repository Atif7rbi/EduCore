<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_response_contains_baseline_security_headers(): void
    {
        $response = $this->get('/');

        $response
            ->assertHeader(
                'X-Content-Type-Options',
                'nosniff'
            )
            ->assertHeader(
                'X-Frame-Options',
                'DENY'
            )
            ->assertHeader(
                'Referrer-Policy',
                'strict-origin-when-cross-origin'
            )
            ->assertHeader(
                'Permissions-Policy',
                'camera=(), microphone=(), geolocation=()'
            );
    }

    public function test_api_response_contains_baseline_security_headers(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader(
                'X-Content-Type-Options',
                'nosniff'
            )
            ->assertHeader(
                'X-Frame-Options',
                'DENY'
            );
    }

    public function test_login_is_rate_limited_after_five_attempts_per_email_and_ip(): void
    {
        User::factory()->create([
            'email' =>
                'throttle@example.test',
            'password' =>
                bcrypt('correct-password'),
            'status' => 'active',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(
                '/auth/login',
                [
                    'email' =>
                        'throttle@example.test',
                    'password' =>
                        'wrong-password',
                ]
            )->assertStatus(422);
        }

        $this->postJson(
            '/auth/login',
            [
                'email' =>
                    'throttle@example.test',
                'password' =>
                    'wrong-password',
            ]
        )->assertStatus(429);
    }

    public function test_login_rate_limit_key_normalizes_email_case(): void
    {
        User::factory()->create([
            'email' =>
                'case@example.test',
            'password' =>
                bcrypt('correct-password'),
            'status' => 'active',
        ]);

        $emails = [
            'CASE@example.test',
            'case@example.test',
            'Case@Example.Test',
            'CASE@EXAMPLE.TEST',
            'case@EXAMPLE.test',
        ];

        foreach ($emails as $email) {
            $this->postJson(
                '/auth/login',
                [
                    'email' => $email,
                    'password' =>
                        'wrong-password',
                ]
            )->assertStatus(422);
        }

        $this->postJson(
            '/auth/login',
            [
                'email' =>
                    'case@example.test',
                'password' =>
                    'wrong-password',
            ]
        )->assertStatus(429);
    }

    public function test_successful_login_still_regenerates_session(): void
    {
        $user = User::factory()->create([
            'email' =>
                'session-regeneration@example.test',
            'password' =>
                bcrypt('correct-password'),
            'status' => 'active',
        ]);

        $response = $this->postJson(
            '/auth/login',
            [
                'email' => $user->email,
                'password' =>
                    'correct-password',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.user.id',
                $user->id
            );

        $this->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath(
                'data.user.id',
                $user->id
            );
    }
}

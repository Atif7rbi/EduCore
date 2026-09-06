<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_and_unknown_emails_receive_the_same_generic_response(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $expectedMessage =
            'If an account exists for this email, a password reset link has been sent.';

        $this->postJson('/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk()
            ->assertJsonPath('data.message', $expectedMessage);

        Notification::assertSentTo($user, ResetPassword::class);

        $this->postJson('/auth/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()
            ->assertJsonPath('data.message', $expectedMessage);
    }

    public function test_reset_request_normalizes_email_case_and_whitespace(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->postJson('/auth/forgot-password', [
            'email' => '  ADMIN@EXAMPLE.COM  ',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_valid_reset_token_changes_password_and_revokes_existing_sessions(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->actingAs($user);

        $this->getJson('/auth/me')->assertOk();

        $sessionId = session()->getId();

        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
            'user_id' => $user->id,
        ]);

        auth()->logout();

        $this->postJson('/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        $token = null;

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        $newPassword = 'EduCore!Reset2026';

        $this->postJson('/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk()
            ->assertJsonPath('data.reset', true);

        $user->refresh();

        $this->assertTrue(Hash::check($newPassword, $user->password));

        $this->assertDatabaseMissing('sessions', [
            'user_id' => $user->id,
        ]);
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->postJson('/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        $token = null;

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'EduCore!Reset2026',
            'password_confirmation' => 'EduCore!Reset2026',
        ];

        $this->postJson('/auth/reset-password', $payload)
            ->assertOk();

        $this->postJson('/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_password_reset');
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->postJson('/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'EduCore!Reset2026',
            'password_confirmation' => 'EduCore!Reset2026',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_password_reset');
    }

    public function test_weak_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $this->postJson('/auth/reset-password', [
            'token' => 'any-token',
            'email' => $user->email,
            'password' => 'weak-password',
            'password_confirmation' => 'weak-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}

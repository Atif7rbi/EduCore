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

    public function test_known_email_receives_reset_notification_without_account_enumeration(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);

        $response = $this->postJson('/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonPath(
                'data.message',
                'If an account exists for this email, a password reset link has been sent.',
            );

        Notification::assertSentTo($user, ResetPassword::class);

        $unknownResponse = $this->postJson('/auth/forgot-password', [
            'email' => 'missing@example.com',
        ]);

        $unknownResponse->assertOk()
            ->assertJsonPath(
                'data.message',
                'If an account exists for this email, a password reset link has been sent.',
            );
    }

    public function test_valid_reset_token_changes_password(): void
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
}

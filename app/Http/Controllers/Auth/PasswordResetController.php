<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class PasswordResetController extends Controller
{
    private const GENERIC_RESET_LINK_MESSAGE =
        'If an account exists for this email, a password reset link has been sent.';

    public function requestResetLink(Request $request): JsonResponse
    {
        $request->merge([
            'email' => $this->normalizedEmail($request),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        try {
            Password::broker()->sendResetLink([
                'email' => $validated['email'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Password reset delivery failed.', [
                'exception' => $exception::class,
            ]);
        }

        return ApiResponse::success([
            'message' => self::GENERIC_RESET_LINK_MESSAGE,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->merge([
            'email' => $this->normalizedEmail($request),
        ]);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    DB::table('sessions')
                        ->where('user_id', $user->id)
                        ->delete();
                });

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return ApiResponse::success([
                'reset' => true,
            ]);
        }

        return ApiResponse::error(
            'invalid_password_reset',
            'The password reset link is invalid or has expired.',
            422,
        );
    }

    private function normalizedEmail(Request $request): string
    {
        return Str::lower(
            trim((string) $request->input('email', ''))
        );
    }
}

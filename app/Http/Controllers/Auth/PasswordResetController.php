<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetController extends Controller
{
    public function requestResetLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $status = Password::broker()->sendResetLink([
            'email' => $validated['email'],
        ]);

        if ($status !== Password::RESET_LINK_SENT && $status !== Password::INVALID_USER) {
            return ApiResponse::error(
                'password_reset_unavailable',
                'Unable to send a password reset link right now.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return ApiResponse::success([
            'message' => 'If an account exists for this email, a password reset link has been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
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
                'password_confirmation' => $validated['password_confirmation'],
                'token' => $validated['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

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
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}

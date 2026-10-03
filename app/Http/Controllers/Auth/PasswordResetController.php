<?php

namespace App\Http\Controllers\Auth;

use App\Application\Exceptions\InvalidPasswordResetToken;
use App\Application\Identity\RedeemPasswordResetToken;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class PasswordResetController extends Controller
{
    private const GENERIC_RESET_LINK_MESSAGE =
        'If an account exists for this email, a password reset link has been sent.';

    public function requestResetLink(
        Request $request,
    ): JsonResponse {
        $request->merge([
            'email' => $this->normalizedEmail(
                $request
            ),
        ]);

        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email',
            ],
        ]);

        try {
            Password::broker()->sendResetLink([
                'email' => $validated['email'],
            ]);
        } catch (Throwable $exception) {
            Log::warning(
                'Password reset delivery failed.',
                [
                    'exception' => $exception::class,
                ],
            );
        }

        return ApiResponse::success([
            'message' => self::GENERIC_RESET_LINK_MESSAGE,
        ]);
    }

    public function reset(
        Request $request,
        RedeemPasswordResetToken $redeemPasswordResetToken,
    ): JsonResponse {
        $request->merge([
            'email' => $this->normalizedEmail(
                $request
            ),
        ]);

        $validated = $request->validate([
            'token' => [
                'required',
                'string',
            ],
            'email' => [
                'required',
                'string',
                'email',
            ],
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

        try {
            $user =
                $redeemPasswordResetToken
                    ->execute(
                        email: $validated['email'],
                        token: $validated['token'],
                        password: $validated['password'],
                    );
        } catch (
            InvalidPasswordResetToken $exception
        ) {
            return ApiResponse::error(
                'invalid_password_reset',
                'The password reset link is invalid or has expired.',
                422,
            );
        }

        /*
         * Durable credential/status/token changes have
         * committed before observers receive this event.
         */
        event(new PasswordReset($user));

        return ApiResponse::success([
            'reset' => true,
        ]);
    }

    private function normalizedEmail(
        Request $request,
    ): string {
        return Str::lower(
            trim(
                (string) $request->input(
                    'email',
                    '',
                )
            )
        );
    }
}

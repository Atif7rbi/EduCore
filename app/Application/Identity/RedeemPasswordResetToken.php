<?php

namespace App\Application\Identity;

use App\Application\Exceptions\InvalidPasswordResetToken;
use App\Application\Support\TransactionManager;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

class RedeemPasswordResetToken
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(
        string $email,
        string $token,
        string $password,
    ): User {
        $normalizedEmail = Str::lower(
            trim($email)
        );

        return $this->transactions->run(
            function () use (
                $normalizedEmail,
                $token,
                $password,
            ): User {
                /*
                 * Canonical credential-establishment lock order:
                 *
                 * User
                 * -> password reset token
                 * -> TeacherAccountProvisioning
                 *
                 * Token validation and token consumption occur
                 * under the same PostgreSQL transaction as the
                 * credential/status mutation.
                 */
                $lockedUser = User::query()
                    ->whereRaw(
                        'LOWER(email) = ?',
                        [$normalizedEmail],
                    )
                    ->lockForUpdate()
                    ->first();

                if ($lockedUser === null) {
                    throw new InvalidPasswordResetToken;
                }

                $broker = Password::broker();

                $brokerName = (string) config(
                    'auth.defaults.passwords',
                    'users',
                );

                $tokenTable = config(
                    "auth.passwords.{$brokerName}.table"
                );

                if (
                    ! is_string($tokenTable)
                    || $tokenTable === ''
                ) {
                    throw new RuntimeException(
                        'Password reset token table is not configured.'
                    );
                }

                $tokenRow = DB::table($tokenTable)
                    ->where(
                        'email',
                        $lockedUser
                            ->getEmailForPasswordReset(),
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    $tokenRow === null
                    || ! $broker->tokenExists(
                        $lockedUser,
                        $token,
                    )
                ) {
                    throw new InvalidPasswordResetToken;
                }

                $provisioning = DB::table(
                    'teacher_account_provisionings'
                )
                    ->where(
                        'teacher_user_id',
                        $lockedUser->id,
                    )
                    ->whereNull(
                        'setup_completed_at'
                    )
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'password' => Hash::make(
                        $password
                    ),
                    'remember_token' => Str::random(
                        60
                    ),
                ];

                if ($provisioning !== null) {
                    if (
                        ! $lockedUser->isTeacher()
                        || $lockedUser->status
                            !== 'disabled'
                    ) {
                        throw new RuntimeException(
                            'Pending Teacher setup is inconsistent with User state.'
                        );
                    }

                    $attributes['status'] =
                        'active';
                }

                $lockedUser
                    ->forceFill($attributes)
                    ->save();

                if ($provisioning !== null) {
                    DB::table(
                        'teacher_account_provisionings'
                    )
                        ->where(
                            'teacher_user_id',
                            $lockedUser->id,
                        )
                        ->whereNull(
                            'setup_completed_at'
                        )
                        ->update([
                            'setup_completed_at' => CarbonImmutable::now(
                                'UTC'
                            ),
                        ]);
                }

                DB::table('sessions')
                    ->where(
                        'user_id',
                        $lockedUser->id,
                    )
                    ->delete();

                /*
                 * Single-use consumption is part of this
                 * transaction, not a post-commit broker action.
                 */
                $broker->deleteToken(
                    $lockedUser
                );

                return $lockedUser->refresh();
            }
        );
    }
}

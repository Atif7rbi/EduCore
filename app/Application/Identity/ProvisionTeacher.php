<?php

namespace App\Application\Identity;

use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Exceptions\TeacherProvisioningIdentityConflict;
use App\Application\Support\TransactionManager;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ProvisionTeacher
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(
        string $actorUserId,
        string $name,
        string $email,
    ): User {
        $normalizedName = trim($name);

        $normalizedEmail = Str::lower(
            trim($email)
        );

        try {
            return $this->transactions->run(
                function () use (
                    $actorUserId,
                    $normalizedName,
                    $normalizedEmail,
                ): User {
                    $actor = User::query()
                        ->whereKey($actorUserId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        ! $actor->isAdmin()
                        || ! $actor->isActive()
                    ) {
                        throw (new ModelNotFoundException)
                            ->setModel(
                                User::class,
                                [$actorUserId],
                            );
                    }

                    $existing = User::query()
                        ->whereRaw(
                            'LOWER(email) = ?',
                            [$normalizedEmail],
                        )
                        ->first();

                    if ($existing !== null) {
                        throw new TeacherProvisioningIdentityConflict;
                    }

                    /*
                     * A password-reset token is keyed by email rather
                     * than User UUID. Remove any orphaned historical
                     * token before this normalized identity is reused
                     * for a newly provisioned Teacher.
                     *
                     * This is inside the provisioning transaction so
                     * account creation and stale-token invalidation are
                     * atomic.
                     */
                    DB::table(
                        'password_reset_tokens'
                    )
                        ->where(
                            'email',
                            $normalizedEmail,
                        )
                        ->delete();

                    /*
                     * The bootstrap credential is intentionally
                     * unobservable. It exists only because users.password
                     * is required before credential establishment.
                     */
                    $teacher = User::query()->create([
                        'name' => $normalizedName,
                        'email' => $normalizedEmail,
                        'password' => Hash::make(
                            Str::random(96)
                        ),
                        'status' => 'disabled',
                        'role' => 'teacher',
                    ]);

                    DB::table(
                        'teacher_account_provisionings'
                    )->insert([
                        'teacher_user_id' => $teacher->id,
                        'provisioned_by_user_id' => $actor->id,
                        'setup_completed_at' => null,
                        'created_at' => CarbonImmutable::now(
                            'UTC'
                        ),
                    ]);

                    return $teacher->refresh();
                }
            );
        } catch (
            IntegrityConstraintViolation $exception
        ) {
            /*
             * PostgreSQL case-insensitive users.email uniqueness
             * is the final authority under concurrent provisioning.
             */
            if ($exception->sqlState === '23505') {
                throw new TeacherProvisioningIdentityConflict(
                    $exception
                );
            }

            throw $exception;
        }
    }
}

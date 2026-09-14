<?php

namespace App\Application\Identity;

use App\Application\Support\TransactionManager;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RegisterStudent
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    /**
     * @param array{
     *     name: string,
     *     email: string,
     *     password: string
     * } $attributes
     */
    public function register(array $attributes): User
    {
        return $this->transactions->run(
            function () use ($attributes): User {
                $user = User::query()->create([
                    'name' => $attributes['name'],
                    'email' => $attributes['email'],
                    'password' => Hash::make(
                        $attributes['password']
                    ),
                    'status' => 'active',
                    'role' => 'student',
                ]);

                $learnerProfile =
                    LearnerProfile::query()->create([
                        'user_id' => $user->id,
                    ]);

                $user->setRelation(
                    'learnerProfile',
                    $learnerProfile,
                );

                return $user;
            }
        );
    }
}

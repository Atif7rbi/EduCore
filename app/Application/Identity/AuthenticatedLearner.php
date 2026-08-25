<?php

namespace App\Application\Identity;

use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AuthenticatedLearner
{
    public function resolve(?User $user): LearnerProfile
    {
        if ($user === null) {
            throw (new ModelNotFoundException())
                ->setModel(User::class);
        }

        $learner = $user->learnerProfile;

        if ($learner === null) {
            throw (new ModelNotFoundException())
                ->setModel(LearnerProfile::class);
        }

        return $learner;
    }
}

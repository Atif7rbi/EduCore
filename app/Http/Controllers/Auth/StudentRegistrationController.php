<?php

namespace App\Http\Controllers\Auth;

use App\Application\Identity\RegisterStudent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterStudentRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class StudentRegistrationController extends Controller
{
    public function __invoke(
        RegisterStudentRequest $request,
        RegisterStudent $registerStudent,
    ): JsonResponse {
        $user = $registerStudent->register(
            $request->safe()->only([
                'name',
                'email',
                'password',
            ])
        );

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'learner_profile_id' => $user->learnerProfile?->id,
            ],
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Exceptions\TeacherProvisioningIdentityConflict;
use App\Application\Identity\ProvisionTeacher;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProvisionTeacherRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class AdminTeacherController extends Controller
{
    public function store(
        ProvisionTeacherRequest $request,
        ProvisionTeacher $provisionTeacher,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $teacher = $provisionTeacher->execute(
                actorUserId: $actor->id,
                name: $request->validated('name'),
                email: $request->validated('email'),
            );
        } catch (
            TeacherProvisioningIdentityConflict $exception
        ) {
            return ApiResponse::error(
                'teacher_identity_conflict',
                'The email identity already belongs to a User.',
                409,
            );
        }

        $delivery = 'failed';

        try {
            $brokerStatus = Password::broker()
                ->sendResetLink([
                    'email' => $teacher->email,
                ]);

            if (
                $brokerStatus
                === Password::RESET_LINK_SENT
            ) {
                $delivery = 'sent';
            } else {
                Log::warning(
                    'Teacher setup link was not accepted by the password broker.',
                    [
                        'teacher_user_id' => $teacher->id,
                        'broker_status' => $brokerStatus,
                    ],
                );
            }
        } catch (Throwable $exception) {
            /*
             * Fail closed:
             * provisioning remains committed, but the Teacher
             * remains disabled until credential establishment.
             */
            Log::warning(
                'Teacher setup link delivery failed.',
                [
                    'teacher_user_id' => $teacher->id,
                    'exception' => $exception::class,
                ],
            );
        }

        return ApiResponse::success([
            'teacher' => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'role' => $teacher->role,
                'status' => $teacher->status,
            ],
            'setup' => [
                'delivery' => $delivery,
            ],
        ], 201);
    }
}

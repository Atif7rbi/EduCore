<?php

namespace App\Http\Controllers\Api\Enrollment;

use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\DeclineStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exceptions\StudentEnrollmentOperationConflict;
use App\Application\Identity\AuthenticatedLearner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\RequestStudentEnrollmentRequest;
use App\Http\Requests\Enrollment\StudentEnrollmentOperationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentEnrollmentLifecycleController extends Controller
{
    public function store(
        RequestStudentEnrollmentRequest $request,
        AuthenticatedLearner $learnerContext,
        RequestStudentEnrollment $service,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $learner = $learnerContext->resolve($user);

        try {
            $enrollment = $service->execute(
                $user->id,
                $learner->id,
                $request->validated(
                    'teacher_subject_assignment_id'
                ),
                $request->validated('operation_id'),
                $request->validated('reason'),
            );
        } catch (
            StudentEnrollmentOperationConflict $exception
        ) {
            return $this->operationConflict();
        }

        return ApiResponse::success(
            $this->enrollmentData($enrollment)
        );
    }

    public function accept(
        string $enrollmentId,
        StudentEnrollmentOperationRequest $request,
        AcceptStudentEnrollment $service,
    ): JsonResponse {
        return $this->runLifecycleOperation(
            request: $request,
            enrollmentId: $enrollmentId,
            operation: fn (
                string $actorUserId,
                string $operationId,
                string $reason,
            ): StudentEnrollment => $service->execute(
                $actorUserId,
                $enrollmentId,
                $operationId,
                $reason,
            ),
        );
    }

    public function decline(
        string $enrollmentId,
        StudentEnrollmentOperationRequest $request,
        DeclineStudentEnrollment $service,
    ): JsonResponse {
        return $this->runLifecycleOperation(
            request: $request,
            enrollmentId: $enrollmentId,
            operation: fn (
                string $actorUserId,
                string $operationId,
                string $reason,
            ): StudentEnrollment => $service->execute(
                $actorUserId,
                $enrollmentId,
                $operationId,
                $reason,
            ),
        );
    }

    public function deactivate(
        string $enrollmentId,
        StudentEnrollmentOperationRequest $request,
        DeactivateStudentEnrollment $service,
    ): JsonResponse {
        return $this->runLifecycleOperation(
            request: $request,
            enrollmentId: $enrollmentId,
            operation: fn (
                string $actorUserId,
                string $operationId,
                string $reason,
            ): StudentEnrollment => $service->execute(
                $actorUserId,
                $enrollmentId,
                $operationId,
                $reason,
            ),
        );
    }

    /**
     * @param  callable(string, string, string): StudentEnrollment  $operation
     */
    private function runLifecycleOperation(
        Request $request,
        string $enrollmentId,
        callable $operation,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $enrollment = $operation(
                $user->id,
                $request->input('operation_id'),
                $request->input('reason'),
            );
        } catch (
            StudentEnrollmentOperationConflict $exception
        ) {
            return $this->operationConflict();
        }

        return ApiResponse::success(
            $this->enrollmentData($enrollment)
        );
    }

    private function operationConflict(): JsonResponse
    {
        return ApiResponse::error(
            'student_enrollment_operation_conflict',
            'The operation identifier was already used for different enrollment facts.',
            Response::HTTP_CONFLICT,
        );
    }

    private function enrollmentData(
        StudentEnrollment $enrollment,
    ): array {
        return [
            'id' => $enrollment->id,
            'learner_profile_id' => $enrollment->learner_profile_id,
            'teacher_subject_assignment_id' => $enrollment
                ->teacher_subject_assignment_id,
            'status' => $enrollment->status,
            'created_at' => $enrollment
                ->created_at
                ?->toISOString(),
            'updated_at' => $enrollment
                ->updated_at
                ?->toISOString(),
        ];
    }
}

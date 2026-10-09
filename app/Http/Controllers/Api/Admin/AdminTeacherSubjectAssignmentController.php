<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Exceptions\TeacherSubjectAssignmentOperationConflict;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Application\TeacherAssignment\ReactivateTeacherSubjectAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignTeacherSubjectRequest;
use App\Http\Requests\Admin\TeacherSubjectAssignmentOperationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminTeacherSubjectAssignmentController extends Controller
{
    public function index(
        string $teacherUserId,
    ): JsonResponse {
        User::query()
            ->whereKey($teacherUserId)
            ->where('role', 'teacher')
            ->firstOrFail();

        $assignments =
            TeacherSubjectAssignment::query()
                ->where(
                    'teacher_id',
                    $teacherUserId
                )
                ->with('subject')
                ->join(
                    'subjects',
                    'subjects.id',
                    '=',
                    'teacher_subject_assignments.subject_id'
                )
                ->orderBy('subjects.sort_order')
                ->orderBy('subjects.code')
                ->orderBy(
                    'teacher_subject_assignments.id'
                )
                ->select(
                    'teacher_subject_assignments.*'
                )
                ->get()
                ->map(
                    fn (
                        TeacherSubjectAssignment $assignment
                    ): array => $this->assignmentData(
                        $assignment
                    )
                )
                ->values()
                ->all();

        return ApiResponse::success(
            $assignments
        );
    }

    public function store(
        string $teacherUserId,
        AssignTeacherSubjectRequest $request,
        AssignTeacherSubject $service,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $assignment = $service->execute(
                actorUserId: $actor->id,
                teacherUserId: $teacherUserId,
                subjectId: $request->validated(
                    'subject_id'
                ),
                operationId: $request->validated(
                    'operation_id'
                ),
                reason: $request->validated(
                    'reason'
                ),
            );
        } catch (
            TeacherSubjectAssignmentOperationConflict $exception
        ) {
            return $this->operationConflict();
        }

        return ApiResponse::success(
            $this->assignmentData(
                $assignment->load('subject')
            )
        );
    }

    public function deactivate(
        string $assignmentId,
        TeacherSubjectAssignmentOperationRequest $request,
        DeactivateTeacherSubjectAssignment $service,
    ): JsonResponse {
        return $this->runLifecycleOperation(
            request: $request,
            assignmentId: $assignmentId,
            operation: fn (
                string $actorUserId,
                string $operationId,
                string $reason,
            ): TeacherSubjectAssignment => $service->execute(
                actorUserId: $actorUserId,
                assignmentId: $assignmentId,
                operationId: $operationId,
                reason: $reason,
            ),
        );
    }

    public function reactivate(
        string $assignmentId,
        TeacherSubjectAssignmentOperationRequest $request,
        ReactivateTeacherSubjectAssignment $service,
    ): JsonResponse {
        return $this->runLifecycleOperation(
            request: $request,
            assignmentId: $assignmentId,
            operation: fn (
                string $actorUserId,
                string $operationId,
                string $reason,
            ): TeacherSubjectAssignment => $service->execute(
                actorUserId: $actorUserId,
                assignmentId: $assignmentId,
                operationId: $operationId,
                reason: $reason,
            ),
        );
    }

    /**
     * @param  callable(string, string, string): TeacherSubjectAssignment  $operation
     */
    private function runLifecycleOperation(
        TeacherSubjectAssignmentOperationRequest $request,
        string $assignmentId,
        callable $operation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $assignment = $operation(
                $actor->id,
                $request->validated(
                    'operation_id'
                ),
                $request->validated(
                    'reason'
                ),
            );
        } catch (
            TeacherSubjectAssignmentOperationConflict $exception
        ) {
            return $this->operationConflict();
        }

        return ApiResponse::success(
            $this->assignmentData(
                $assignment->load('subject')
            )
        );
    }

    private function operationConflict(): JsonResponse
    {
        return ApiResponse::error(
            'teacher_subject_assignment_operation_conflict',
            'The operation identifier was already used for different assignment facts.',
            Response::HTTP_CONFLICT,
        );
    }

    private function assignmentData(
        TeacherSubjectAssignment $assignment,
    ): array {
        return [
            'id' => $assignment->id,
            'teacher_user_id' => $assignment->teacher_id,
            'subject' => [
                'id' => $assignment->subject->id,
                'code' => $assignment->subject->code,
                'name' => $assignment->subject->name,
                'status' => $assignment->subject->status,
            ],
            'status' => $assignment->status,
            'created_at' => $assignment->created_at
                ?->toISOString(),
            'updated_at' => $assignment->updated_at
                ?->toISOString(),
        ];
    }
}

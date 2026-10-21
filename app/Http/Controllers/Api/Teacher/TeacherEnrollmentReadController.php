<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherEnrollmentReadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['pending', 'active']),
            ],
            'assignment_id' => [
                'nullable',
                'uuid',
            ],
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        /** @var User $teacher */
        $teacher = $request->user();
        $assignmentId = $validated['assignment_id'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 20);

        $enrollments = StudentEnrollment::query()
            ->where('status', $validated['status'])
            ->whereHas(
                'teacherSubjectAssignment',
                function (Builder $query) use (
                    $assignmentId,
                    $teacher,
                ): void {
                    $query
                        ->where('teacher_id', $teacher->id)
                        ->where('status', 'active')
                        ->when(
                            $assignmentId !== null,
                            fn (Builder $assignmentQuery) => $assignmentQuery->whereKey($assignmentId),
                        );
                },
            )
            ->with([
                'learnerProfile:id,user_id',
                'learnerProfile.user:id,name,status',
                'teacherSubjectAssignment:id,teacher_id,subject_id,status',
                'teacherSubjectAssignment.subject:id,code,name,status',
                'transitions:id,enrollment_id,outcome,effective_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($enrollments->items())
                ->map(
                    fn (StudentEnrollment $enrollment): array => $this->enrollmentData($enrollment),
                )
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $enrollments->currentPage(),
                'per_page' => $enrollments->perPage(),
                'total' => $enrollments->total(),
                'last_page' => $enrollments->lastPage(),
            ],
        ]);
    }

    private function enrollmentData(
        StudentEnrollment $enrollment,
    ): array {
        $assignment = $enrollment->teacherSubjectAssignment;

        return [
            'id' => $enrollment->id,
            'student' => [
                'user_id' => $enrollment->learnerProfile->user->id,
                'name' => $enrollment->learnerProfile->user->name,
            ],
            'teacher_subject_assignment' => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'subject' => [
                    'id' => $assignment->subject->id,
                    'code' => $assignment->subject->code,
                    'name' => $assignment->subject->name,
                ],
            ],
            'status' => $enrollment->status,
            'created_at' => $enrollment->created_at?->toISOString(),
            'accepted_at' => $enrollment->transitions
                ->firstWhere('outcome', 'accepted')
                ?->effective_at
                ?->toISOString(),
            'updated_at' => $enrollment->updated_at?->toISOString(),
        ];
    }
}

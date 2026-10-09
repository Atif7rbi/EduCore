<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EducationStage;
use App\Models\Skill;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherWorkspaceReadController extends Controller
{
    public function subjectAssignments(
        Request $request,
    ): JsonResponse {
        /** @var User $teacher */
        $teacher = $request->user();

        $assignments = TeacherSubjectAssignment::query()
            ->where('teacher_id', $teacher->id)
            ->with('subject')
            ->join(
                'subjects',
                'subjects.id',
                '=',
                'teacher_subject_assignments.subject_id',
            )
            ->orderBy('subjects.sort_order')
            ->orderBy('subjects.code')
            ->orderBy('teacher_subject_assignments.id')
            ->select('teacher_subject_assignments.*')
            ->get()
            ->map(
                fn (
                    TeacherSubjectAssignment $assignment
                ): array => [
                    'id' => $assignment->id,
                    'teacher_user_id' => $assignment->teacher_id,
                    'subject' => [
                        'id' => $assignment->subject->id,
                        'code' => $assignment->subject->code,
                        'name' => $assignment->subject->name,
                        'status' => $assignment->subject->status,
                    ],
                    'status' => $assignment->status,
                    'created_at' => $assignment->created_at?->toISOString(),
                    'updated_at' => $assignment->updated_at?->toISOString(),
                ],
            )
            ->values()
            ->all();

        return ApiResponse::success($assignments);
    }

    public function educationStages(): JsonResponse
    {
        $stages = EducationStage::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->map(fn (EducationStage $stage): array => [
                'id' => $stage->id,
                'code' => $stage->code,
                'name' => $stage->name,
                'sort_order' => (int) $stage->sort_order,
                'status' => $stage->status,
            ])
            ->values()
            ->all();

        return ApiResponse::success($stages);
    }

    public function skills(): JsonResponse
    {
        $skills = Skill::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Skill $skill): array => [
                'id' => $skill->id,
                'name' => $skill->name,
                'description' => $skill->description,
            ])
            ->values()
            ->all();

        return ApiResponse::success($skills);
    }
}

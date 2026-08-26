<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Curriculum;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;

class AdminCurriculumReadController extends Controller
{
    public function subjects(): JsonResponse
    {
        $subjects = Subject::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Subject $subject): array => [
                'id' => $subject->id,
                'name' => $subject->name,
                'created_at' => $subject->created_at?->toISOString(),
                'updated_at' => $subject->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($subjects);
    }

    public function curricula(
        string $subjectId,
    ): JsonResponse {
        $subject = Subject::query()
            ->whereKey($subjectId)
            ->firstOrFail();

        $curricula = Curriculum::query()
            ->where('subject_id', $subject->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Curriculum $curriculum): array => [
                'id' => $curriculum->id,
                'subject_id' => $curriculum->subject_id,
                'name' => $curriculum->name,
                'created_at' =>
                    $curriculum->created_at?->toISOString(),
                'updated_at' =>
                    $curriculum->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($curricula);
    }

    public function versions(
        string $curriculumId,
    ): JsonResponse {
        $curriculum = Curriculum::query()
            ->whereKey($curriculumId)
            ->firstOrFail();

        $versions = $curriculum->versions()
            ->orderBy('version_number')
            ->orderBy('id')
            ->get()
            ->map(fn ($version): array => [
                'id' => $version->id,
                'curriculum_id' => $version->curriculum_id,
                'version_number' => $version->version_number,
                'label' => $version->label,
                'status' => $version->status,
                'created_at' =>
                    $version->created_at?->toISOString(),
                'updated_at' =>
                    $version->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($versions);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Curriculum;
use App\Models\EducationStage;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;

class AdminCurriculumReadController extends Controller
{
    public function subjects(): JsonResponse
    {
        /*
         * Normal catalog read model:
         * canonical Subjects only.
         *
         * Inactive canonical Subjects remain visible for historical
         * context, but new Curriculum creation rejects them.
         */
        $subjects = Subject::query()
            ->whereNotNull('code')
            ->withCount('curricula')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id')
            ->get()
            ->map(fn (Subject $subject): array => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'icon_key' => $subject->icon_key,
                'thumbnail_key' => $subject->thumbnail_key,
                'sort_order' => (int) $subject->sort_order,
                'status' => $subject->status,
                'curricula_count' => (int) $subject->curricula_count,
                'created_at' => $subject->created_at?->toISOString(),
                'updated_at' => $subject->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($subjects);
    }

    public function educationStages(): JsonResponse
    {
        $stages = EducationStage::query()
            ->withCount('curricula')
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
                'curricula_count' => (int) $stage->curricula_count,
                'created_at' => $stage->created_at?->toISOString(),
                'updated_at' => $stage->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($stages);
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
                'education_stage_id' => $curriculum->education_stage_id,
                'name' => $curriculum->name,
                'created_at' => $curriculum->created_at?->toISOString(),
                'updated_at' => $curriculum->updated_at?->toISOString(),
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
                'created_at' => $version->created_at?->toISOString(),
                'updated_at' => $version->updated_at?->toISOString(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($versions);
    }
}

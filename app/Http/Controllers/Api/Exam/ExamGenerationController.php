<?php

namespace App\Http\Controllers\Api\Exam;

use App\Application\Exam\BuildExamGeneration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\BuildExamGenerationRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ExamGenerationController extends Controller
{
    public function store(
        string $examTemplateVersionId,
        BuildExamGenerationRequest $request,
        BuildExamGeneration $service,
    ): JsonResponse {
        $validated = $request->validated();

        $generation = $service->execute(
            $examTemplateVersionId,
            $validated['generator_version'],
            $validated['seed'],
            $validated['items'],
        );

        $generation->load([
            'items' => fn ($query) => $query
                ->orderBy('selection_position'),
        ]);

        return ApiResponse::success([
            'id' => $generation->id,
            'exam_template_version_id' => $generation->exam_template_version_id,
            'curriculum_version_id' => $generation->curriculum_version_id,
            'rules_snapshot' => $generation->rules_snapshot,
            'rules_schema_version' => $generation->rules_schema_version,
            'generator_version' => $generation->generator_version,
            'seed' => $generation->seed,
            'generated_at' => $generation->generated_at?->toISOString(),
            'items' => $generation->items
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'assessment_item_revision_id' => $item->assessment_item_revision_id,
                    'assessment_item_id' => $item->assessment_item_id,
                    'curriculum_version_id' => $item->curriculum_version_id,
                    'selection_position' => $item->selection_position,
                ])
                ->values()
                ->all(),
        ], Response::HTTP_CREATED);
    }
}

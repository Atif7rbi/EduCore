<?php

namespace App\Http\Controllers\Api\Read;

use App\Application\Identity\AuthenticatedLearner;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attempt;
use App\Models\ExamGeneration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamReadController extends Controller
{
    public function index(
        Request $request,
        AuthenticatedLearner $learnerContext,
    ): JsonResponse {
        $learner = $learnerContext->resolve(
            $request->user()
        );

        $generations = ExamGeneration::query()
            ->whereHas(
                'examTemplateVersion',
                fn ($query) => $query
                    ->where('status', 'published')
            )
            ->whereHas(
                'curriculumVersion',
                fn ($query) => $query
                    ->where('status', 'published')
            )
            ->with([
                'examTemplateVersion.examTemplate',
            ])
            ->withCount('items')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get();

        $attempts = Attempt::query()
            ->where(
                'learner_profile_id',
                $learner->id
            )
            ->whereNotNull(
                'exam_generation_id'
            )
            ->whereIn(
                'exam_generation_id',
                $generations->pluck('id')
            )
            ->get()
            ->keyBy('exam_generation_id');

        return ApiResponse::success(
            $generations
                ->map(function (
                    ExamGeneration $generation
                ) use ($attempts): array {
                    $version =
                        $generation
                            ->examTemplateVersion;

                    $template =
                        $version->examTemplate;

                    $attempt =
                        $attempts->get(
                            $generation->id
                        );

                    return [
                        'id' =>
                            $generation->id,
                        'curriculum_version_id' =>
                            $generation
                                ->curriculum_version_id,
                        'exam_template_version_id' =>
                            $generation
                                ->exam_template_version_id,
                        'template' => [
                            'id' =>
                                $template->id,
                            'name' =>
                                $template->name,
                            'description' =>
                                $template->description,
                        ],
                        'template_version' => [
                            'id' =>
                                $version->id,
                            'version_number' =>
                                $version->version_number,
                            'label' =>
                                $version->label,
                        ],
                        'generated_at' =>
                            $generation
                                ->generated_at
                                ?->toISOString(),
                        'item_count' =>
                            $generation
                                ->items_count,
                        'current_attempt' =>
                            $attempt === null
                                ? null
                                : [
                                    'id' =>
                                        $attempt->id,
                                    'status' =>
                                        $attempt->status,
                                    'started_at' =>
                                        $attempt
                                            ->started_at
                                            ?->toISOString(),
                                    'finalized_at' =>
                                        $attempt
                                            ->finalized_at
                                            ?->toISOString(),
                                ],
                    ];
                })
                ->values()
                ->all()
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Attempt;

use App\Application\Attempt\BuildExamAttempt;
use App\Application\Attempt\BuildPracticeAttempt;
use App\Application\Identity\AuthenticatedLearner;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExamGeneration;
use App\Models\PracticeActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttemptConstructionController extends Controller
{
    public function fromExam(
        string $examGenerationId,
        Request $request,
        AuthenticatedLearner $learnerContext,
        BuildExamAttempt $service,
    ): JsonResponse {
        $learner = $learnerContext->resolve($request->user());

        ExamGeneration::query()
            ->whereKey($examGenerationId)
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
            ->firstOrFail();

        $attempt = $service->execute(
            $learner->id,
            $examGenerationId,
        );

        return $this->created($attempt);
    }

    public function fromPractice(
        string $practiceActivityId,
        Request $request,
        AuthenticatedLearner $learnerContext,
        BuildPracticeAttempt $service,
    ): JsonResponse {
        $learner = $learnerContext->resolve($request->user());

        PracticeActivity::query()
            ->whereKey($practiceActivityId)
            ->where('status', 'active')
            ->whereHas(
                'curriculumVersion',
                fn ($query) => $query
                    ->where('status', 'published')
            )
            ->firstOrFail();

        $attempt = $service->execute(
            $learner->id,
            $practiceActivityId,
        );

        return $this->created($attempt);
    }

    private function created($attempt): JsonResponse
    {
        $attempt->load([
            'items' => fn ($query) => $query
                ->orderBy('presentation_position'),
        ]);

        return ApiResponse::success([
            'id' => $attempt->id,
            'learner_profile_id' => $attempt->learner_profile_id,
            'exam_generation_id' => $attempt->exam_generation_id,
            'practice_activity_id' => $attempt->practice_activity_id,
            'curriculum_version_id' => $attempt->curriculum_version_id,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toISOString(),
            'finalized_at' => $attempt->finalized_at?->toISOString(),
            'items' => $attempt->items
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'assessment_item_revision_id' => $item->assessment_item_revision_id,
                    'assessment_item_id' => $item->assessment_item_id,
                    'presentation_position' => $item->presentation_position,
                    'presented_payload' => $item->presented_payload,
                    'presented_schema_version' => $item->presented_schema_version,
                ])
                ->values()
                ->all(),
        ], Response::HTTP_CREATED);
    }
}

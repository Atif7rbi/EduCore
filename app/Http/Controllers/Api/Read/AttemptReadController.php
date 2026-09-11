<?php

namespace App\Http\Controllers\Api\Read;

use App\Application\Identity\AuthenticatedLearner;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttemptReadController extends Controller
{
    public function index(
        Request $request,
        AuthenticatedLearner $learnerContext,
    ): JsonResponse {
        $learner = $learnerContext->resolve($request->user());

        $attempts = Attempt::query()
            ->where(
                'learner_profile_id',
                $learner->id
            )
            ->with([
                'items.response',
                'items.response.regradeCorrections' => fn ($query) =>
                    $query
                        ->orderByDesc('correction_number')
                        ->orderByDesc('id'),
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        $data = $attempts
            ->map(function (Attempt $attempt): array {
                $isFinalized = in_array(
                    $attempt->status,
                    ['submitted', 'abandoned'],
                    true,
                );

                $item = [
                    'id' => $attempt->id,
                    'exam_generation_id' =>
                        $attempt->exam_generation_id,
                    'practice_activity_id' =>
                        $attempt->practice_activity_id,
                    'curriculum_version_id' =>
                        $attempt->curriculum_version_id,
                    'status' => $attempt->status,
                    'started_at' =>
                        $attempt->started_at?->toISOString(),
                    'finalized_at' =>
                        $attempt->finalized_at?->toISOString(),
                ];

                if (! $isFinalized) {
                    return $item;
                }

                $answered = 0;
                $correct = 0;
                $incorrect = 0;
                $unanswered = 0;

                foreach ($attempt->items as $attemptItem) {
                    $response = $attemptItem->response;

                    if ($response === null) {
                        $unanswered++;

                        continue;
                    }

                    $latestCorrection =
                        $response->regradeCorrections->first();

                    $effective =
                        $latestCorrection?->corrected_is_correct
                        ?? $response->original_is_correct;

                    if ($effective === null) {
                        $unanswered++;

                        continue;
                    }

                    $answered++;

                    if ($effective === true) {
                        $correct++;
                    } else {
                        $incorrect++;
                    }
                }

                $item['summary'] = [
                    'answered' => $answered,
                    'correct' => $correct,
                    'incorrect' => $incorrect,
                    'unanswered' => $unanswered,
                    'total' => $attempt->items->count(),
                ];

                return $item;
            })
            ->values()
            ->all();

        return ApiResponse::success($data);
    }

    public function show(
        string $attemptId,
        Request $request,
        AuthenticatedLearner $learnerContext,
    ): JsonResponse {
        $learner = $learnerContext->resolve($request->user());

        $attempt = Attempt::query()
            ->whereKey($attemptId)
            ->where('learner_profile_id', $learner->id)
            ->with([
                'items' => fn ($query) => $query
                    ->orderBy('presentation_position')
                    ->orderBy('id'),
                'items.response',
                'items.response.regradeCorrections' => fn ($query) =>
                    $query
                        ->orderByDesc('correction_number')
                        ->orderByDesc('id'),
            ])
            ->firstOrFail();

        $isFinalized = in_array(
            $attempt->status,
            ['submitted', 'abandoned'],
            true,
        );

        $items = $attempt->items
            ->map(function ($item) use ($isFinalized): array {
                $response = $item->response;

                $result = [
                    'id' => $item->id,
                    'assessment_item_revision_id' =>
                        $item->assessment_item_revision_id,
                    'assessment_item_id' =>
                        $item->assessment_item_id,
                    'presentation_position' =>
                        $item->presentation_position,
                    'presented_payload' =>
                        $item->presented_payload,
                    'presented_schema_version' =>
                        $item->presented_schema_version,
                    'response' => $response === null
                        ? null
                        : [
                            'id' => $response->id,
                            'response_payload' =>
                                $response->response_payload,
                            'answer_change_count' =>
                                $response->answer_change_count,
                            'time_spent_ms' =>
                                $response->time_spent_ms,
                        ],
                ];

                if (
                    $isFinalized
                    && $response !== null
                ) {
                    $latestCorrection =
                        $response->regradeCorrections->first();

                    $result['result'] = [
                        'original_is_correct' =>
                            $response->original_is_correct,
                        'effective_is_correct' =>
                            $latestCorrection?->corrected_is_correct
                            ?? $response->original_is_correct,
                        'correction_number' =>
                            $latestCorrection?->correction_number,
                    ];
                }

                return $result;
            })
            ->values();

        $payload = [
            'id' => $attempt->id,
            'learner_profile_id' => $attempt->learner_profile_id,
            'exam_generation_id' => $attempt->exam_generation_id,
            'practice_activity_id' => $attempt->practice_activity_id,
            'curriculum_version_id' => $attempt->curriculum_version_id,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toISOString(),
            'finalized_at' => $attempt->finalized_at?->toISOString(),
            'items' => $items->all(),
        ];

        if ($isFinalized) {
            $answered = 0;
            $correct = 0;
            $incorrect = 0;
            $unanswered = 0;

            foreach ($items as $item) {
                $effective = $item['result']['effective_is_correct']
                    ?? null;

                if ($effective === null) {
                    $unanswered++;

                    continue;
                }

                $answered++;

                if ($effective === true) {
                    $correct++;
                } else {
                    $incorrect++;
                }
            }

            $payload['summary'] = [
                'answered' => $answered,
                'correct' => $correct,
                'incorrect' => $incorrect,
                'unanswered' => $unanswered,
                'total' => $items->count(),
            ];
        }

        return ApiResponse::success($payload);
    }
}

<?php

namespace App\Http\Controllers\Api\Read;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attempt;
use Illuminate\Http\JsonResponse;

class AttemptReadController extends Controller
{
    public function show(string $attemptId): JsonResponse
    {
        $attempt = Attempt::query()
            ->with([
                'items' => fn ($query) => $query
                    ->orderBy('presentation_position')
                    ->orderBy('id'),
                'items.response',
            ])
            ->findOrFail($attemptId);

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
                ->map(function ($item): array {
                    $response = $item->response;

                    return [
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
                })
                ->values()
                ->all(),
        ]);
    }
}

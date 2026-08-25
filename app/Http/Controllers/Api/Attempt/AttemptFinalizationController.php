<?php

namespace App\Http\Controllers\Api\Attempt;

use App\Application\Attempt\FinalizeAttempt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attempt\FinalizeAttemptRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class AttemptFinalizationController extends Controller
{
    public function update(
        string $attemptId,
        FinalizeAttemptRequest $request,
        FinalizeAttempt $service,
    ): JsonResponse {
        $validated = $request->validated();

        $correctnessByAttemptItemId = [];

        foreach ($validated['items'] as $item) {
            $correctnessByAttemptItemId[
                $item['attempt_item_id']
            ] = $item['original_is_correct'];
        }

        $attempt = $service->execute(
            $attemptId,
            $correctnessByAttemptItemId,
            $validated['final_status'],
        );

        return ApiResponse::success([
            'id' => $attempt->id,
            'learner_profile_id' => $attempt->learner_profile_id,
            'exam_generation_id' => $attempt->exam_generation_id,
            'practice_activity_id' => $attempt->practice_activity_id,
            'curriculum_version_id' => $attempt->curriculum_version_id,
            'status' => $attempt->status,
            'started_at' => $attempt->started_at?->toISOString(),
            'finalized_at' => $attempt->finalized_at?->toISOString(),
        ]);
    }
}

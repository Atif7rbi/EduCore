<?php

namespace App\Http\Controllers\Api\Attempt;

use App\Application\Attempt\SaveAttemptResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attempt\SaveAttemptResponseRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class AttemptResponseController extends Controller
{
    public function update(
        string $attemptItemId,
        SaveAttemptResponseRequest $request,
        SaveAttemptResponse $service,
    ): JsonResponse {
        $validated = $request->validated();

        $response = $service->execute(
            $attemptItemId,
            $validated['response_payload'] ?? null,
            $validated['time_spent_ms'],
        );

        return ApiResponse::success([
            'id' => $response->id,
            'attempt_item_id' => $response->attempt_item_id,
            'response_payload' => $response->response_payload,
            'answer_change_count' => $response->answer_change_count,
            'time_spent_ms' => $response->time_spent_ms,
            'original_is_correct' => $response->original_is_correct,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Attempt;

use App\Application\Attempt\AddRegradeCorrection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attempt\AddRegradeCorrectionRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegradeCorrectionController extends Controller
{
    public function store(
        string $attemptResponseId,
        AddRegradeCorrectionRequest $request,
        AddRegradeCorrection $service,
    ): JsonResponse {
        $validated = $request->validated();

        $correction = $service->execute(
            $attemptResponseId,
            $validated['corrected_is_correct'],
            $validated['reason'],
        );

        return ApiResponse::success([
            'id' => $correction->id,
            'attempt_response_id' => $correction->attempt_response_id,
            'correction_number' => $correction->correction_number,
            'corrected_is_correct' => $correction->corrected_is_correct,
            'reason' => $correction->reason,
            'corrected_at' => $correction->corrected_at?->toISOString(),
        ], Response::HTTP_CREATED);
    }
}

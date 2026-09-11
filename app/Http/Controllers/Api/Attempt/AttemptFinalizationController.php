<?php

namespace App\Http\Controllers\Api\Attempt;

use App\Application\Attempt\FinalizeAttempt;
use App\Application\Identity\AuthenticatedLearner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attempt\FinalizeAttemptRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attempt;
use Illuminate\Http\JsonResponse;

class AttemptFinalizationController extends Controller
{
    public function update(
        string $attemptId,
        FinalizeAttemptRequest $request,
        AuthenticatedLearner $learnerContext,
        FinalizeAttempt $service,
    ): JsonResponse {
        $learner = $learnerContext->resolve($request->user());

        Attempt::query()
            ->whereKey($attemptId)
            ->where(
                'learner_profile_id',
                $learner->id
            )
            ->firstOrFail();

        $validated = $request->validated();

        $attempt = $service->execute(
            $attemptId,
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

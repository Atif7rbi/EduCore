<?php

namespace App\Http\Controllers\Api\Practice;

use App\Application\Practice\AddPracticeActivityItem;
use App\Application\Practice\RemovePracticeActivityItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Practice\AddPracticeActivityItemRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PracticeActivityItemController extends Controller
{
    public function store(
        string $practiceActivityId,
        AddPracticeActivityItemRequest $request,
        AddPracticeActivityItem $service,
    ): JsonResponse {
        $validated = $request->validated();

        $item = $service->execute(
            $practiceActivityId,
            $validated['assessment_item_revision_id'],
            $validated['assessment_item_id'],
            $validated['display_order'],
        );

        return ApiResponse::success([
            'id' => $item->id,
            'practice_activity_id' => $item->practice_activity_id,
            'assessment_item_revision_id' => $item->assessment_item_revision_id,
            'assessment_item_id' => $item->assessment_item_id,
            'curriculum_version_id' => $item->curriculum_version_id,
            'display_order' => $item->display_order,
        ], Response::HTTP_CREATED);
    }

    public function destroy(
        string $practiceActivityId,
        string $practiceActivityItemId,
        RemovePracticeActivityItem $service,
    ): JsonResponse {
        $service->execute(
            $practiceActivityId,
            $practiceActivityItemId,
        );

        return ApiResponse::success([
            'deleted' => true,
        ]);
    }
}

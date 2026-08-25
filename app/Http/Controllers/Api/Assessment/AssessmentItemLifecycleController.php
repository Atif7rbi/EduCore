<?php

namespace App\Http\Controllers\Api\Assessment;

use App\Application\Assessment\PublishAssessmentItem;
use App\Application\Assessment\RetireAssessmentItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\PublishAssessmentItemRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class AssessmentItemLifecycleController extends Controller
{
    public function publish(
        string $assessmentItemId,
        PublishAssessmentItemRequest $request,
        PublishAssessmentItem $service,
    ): JsonResponse {
        $item = $service->execute(
            $assessmentItemId,
            $request->validated('published_revision_id'),
        );

        return ApiResponse::success([
            'id' => $item->id,
            'curriculum_version_id' => $item->curriculum_version_id,
            'item_type' => $item->item_type,
            'internal_label' => $item->internal_label,
            'status' => $item->status,
            'published_revision_id' => $item->published_revision_id,
        ]);
    }

    public function retire(
        string $assessmentItemId,
        RetireAssessmentItem $service,
    ): JsonResponse {
        $item = $service->execute($assessmentItemId);

        return ApiResponse::success([
            'id' => $item->id,
            'curriculum_version_id' => $item->curriculum_version_id,
            'item_type' => $item->item_type,
            'internal_label' => $item->internal_label,
            'status' => $item->status,
            'published_revision_id' => $item->published_revision_id,
        ]);
    }
}

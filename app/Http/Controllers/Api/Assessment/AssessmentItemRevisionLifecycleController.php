<?php

namespace App\Http\Controllers\Api\Assessment;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class AssessmentItemRevisionLifecycleController extends Controller
{
    public function release(
        string $assessmentItemRevisionId,
        ReleaseAssessmentItemRevision $service,
    ): JsonResponse {
        $revision = $service->execute($assessmentItemRevisionId);

        return ApiResponse::success([
            'id' => $revision->id,
            'assessment_item_id' => $revision->assessment_item_id,
            'curriculum_version_id' => $revision->curriculum_version_id,
            'revision_number' => $revision->revision_number,
            'primary_topic_id' => $revision->primary_topic_id,
            'difficulty' => $revision->difficulty,
            'released_at' => $revision->released_at?->toISOString(),
        ]);
    }
}

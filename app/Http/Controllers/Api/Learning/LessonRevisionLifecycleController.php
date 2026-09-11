<?php

namespace App\Http\Controllers\Api\Learning;

use App\Application\Learning\ReleaseLessonRevision;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class LessonRevisionLifecycleController extends Controller
{
    public function release(
        string $lessonRevisionId,
        ReleaseLessonRevision $service,
    ): JsonResponse {
        $revision = $service->execute($lessonRevisionId);

        return ApiResponse::success([
            'id' => $revision->id,
            'lesson_id' => $revision->lesson_id,
            'curriculum_version_id' => $revision->curriculum_version_id,
            'revision_number' => $revision->revision_number,
            'primary_topic_id' => $revision->primary_topic_id,
            'released_at' => $revision->released_at?->toISOString(),
        ]);
    }
}

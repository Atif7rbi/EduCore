<?php

namespace App\Http\Controllers\Api\Learning;

use App\Application\Learning\PublishLesson;
use App\Application\Learning\RetireLesson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\PublishLessonRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class LessonLifecycleController extends Controller
{
    public function publish(
        string $lessonId,
        PublishLessonRequest $request,
        PublishLesson $service,
    ): JsonResponse {
        $lesson = $service->execute(
            $lessonId,
            $request->validated('published_revision_id'),
        );

        return ApiResponse::success([
            'id' => $lesson->id,
            'curriculum_version_id' => $lesson->curriculum_version_id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'status' => $lesson->status,
            'display_order' => $lesson->display_order,
            'published_revision_id' => $lesson->published_revision_id,
        ]);
    }

    public function retire(
        string $lessonId,
        RetireLesson $service,
    ): JsonResponse {
        $lesson = $service->execute($lessonId);

        return ApiResponse::success([
            'id' => $lesson->id,
            'curriculum_version_id' => $lesson->curriculum_version_id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'status' => $lesson->status,
            'display_order' => $lesson->display_order,
            'published_revision_id' => $lesson->published_revision_id,
        ]);
    }
}

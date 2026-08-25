<?php

namespace App\Http\Controllers\Api\Learning;

use App\Application\Identity\AuthenticatedLearner;
use App\Application\Learning\RecordLessonProgress;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LessonProgressController extends Controller
{
    public function start(
        string $lessonId,
        Request $request,
        AuthenticatedLearner $learnerContext,
        RecordLessonProgress $service,
    ): JsonResponse {
        $learner = $learnerContext->resolve(
            $request->user()
        );

        $lesson = Lesson::query()
            ->where('status', 'published')
            ->whereNotNull('published_revision_id')
            ->whereHas(
                'curriculumVersion',
                fn ($query) => $query
                    ->where('status', 'published')
            )
            ->findOrFail($lessonId);

        $progress = $service->execute(
            $learner->id,
            $lesson->published_revision_id,
        );

        return ApiResponse::success(
            $this->payload($progress)
        );
    }

    public function complete(
        string $lessonId,
        Request $request,
        AuthenticatedLearner $learnerContext,
        RecordLessonProgress $service,
    ): JsonResponse {
        $learner = $learnerContext->resolve(
            $request->user()
        );

        $lesson = Lesson::query()
            ->where('status', 'published')
            ->whereNotNull('published_revision_id')
            ->whereHas(
                'curriculumVersion',
                fn ($query) => $query
                    ->where('status', 'published')
            )
            ->findOrFail($lessonId);

        $progress = $service->execute(
            $learner->id,
            $lesson->published_revision_id,
            true,
        );

        return ApiResponse::success(
            $this->payload($progress)
        );
    }

    private function payload($progress): array
    {
        return [
            'id' => $progress->id,
            'lesson_revision_id' => $progress->lesson_revision_id,
            'status' => $progress->status,
            'started_at' => $progress->started_at?->toISOString(),
            'completed_at' => $progress->completed_at?->toISOString(),
        ];
    }
}

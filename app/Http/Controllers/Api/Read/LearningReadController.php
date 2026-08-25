<?php

namespace App\Http\Controllers\Api\Read;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Lesson;
use App\Models\PracticeActivity;
use Illuminate\Http\JsonResponse;

class LearningReadController extends Controller
{
    public function lesson(string $lessonId): JsonResponse
    {
        $lesson = Lesson::query()
            ->with([
                'publishedRevision',
                'practiceActivities' => fn ($query) => $query
                    ->orderBy('created_at')
                    ->orderBy('id'),
            ])
            ->findOrFail($lessonId);

        $revision = $lesson->publishedRevision;

        return ApiResponse::success([
            'id' => $lesson->id,
            'curriculum_version_id' => $lesson->curriculum_version_id,
            'title' => $lesson->title,
            'description' => $lesson->description,
            'status' => $lesson->status,
            'display_order' => $lesson->display_order,
            'published_revision' => $revision === null
                ? null
                : [
                    'id' => $revision->id,
                    'revision_number' => $revision->revision_number,
                    'primary_topic_id' => $revision->primary_topic_id,
                    'content_payload' => $revision->content_payload,
                    'content_schema_version' => $revision->content_schema_version,
                    'released_at' => $revision->released_at?->toISOString(),
                ],
            'practice_activities' => $lesson->practiceActivities
                ->map(fn (PracticeActivity $activity): array => [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'description' => $activity->description,
                    'status' => $activity->status,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function practiceActivity(
        string $practiceActivityId,
    ): JsonResponse {
        $activity = PracticeActivity::query()
            ->with([
                'items' => fn ($query) => $query
                    ->orderBy('display_order')
                    ->orderBy('id'),
            ])
            ->findOrFail($practiceActivityId);

        return ApiResponse::success([
            'id' => $activity->id,
            'curriculum_version_id' => $activity->curriculum_version_id,
            'lesson_id' => $activity->lesson_id,
            'name' => $activity->name,
            'description' => $activity->description,
            'status' => $activity->status,
            'items' => $activity->items
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'assessment_item_revision_id' => $item->assessment_item_revision_id,
                    'assessment_item_id' => $item->assessment_item_id,
                    'display_order' => $item->display_order,
                ])
                ->values()
                ->all(),
        ]);
    }
}

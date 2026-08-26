<?php

namespace App\Http\Controllers\Api\Read;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attempt;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressReadController extends Controller
{
    public function overview(
        Request $request,
    ): JsonResponse {
        $learnerProfile =
            $request->user()
                ?->learnerProfile;

        if ($learnerProfile === null) {
            return ApiResponse::error(
                'learner_profile_required',
                'A learner profile is required.',
                403,
            );
        }

        $learning = LessonProgress::query()
            ->where(
                'learner_profile_id',
                $learnerProfile->id,
            )
            ->selectRaw(
                <<<'SQL'
COUNT(*) AS started_lessons_count,
COUNT(*) FILTER (
    WHERE status = 'completed'
) AS completed_lessons_count
SQL
            )
            ->first();

        $assessment = Attempt::query()
            ->where(
                'learner_profile_id',
                $learnerProfile->id,
            )
            ->selectRaw(
                <<<'SQL'
COUNT(*) AS attempts_total,
COUNT(*) FILTER (
    WHERE status = 'submitted'
) AS submitted_attempts,
COUNT(*) FILTER (
    WHERE status = 'abandoned'
) AS abandoned_attempts,
COUNT(*) FILTER (
    WHERE status = 'in_progress'
) AS in_progress_attempts
SQL
            )
            ->first();

        return ApiResponse::success([
            'learning' => [
                'started_lessons_count' =>
                    (int) (
                        $learning
                            ?->started_lessons_count
                        ?? 0
                    ),
                'completed_lessons_count' =>
                    (int) (
                        $learning
                            ?->completed_lessons_count
                        ?? 0
                    ),
            ],
            'assessment' => [
                'attempts_total' =>
                    (int) (
                        $assessment
                            ?->attempts_total
                        ?? 0
                    ),
                'submitted_attempts' =>
                    (int) (
                        $assessment
                            ?->submitted_attempts
                        ?? 0
                    ),
                'abandoned_attempts' =>
                    (int) (
                        $assessment
                            ?->abandoned_attempts
                        ?? 0
                    ),
                'in_progress_attempts' =>
                    (int) (
                        $assessment
                            ?->in_progress_attempts
                        ?? 0
                    ),
            ],
        ]);
    }
}

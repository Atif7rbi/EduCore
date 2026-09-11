<?php

namespace App\Http\Controllers\Api\Read;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\SkillAnalyticsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\EvidenceScope;
use App\Models\MaterializedSkillPerformance;
use Illuminate\Http\JsonResponse;

class SkillAnalyticsReadController extends Controller
{
    public function index(
        SkillAnalyticsRequest $request,
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

        $scope = EvidenceScope::query()
            ->whereKey(
                $request->validated(
                    'evidence_scope_id'
                )
            )
            ->firstOrFail();

        $performances =
            MaterializedSkillPerformance::query()
                ->where(
                    'learner_profile_id',
                    $learnerProfile->id,
                )
                ->where(
                    'evidence_scope_id',
                    $scope->id,
                )
                ->with('skill')
                ->get()
                ->sortBy(
                    fn (
                        MaterializedSkillPerformance $performance
                    ): string =>
                        mb_strtolower(
                            $performance->skill->name
                        )
                        .'|'
                        .$performance->skill_id
                )
                ->values()
                ->map(
                    fn (
                        MaterializedSkillPerformance $performance
                    ): array =>
                        $this->performanceData(
                            $performance
                        )
                )
                ->all();

        return ApiResponse::success([
            'evidence_scope' => [
                'id' => $scope->id,
                'label' => $scope->label,
                'status' => $scope->status,
                'definition_schema_version' =>
                    $scope
                        ->definition_schema_version,
            ],
            'temporal_boundary' => 'lifetime',
            'skills' => $performances,
        ]);
    }

    private function performanceData(
        MaterializedSkillPerformance $performance,
    ): array {
        $answered =
            $performance
                ->single_primary_answered_count;

        $correct =
            $performance
                ->single_primary_correct_count;

        return [
            'skill' => [
                'id' =>
                    $performance->skill_id,
                'name' =>
                    $performance->skill->name,
                'description' =>
                    $performance
                        ->skill
                        ->description,
            ],
            'single_primary' => [
                'correct_count' => $correct,
                'answered_count' => $answered,
                'accuracy' =>
                    $answered > 0
                        ? $correct / $answered
                        : null,
            ],
            'supporting' => [
                'positive_count' =>
                    $performance
                        ->supporting_positive_count,
                'exposure_count' =>
                    $performance
                        ->supporting_exposure_count,
            ],
            'last_rebuilt_at' =>
                $performance
                    ->last_rebuilt_at
                    ?->toISOString(),
        ];
    }
}

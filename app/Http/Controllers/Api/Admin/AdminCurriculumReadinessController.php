<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Curriculum\EvaluateCurriculumVersionReadiness;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminCurriculumReadinessController extends Controller
{
    public function __invoke(
        string $curriculumVersionId,
        EvaluateCurriculumVersionReadiness $service,
    ): JsonResponse {
        return ApiResponse::success(
            $service->execute(
                $curriculumVersionId
            )
        );
    }
}

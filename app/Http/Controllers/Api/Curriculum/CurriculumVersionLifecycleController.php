<?php

namespace App\Http\Controllers\Api\Curriculum;

use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Exceptions\CurriculumVersionNotReady;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class CurriculumVersionLifecycleController extends Controller
{
    public function publish(
        string $curriculumVersionId,
        PublishCurriculumVersion $service,
    ): JsonResponse {
        try {
            $version =
                $service->execute(
                    $curriculumVersionId
                );
        } catch (
            CurriculumVersionNotReady $exception
        ) {
            return ApiResponse::error(
                'curriculum_version_not_ready',
                'The curriculum version does not satisfy the publishing requirements.',
                409,
                [
                    'blockers' =>
                        array_values(
                            array_map(
                                static fn (
                                    array $blocker
                                ): string =>
                                    $blocker['code'],
                                $exception->blockers,
                            )
                        ),
                ],
            );
        }

        return ApiResponse::success([
            'id' => $version->id,
            'curriculum_id' => $version->curriculum_id,
            'version_number' => $version->version_number,
            'label' => $version->label,
            'status' => $version->status,
        ]);
    }

    public function retire(
        string $curriculumVersionId,
        RetireCurriculumVersion $service,
    ): JsonResponse {
        $version = $service->execute($curriculumVersionId);

        return ApiResponse::success([
            'id' => $version->id,
            'curriculum_id' => $version->curriculum_id,
            'version_number' => $version->version_number,
            'label' => $version->label,
            'status' => $version->status,
        ]);
    }
}

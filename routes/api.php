<?php

use App\Http\Controllers\Api\Curriculum\CurriculumVersionLifecycleController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'data' => [
            'status' => 'ok',
        ],
    ]);
});

Route::prefix('curriculum-versions')->group(function (): void {
    Route::post(
        '/{curriculumVersionId}/publish',
        [CurriculumVersionLifecycleController::class, 'publish']
    )->whereUuid('curriculumVersionId');

    Route::post(
        '/{curriculumVersionId}/retire',
        [CurriculumVersionLifecycleController::class, 'retire']
    )->whereUuid('curriculumVersionId');
});

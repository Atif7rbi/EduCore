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

Route::prefix('lesson-revisions')->group(function (): void {
    Route::post(
        '/{lessonRevisionId}/release',
        [
            \App\Http\Controllers\Api\Learning\LessonRevisionLifecycleController::class,
            'release',
        ]
    )->whereUuid('lessonRevisionId');
});

Route::prefix('lessons')->group(function (): void {
    Route::post(
        '/{lessonId}/publish',
        [
            \App\Http\Controllers\Api\Learning\LessonLifecycleController::class,
            'publish',
        ]
    )->whereUuid('lessonId');

    Route::post(
        '/{lessonId}/retire',
        [
            \App\Http\Controllers\Api\Learning\LessonLifecycleController::class,
            'retire',
        ]
    )->whereUuid('lessonId');
});

Route::prefix('assessment-item-revisions')->group(function (): void {
    Route::post(
        '/{assessmentItemRevisionId}/release',
        [
            \App\Http\Controllers\Api\Assessment\AssessmentItemRevisionLifecycleController::class,
            'release',
        ]
    )->whereUuid('assessmentItemRevisionId');
});

Route::prefix('assessment-items')->group(function (): void {
    Route::post(
        '/{assessmentItemId}/publish',
        [
            \App\Http\Controllers\Api\Assessment\AssessmentItemLifecycleController::class,
            'publish',
        ]
    )->whereUuid('assessmentItemId');

    Route::post(
        '/{assessmentItemId}/retire',
        [
            \App\Http\Controllers\Api\Assessment\AssessmentItemLifecycleController::class,
            'retire',
        ]
    )->whereUuid('assessmentItemId');
});

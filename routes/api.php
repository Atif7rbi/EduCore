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

Route::middleware(['web', 'management'])->group(function (): void {
    Route::prefix('admin')->group(function (): void {
        Route::get(
            '/subjects',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumReadController::class,
                'subjects',
            ]
        );

        Route::get(
            '/subjects/{subjectId}/curricula',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumReadController::class,
                'curricula',
            ]
        )->whereUuid('subjectId');

        Route::get(
            '/curricula/{curriculumId}/versions',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumReadController::class,
                'versions',
            ]
        )->whereUuid('curriculumId');
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

    Route::prefix('practice-activities')->group(function (): void {
        Route::post(
            '/{practiceActivityId}/items',
            [
                \App\Http\Controllers\Api\Practice\PracticeActivityItemController::class,
                'store',
            ]
        )->whereUuid('practiceActivityId');

        Route::delete(
            '/{practiceActivityId}/items/{practiceActivityItemId}',
            [
                \App\Http\Controllers\Api\Practice\PracticeActivityItemController::class,
                'destroy',
            ]
        )
            ->whereUuid('practiceActivityId')
            ->whereUuid('practiceActivityItemId');
    });

    Route::post(
        '/exam-template-versions/{examTemplateVersionId}/generations',
        [
            \App\Http\Controllers\Api\Exam\ExamGenerationController::class,
            'store',
        ]
    )->whereUuid('examTemplateVersionId');
});

Route::middleware(['web', 'auth:web', 'active', 'learner'])->group(function (): void {
    Route::post(
        '/exam-generations/{examGenerationId}/attempts',
        [
            \App\Http\Controllers\Api\Attempt\AttemptConstructionController::class,
            'fromExam',
        ]
    )->whereUuid('examGenerationId');

    Route::post(
        '/practice-activities/{practiceActivityId}/attempts',
        [
            \App\Http\Controllers\Api\Attempt\AttemptConstructionController::class,
            'fromPractice',
        ]
    )->whereUuid('practiceActivityId');

    Route::put(
        '/attempt-items/{attemptItemId}/response',
        [
            \App\Http\Controllers\Api\Attempt\AttemptResponseController::class,
            'update',
        ]
    )->whereUuid('attemptItemId');


    Route::post(
        '/attempts/{attemptId}/finalize',
        [
            \App\Http\Controllers\Api\Attempt\AttemptFinalizationController::class,
            'update',
        ]
    )->whereUuid('attemptId');
});

Route::middleware(['web', 'management'])->group(function (): void {
    Route::post(
        '/attempt-responses/{attemptResponseId}/regrade-corrections',
        [
            \App\Http\Controllers\Api\Attempt\RegradeCorrectionController::class,
            'store',
        ]
    )->whereUuid('attemptResponseId');
});

Route::middleware(['web', 'auth:web', 'active', 'learner'])->group(function (): void {
    Route::post(
        '/lessons/{lessonId}/progress',
        [
            \App\Http\Controllers\Api\Learning\LessonProgressController::class,
            'start',
        ]
    )->whereUuid('lessonId');

    Route::post(
        '/lessons/{lessonId}/complete',
        [
            \App\Http\Controllers\Api\Learning\LessonProgressController::class,
            'complete',
        ]
    )->whereUuid('lessonId');

    Route::get(
        '/curriculum-versions/{curriculumVersionId}',
        [
            \App\Http\Controllers\Api\Read\CurriculumReadController::class,
            'showVersion',
        ]
    )->whereUuid('curriculumVersionId');

    Route::get(
        '/curriculum-versions/{curriculumVersionId}/lessons',
        [
            \App\Http\Controllers\Api\Read\CurriculumReadController::class,
            'lessons',
        ]
    )->whereUuid('curriculumVersionId');

    Route::get(
        '/lessons/{lessonId}',
        [
            \App\Http\Controllers\Api\Read\LearningReadController::class,
            'lesson',
        ]
    )->whereUuid('lessonId');

    Route::get(
        '/practice-activities/{practiceActivityId}',
        [
            \App\Http\Controllers\Api\Read\LearningReadController::class,
            'practiceActivity',
        ]
    )->whereUuid('practiceActivityId');
});

Route::middleware(['web', 'auth:web', 'active', 'learner'])->group(function (): void {
    Route::get(
        '/attempts',
        [
            \App\Http\Controllers\Api\Read\AttemptReadController::class,
            'index',
        ]
    );

    Route::get(
        '/attempts/{attemptId}',
        [
            \App\Http\Controllers\Api\Read\AttemptReadController::class,
            'show',
        ]
    )->whereUuid('attemptId');
});

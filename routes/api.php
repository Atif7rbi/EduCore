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
        Route::post(
            '/subjects',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumManagementController::class,
                'storeSubject',
            ]
        );

        Route::put(
            '/subjects/{subjectId}',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumManagementController::class,
                'updateSubject',
            ]
        )->whereUuid('subjectId');

        Route::post(
            '/subjects/{subjectId}/curricula',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumManagementController::class,
                'storeCurriculum',
            ]
        )->whereUuid('subjectId');

        Route::put(
            '/curricula/{curriculumId}',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumManagementController::class,
                'updateCurriculum',
            ]
        )->whereUuid('curriculumId');

        Route::post(
            '/curricula/{curriculumId}/versions',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumManagementController::class,
                'storeVersion',
            ]
        )->whereUuid('curriculumId');

        Route::put(
            '/curriculum-versions/{curriculumVersionId}',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumManagementController::class,
                'updateVersion',
            ]
        )->whereUuid('curriculumVersionId');

        Route::get(
            '/subjects',
            [
                \App\Http\Controllers\Api\Admin\AdminCurriculumReadController::class,
                'subjects',
            ]
        );

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/topics',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'topics',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/topics',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'storeTopic',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/topics/{topicId}',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'updateTopic',
            ]
        )->whereUuid('topicId');

        Route::get(
            '/skills',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'skills',
            ]
        );

        Route::post(
            '/skills',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'storeSkill',
            ]
        );

        Route::put(
            '/skills/{skillId}',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'updateSkill',
            ]
        )->whereUuid('skillId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/skill-placements',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'placements',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/skill-placements',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'storePlacement',
            ]
        )->whereUuid('curriculumVersionId');

        Route::delete(
            '/skill-placements/{placementId}',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'destroyPlacement',
            ]
        )->whereUuid('placementId');

        Route::post(
            '/skill-placements/{placementId}/home-topics',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'storeHomeTopic',
            ]
        )->whereUuid('placementId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/exam-templates',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'index',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/exam-templates',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'store',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/exam-templates/{examTemplateId}',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'update',
            ]
        )->whereUuid('examTemplateId');

        Route::post(
            '/exam-templates/{examTemplateId}/archive',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'archive',
            ]
        )->whereUuid('examTemplateId');

        Route::post(
            '/exam-templates/{examTemplateId}/activate',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'activate',
            ]
        )->whereUuid('examTemplateId');

        Route::get(
            '/exam-templates/{examTemplateId}/versions',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'versions',
            ]
        )->whereUuid('examTemplateId');

        Route::post(
            '/exam-templates/{examTemplateId}/versions',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'storeVersion',
            ]
        )->whereUuid('examTemplateId');

        Route::put(
            '/exam-template-versions/{examTemplateVersionId}',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'updateVersion',
            ]
        )->whereUuid('examTemplateVersionId');

        Route::post(
            '/exam-template-versions/{examTemplateVersionId}/publish',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'publishVersion',
            ]
        )->whereUuid('examTemplateVersionId');

        Route::post(
            '/exam-template-versions/{examTemplateVersionId}/retire',
            [
                \App\Http\Controllers\Api\Admin\AdminExamTemplateController::class,
                'retireVersion',
            ]
        )->whereUuid('examTemplateVersionId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/practice-activities',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'index',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/practice-activities',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'store',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/practice-activities/{practiceActivityId}',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'update',
            ]
        )->whereUuid('practiceActivityId');

        Route::post(
            '/practice-activities/{practiceActivityId}/activate',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'activate',
            ]
        )->whereUuid('practiceActivityId');

        Route::post(
            '/practice-activities/{practiceActivityId}/archive',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'archive',
            ]
        )->whereUuid('practiceActivityId');

        Route::get(
            '/practice-activities/{practiceActivityId}/items',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'items',
            ]
        )->whereUuid('practiceActivityId');

        Route::post(
            '/practice-activities/{practiceActivityId}/items',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'storeItem',
            ]
        )->whereUuid('practiceActivityId');

        Route::delete(
            '/practice-activities/{practiceActivityId}/items/{practiceActivityItemId}',
            [
                \App\Http\Controllers\Api\Admin\AdminPracticeActivityController::class,
                'destroyItem',
            ]
        )
            ->whereUuid('practiceActivityId')
            ->whereUuid('practiceActivityItemId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/assessment-items',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'items',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/assessment-items',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'storeItem',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/assessment-items/{assessmentItemId}',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'updateItem',
            ]
        )->whereUuid('assessmentItemId');

        Route::get(
            '/assessment-items/{assessmentItemId}/revisions',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'revisions',
            ]
        )->whereUuid('assessmentItemId');

        Route::post(
            '/assessment-items/{assessmentItemId}/revisions',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'storeRevision',
            ]
        )->whereUuid('assessmentItemId');

        Route::get(
            '/assessment-item-revisions/{assessmentItemRevisionId}/skills',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'revisionSkills',
            ]
        )->whereUuid('assessmentItemRevisionId');

        Route::post(
            '/assessment-item-revisions/{assessmentItemRevisionId}/skills',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'storeRevisionSkill',
            ]
        )->whereUuid('assessmentItemRevisionId');

        Route::delete(
            '/assessment-item-revisions/{assessmentItemRevisionId}/skills/{assessmentItemRevisionSkillId}',
            [
                \App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController::class,
                'destroyRevisionSkill',
            ]
        )
            ->whereUuid('assessmentItemRevisionId')
            ->whereUuid('assessmentItemRevisionSkillId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/lessons',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'lessons',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/lessons',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'storeLesson',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/lessons/{lessonId}',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'updateLesson',
            ]
        )->whereUuid('lessonId');

        Route::get(
            '/lessons/{lessonId}/revisions',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'revisions',
            ]
        )->whereUuid('lessonId');

        Route::post(
            '/lessons/{lessonId}/revisions',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'storeRevision',
            ]
        )->whereUuid('lessonId');

        Route::get(
            '/lesson-revisions/{lessonRevisionId}/skills',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'revisionSkills',
            ]
        )->whereUuid('lessonRevisionId');

        Route::post(
            '/lesson-revisions/{lessonRevisionId}/skills',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'storeRevisionSkill',
            ]
        )->whereUuid('lessonRevisionId');

        Route::delete(
            '/lesson-revisions/{lessonRevisionId}/skills/{lessonRevisionSkillId}',
            [
                \App\Http\Controllers\Api\Admin\AdminLessonAuthoringController::class,
                'destroyRevisionSkill',
            ]
        )
            ->whereUuid('lessonRevisionId')
            ->whereUuid('lessonRevisionSkillId');

        Route::delete(
            '/skill-placements/{placementId}/home-topics/{homeTopicId}',
            [
                \App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController::class,
                'destroyHomeTopic',
            ]
        )
            ->whereUuid('placementId')
            ->whereUuid('homeTopicId');

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
        '/progress/overview',
        [
            \App\Http\Controllers\Api\Read\ProgressReadController::class,
            'overview',
        ]
    );

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

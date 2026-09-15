<?php

use App\Http\Controllers\Api\Admin\AdminAssessmentAuthoringController;
use App\Http\Controllers\Api\Admin\AdminCurriculumManagementController;
use App\Http\Controllers\Api\Admin\AdminCurriculumReadController;
use App\Http\Controllers\Api\Admin\AdminCurriculumReadinessController;
use App\Http\Controllers\Api\Admin\AdminExamTemplateController;
use App\Http\Controllers\Api\Admin\AdminLessonAuthoringController;
use App\Http\Controllers\Api\Admin\AdminPracticeActivityController;
use App\Http\Controllers\Api\Admin\AdminTaxonomyManagementController;
use App\Http\Controllers\Api\Assessment\AssessmentItemLifecycleController;
use App\Http\Controllers\Api\Assessment\AssessmentItemRevisionLifecycleController;
use App\Http\Controllers\Api\Attempt\AttemptConstructionController;
use App\Http\Controllers\Api\Attempt\AttemptFinalizationController;
use App\Http\Controllers\Api\Attempt\AttemptResponseController;
use App\Http\Controllers\Api\Attempt\RegradeCorrectionController;
use App\Http\Controllers\Api\Curriculum\CurriculumVersionLifecycleController;
use App\Http\Controllers\Api\Enrollment\StudentEnrollmentLifecycleController;
use App\Http\Controllers\Api\Exam\ExamGenerationController;
use App\Http\Controllers\Api\Learning\LessonLifecycleController;
use App\Http\Controllers\Api\Learning\LessonProgressController;
use App\Http\Controllers\Api\Learning\LessonRevisionLifecycleController;
use App\Http\Controllers\Api\Practice\PracticeActivityItemController;
use App\Http\Controllers\Api\Read\AttemptReadController;
use App\Http\Controllers\Api\Read\CurriculumReadController;
use App\Http\Controllers\Api\Read\EvidenceScopeReadController;
use App\Http\Controllers\Api\Read\ExamReadController;
use App\Http\Controllers\Api\Read\LearningReadController;
use App\Http\Controllers\Api\Read\ProgressReadController;
use App\Http\Controllers\Api\Read\SkillAnalyticsReadController;
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
                AdminCurriculumManagementController::class,
                'storeSubject',
            ]
        );

        Route::put(
            '/subjects/{subjectId}',
            [
                AdminCurriculumManagementController::class,
                'updateSubject',
            ]
        )->whereUuid('subjectId');

        Route::post(
            '/subjects/{subjectId}/curricula',
            [
                AdminCurriculumManagementController::class,
                'storeCurriculum',
            ]
        )->whereUuid('subjectId');

        Route::put(
            '/curricula/{curriculumId}',
            [
                AdminCurriculumManagementController::class,
                'updateCurriculum',
            ]
        )->whereUuid('curriculumId');

        Route::post(
            '/curricula/{curriculumId}/versions',
            [
                AdminCurriculumManagementController::class,
                'storeVersion',
            ]
        )->whereUuid('curriculumId');

        Route::put(
            '/curriculum-versions/{curriculumVersionId}',
            [
                AdminCurriculumManagementController::class,
                'updateVersion',
            ]
        )->whereUuid('curriculumVersionId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/readiness',
            AdminCurriculumReadinessController::class
        )->whereUuid('curriculumVersionId');

        Route::get(
            '/subjects',
            [
                AdminCurriculumReadController::class,
                'subjects',
            ]
        );

        Route::get(
            '/education-stages',
            [
                AdminCurriculumReadController::class,
                'educationStages',
            ]
        );

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/topics',
            [
                AdminTaxonomyManagementController::class,
                'topics',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/topics',
            [
                AdminTaxonomyManagementController::class,
                'storeTopic',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/topics/{topicId}',
            [
                AdminTaxonomyManagementController::class,
                'updateTopic',
            ]
        )->whereUuid('topicId');

        Route::get(
            '/skills',
            [
                AdminTaxonomyManagementController::class,
                'skills',
            ]
        );

        Route::post(
            '/skills',
            [
                AdminTaxonomyManagementController::class,
                'storeSkill',
            ]
        );

        Route::put(
            '/skills/{skillId}',
            [
                AdminTaxonomyManagementController::class,
                'updateSkill',
            ]
        )->whereUuid('skillId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/skill-placements',
            [
                AdminTaxonomyManagementController::class,
                'placements',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/skill-placements',
            [
                AdminTaxonomyManagementController::class,
                'storePlacement',
            ]
        )->whereUuid('curriculumVersionId');

        Route::delete(
            '/skill-placements/{placementId}',
            [
                AdminTaxonomyManagementController::class,
                'destroyPlacement',
            ]
        )->whereUuid('placementId');

        Route::post(
            '/skill-placements/{placementId}/home-topics',
            [
                AdminTaxonomyManagementController::class,
                'storeHomeTopic',
            ]
        )->whereUuid('placementId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/exam-templates',
            [
                AdminExamTemplateController::class,
                'index',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/exam-templates',
            [
                AdminExamTemplateController::class,
                'store',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/exam-templates/{examTemplateId}',
            [
                AdminExamTemplateController::class,
                'update',
            ]
        )->whereUuid('examTemplateId');

        Route::post(
            '/exam-templates/{examTemplateId}/archive',
            [
                AdminExamTemplateController::class,
                'archive',
            ]
        )->whereUuid('examTemplateId');

        Route::post(
            '/exam-templates/{examTemplateId}/activate',
            [
                AdminExamTemplateController::class,
                'activate',
            ]
        )->whereUuid('examTemplateId');

        Route::get(
            '/exam-templates/{examTemplateId}/versions',
            [
                AdminExamTemplateController::class,
                'versions',
            ]
        )->whereUuid('examTemplateId');

        Route::post(
            '/exam-templates/{examTemplateId}/versions',
            [
                AdminExamTemplateController::class,
                'storeVersion',
            ]
        )->whereUuid('examTemplateId');

        Route::put(
            '/exam-template-versions/{examTemplateVersionId}',
            [
                AdminExamTemplateController::class,
                'updateVersion',
            ]
        )->whereUuid('examTemplateVersionId');

        Route::post(
            '/exam-template-versions/{examTemplateVersionId}/publish',
            [
                AdminExamTemplateController::class,
                'publishVersion',
            ]
        )->whereUuid('examTemplateVersionId');

        Route::post(
            '/exam-template-versions/{examTemplateVersionId}/retire',
            [
                AdminExamTemplateController::class,
                'retireVersion',
            ]
        )->whereUuid('examTemplateVersionId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/practice-activities',
            [
                AdminPracticeActivityController::class,
                'index',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/practice-activities',
            [
                AdminPracticeActivityController::class,
                'store',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/practice-activities/{practiceActivityId}',
            [
                AdminPracticeActivityController::class,
                'update',
            ]
        )->whereUuid('practiceActivityId');

        Route::post(
            '/practice-activities/{practiceActivityId}/activate',
            [
                AdminPracticeActivityController::class,
                'activate',
            ]
        )->whereUuid('practiceActivityId');

        Route::post(
            '/practice-activities/{practiceActivityId}/archive',
            [
                AdminPracticeActivityController::class,
                'archive',
            ]
        )->whereUuid('practiceActivityId');

        Route::get(
            '/practice-activities/{practiceActivityId}/items',
            [
                AdminPracticeActivityController::class,
                'items',
            ]
        )->whereUuid('practiceActivityId');

        Route::post(
            '/practice-activities/{practiceActivityId}/items',
            [
                AdminPracticeActivityController::class,
                'storeItem',
            ]
        )->whereUuid('practiceActivityId');

        Route::delete(
            '/practice-activities/{practiceActivityId}/items/{practiceActivityItemId}',
            [
                AdminPracticeActivityController::class,
                'destroyItem',
            ]
        )
            ->whereUuid('practiceActivityId')
            ->whereUuid('practiceActivityItemId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/assessment-items',
            [
                AdminAssessmentAuthoringController::class,
                'items',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/assessment-items',
            [
                AdminAssessmentAuthoringController::class,
                'storeItem',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/assessment-items/{assessmentItemId}',
            [
                AdminAssessmentAuthoringController::class,
                'updateItem',
            ]
        )->whereUuid('assessmentItemId');

        Route::get(
            '/assessment-items/{assessmentItemId}/revisions',
            [
                AdminAssessmentAuthoringController::class,
                'revisions',
            ]
        )->whereUuid('assessmentItemId');

        Route::post(
            '/assessment-items/{assessmentItemId}/revisions',
            [
                AdminAssessmentAuthoringController::class,
                'storeRevision',
            ]
        )->whereUuid('assessmentItemId');

        Route::get(
            '/assessment-item-revisions/{assessmentItemRevisionId}/skills',
            [
                AdminAssessmentAuthoringController::class,
                'revisionSkills',
            ]
        )->whereUuid('assessmentItemRevisionId');

        Route::post(
            '/assessment-item-revisions/{assessmentItemRevisionId}/skills',
            [
                AdminAssessmentAuthoringController::class,
                'storeRevisionSkill',
            ]
        )->whereUuid('assessmentItemRevisionId');

        Route::delete(
            '/assessment-item-revisions/{assessmentItemRevisionId}/skills/{assessmentItemRevisionSkillId}',
            [
                AdminAssessmentAuthoringController::class,
                'destroyRevisionSkill',
            ]
        )
            ->whereUuid('assessmentItemRevisionId')
            ->whereUuid('assessmentItemRevisionSkillId');

        Route::get(
            '/curriculum-versions/{curriculumVersionId}/lessons',
            [
                AdminLessonAuthoringController::class,
                'lessons',
            ]
        )->whereUuid('curriculumVersionId');

        Route::post(
            '/curriculum-versions/{curriculumVersionId}/lessons',
            [
                AdminLessonAuthoringController::class,
                'storeLesson',
            ]
        )->whereUuid('curriculumVersionId');

        Route::put(
            '/lessons/{lessonId}',
            [
                AdminLessonAuthoringController::class,
                'updateLesson',
            ]
        )->whereUuid('lessonId');

        Route::get(
            '/lessons/{lessonId}/revisions',
            [
                AdminLessonAuthoringController::class,
                'revisions',
            ]
        )->whereUuid('lessonId');

        Route::post(
            '/lessons/{lessonId}/revisions',
            [
                AdminLessonAuthoringController::class,
                'storeRevision',
            ]
        )->whereUuid('lessonId');

        Route::get(
            '/lesson-revisions/{lessonRevisionId}/skills',
            [
                AdminLessonAuthoringController::class,
                'revisionSkills',
            ]
        )->whereUuid('lessonRevisionId');

        Route::post(
            '/lesson-revisions/{lessonRevisionId}/skills',
            [
                AdminLessonAuthoringController::class,
                'storeRevisionSkill',
            ]
        )->whereUuid('lessonRevisionId');

        Route::delete(
            '/lesson-revisions/{lessonRevisionId}/skills/{lessonRevisionSkillId}',
            [
                AdminLessonAuthoringController::class,
                'destroyRevisionSkill',
            ]
        )
            ->whereUuid('lessonRevisionId')
            ->whereUuid('lessonRevisionSkillId');

        Route::delete(
            '/skill-placements/{placementId}/home-topics/{homeTopicId}',
            [
                AdminTaxonomyManagementController::class,
                'destroyHomeTopic',
            ]
        )
            ->whereUuid('placementId')
            ->whereUuid('homeTopicId');

        Route::get(
            '/subjects/{subjectId}/curricula',
            [
                AdminCurriculumReadController::class,
                'curricula',
            ]
        )->whereUuid('subjectId');

        Route::get(
            '/curricula/{curriculumId}/versions',
            [
                AdminCurriculumReadController::class,
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
                LessonRevisionLifecycleController::class,
                'release',
            ]
        )->whereUuid('lessonRevisionId');
    });

    Route::prefix('lessons')->group(function (): void {
        Route::post(
            '/{lessonId}/publish',
            [
                LessonLifecycleController::class,
                'publish',
            ]
        )->whereUuid('lessonId');

        Route::post(
            '/{lessonId}/unpublish',
            [
                LessonLifecycleController::class,
                'unpublish',
            ]
        )->whereUuid('lessonId');
    });

    Route::prefix('assessment-item-revisions')->group(function (): void {
        Route::post(
            '/{assessmentItemRevisionId}/release',
            [
                AssessmentItemRevisionLifecycleController::class,
                'release',
            ]
        )->whereUuid('assessmentItemRevisionId');
    });

    Route::prefix('assessment-items')->group(function (): void {
        Route::post(
            '/{assessmentItemId}/publish',
            [
                AssessmentItemLifecycleController::class,
                'publish',
            ]
        )->whereUuid('assessmentItemId');

        Route::post(
            '/{assessmentItemId}/retire',
            [
                AssessmentItemLifecycleController::class,
                'retire',
            ]
        )->whereUuid('assessmentItemId');
    });

    Route::prefix('practice-activities')->group(function (): void {
        Route::post(
            '/{practiceActivityId}/items',
            [
                PracticeActivityItemController::class,
                'store',
            ]
        )->whereUuid('practiceActivityId');

        Route::delete(
            '/{practiceActivityId}/items/{practiceActivityItemId}',
            [
                PracticeActivityItemController::class,
                'destroy',
            ]
        )
            ->whereUuid('practiceActivityId')
            ->whereUuid('practiceActivityItemId');
    });

    Route::post(
        '/exam-template-versions/{examTemplateVersionId}/generations',
        [
            ExamGenerationController::class,
            'store',
        ]
    )->whereUuid('examTemplateVersionId');
});

Route::middleware(['web', 'auth:web', 'active', 'student', 'learner'])->group(function (): void {
    Route::post(
        '/exam-generations/{examGenerationId}/attempts',
        [
            AttemptConstructionController::class,
            'fromExam',
        ]
    )->whereUuid('examGenerationId');

    Route::post(
        '/practice-activities/{practiceActivityId}/attempts',
        [
            AttemptConstructionController::class,
            'fromPractice',
        ]
    )->whereUuid('practiceActivityId');

    Route::put(
        '/attempt-items/{attemptItemId}/response',
        [
            AttemptResponseController::class,
            'update',
        ]
    )->whereUuid('attemptItemId');

    Route::post(
        '/attempts/{attemptId}/finalize',
        [
            AttemptFinalizationController::class,
            'update',
        ]
    )->whereUuid('attemptId');
});

Route::middleware(['web', 'management'])->group(function (): void {
    Route::post(
        '/attempt-responses/{attemptResponseId}/regrade-corrections',
        [
            RegradeCorrectionController::class,
            'store',
        ]
    )->whereUuid('attemptResponseId');
});

Route::middleware(['web', 'auth:web', 'active', 'student', 'learner'])->group(function (): void {
    Route::get(
        '/curricula',
        [
            CurriculumReadController::class,
            'index',
        ]
    );

    Route::get(
        '/exam-generations',
        [
            ExamReadController::class,
            'index',
        ]
    );

    Route::get(
        '/lessons/{lessonId}/progress',
        [
            LessonProgressController::class,
            'show',
        ]
    )->whereUuid('lessonId');

    Route::post(
        '/lessons/{lessonId}/progress',
        [
            LessonProgressController::class,
            'start',
        ]
    )->whereUuid('lessonId');

    Route::post(
        '/lessons/{lessonId}/complete',
        [
            LessonProgressController::class,
            'complete',
        ]
    )->whereUuid('lessonId');

    Route::get(
        '/curriculum-versions/{curriculumVersionId}',
        [
            CurriculumReadController::class,
            'showVersion',
        ]
    )->whereUuid('curriculumVersionId');

    Route::get(
        '/curriculum-versions/{curriculumVersionId}/lessons',
        [
            CurriculumReadController::class,
            'lessons',
        ]
    )->whereUuid('curriculumVersionId');

    Route::get(
        '/lessons/{lessonId}',
        [
            LearningReadController::class,
            'lesson',
        ]
    )->whereUuid('lessonId');

    Route::get(
        '/practice-activities/{practiceActivityId}',
        [
            LearningReadController::class,
            'practiceActivity',
        ]
    )->whereUuid('practiceActivityId');
});

Route::middleware(['web', 'auth:web', 'active', 'student', 'learner'])->group(function (): void {
    Route::get(
        '/progress/overview',
        [
            ProgressReadController::class,
            'overview',
        ]
    );

    Route::get(
        '/analytics/evidence-scopes',
        [
            EvidenceScopeReadController::class,
            'index',
        ]
    );

    Route::get(
        '/analytics/skills',
        [
            SkillAnalyticsReadController::class,
            'index',
        ]
    );

    Route::get(
        '/attempts',
        [
            AttemptReadController::class,
            'index',
        ]
    );

    Route::get(
        '/attempts/{attemptId}',
        [
            AttemptReadController::class,
            'show',
        ]
    )->whereUuid('attemptId');
});

Route::middleware([
    'web',
    'auth:web',
    'active',
    'student',
    'learner',
])->prefix('student')->group(function (): void {
    Route::post(
        '/enrollments',
        [
            StudentEnrollmentLifecycleController::class,
            'store',
        ]
    );
});

Route::middleware([
    'web',
    'auth:web',
    'active',
    'teacher',
])->prefix('teacher')->group(function (): void {
    Route::post(
        '/enrollments/{enrollmentId}/accept',
        [
            StudentEnrollmentLifecycleController::class,
            'accept',
        ]
    )->whereUuid('enrollmentId');

    Route::post(
        '/enrollments/{enrollmentId}/decline',
        [
            StudentEnrollmentLifecycleController::class,
            'decline',
        ]
    )->whereUuid('enrollmentId');

    Route::post(
        '/enrollments/{enrollmentId}/deactivate',
        [
            StudentEnrollmentLifecycleController::class,
            'deactivate',
        ]
    )->whereUuid('enrollmentId');
});

Route::middleware([
    'web',
    'management',
])->prefix('admin')->group(function (): void {
    Route::post(
        '/student-enrollments/{enrollmentId}/deactivate',
        [
            StudentEnrollmentLifecycleController::class,
            'deactivate',
        ]
    )->whereUuid('enrollmentId');
});

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'counts' => [
                'subjects' => DB::table('subjects')->count(),
                'curricula' => DB::table('curricula')->count(),
                'teacher_owned_curricula' => DB::table('curricula')
                    ->whereNotNull('teacher_subject_assignment_id')
                    ->count(),
                'legacy_ownerless_curricula' => DB::table('curricula')
                    ->whereNull('teacher_subject_assignment_id')
                    ->count(),
                'curriculum_versions' => DB::table('curriculum_versions')->count(),
                'topics' => DB::table('topics')->count(),
                'lessons' => DB::table('lessons')->count(),
                'skills' => DB::table('skills')->count(),
                'assessment_items' => DB::table('assessment_items')->count(),
                'practice_activities' => DB::table('practice_activities')->count(),
                'exam_templates' => DB::table('exam_templates')->count(),
                'learners' => DB::table('learner_profiles')->count(),
            ],
            'readiness' => [
                'published_curriculum_versions' => DB::table('curriculum_versions')
                    ->where('status', 'published')
                    ->count(),
                'published_teacher_owned_curriculum_versions' => DB::table(
                    'curriculum_versions as version'
                )
                    ->join(
                        'curricula as curriculum',
                        'curriculum.id',
                        '=',
                        'version.curriculum_id'
                    )
                    ->where('version.status', 'published')
                    ->whereNotNull(
                        'curriculum.teacher_subject_assignment_id'
                    )
                    ->count(),
                'published_legacy_ownerless_curriculum_versions' => DB::table(
                    'curriculum_versions as version'
                )
                    ->join(
                        'curricula as curriculum',
                        'curriculum.id',
                        '=',
                        'version.curriculum_id'
                    )
                    ->where('version.status', 'published')
                    ->whereNull(
                        'curriculum.teacher_subject_assignment_id'
                    )
                    ->count(),
                'published_lessons' => DB::table('lessons')
                    ->where('status', 'published')
                    ->count(),
                'active_practice_activities' => DB::table('practice_activities')
                    ->where('status', 'active')
                    ->count(),
                'active_exam_templates' => DB::table('exam_templates')
                    ->where('status', 'active')
                    ->count(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RequireManagementCurriculumReadOnly
{
    /**
     * Mutation route parameters whose resources are descendants
     * of a Curriculum.
     *
     * Historical ownerless Curricula retain the pre-Phase-E
     * management authoring behavior.
     *
     * Teacher-owned Curricula are read-only for management.
     */
    private const DESCENDANT_PARAMETERS = [
        'curriculumId' => 'curriculum',
        'curriculumVersionId' => 'curriculum_version',
        'topicId' => 'topic',
        'placementId' => 'skill_version_placement',
        'examTemplateId' => 'exam_template',
        'examTemplateVersionId' => 'exam_template_version',
        'practiceActivityId' => 'practice_activity',
        'assessmentItemId' => 'assessment_item',
        'assessmentItemRevisionId' => 'assessment_item_revision',
        'lessonId' => 'lesson',
        'lessonRevisionId' => 'lesson_revision',
    ];

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        if (
            in_array(
                $request->method(),
                ['GET', 'HEAD', 'OPTIONS'],
                true,
            )
        ) {
            return $next($request);
        }

        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        /*
         * Preserve the already frozen Phase E Curriculum-root
         * response:
         *
         * 409 admin_curriculum_authoring_disabled
         */
        if (
            str_ends_with(
                $route->getActionName(),
                'AdminCurriculumManagementController@updateCurriculum',
            )
        ) {
            return $next($request);
        }

        foreach (
            self::DESCENDANT_PARAMETERS as $parameter => $resourceType
        ) {
            $resourceId = $route->parameter($parameter);

            if (
                ! is_string($resourceId)
                || $resourceId === ''
            ) {
                continue;
            }

            $ownership = $this->resolveOwnership(
                $resourceType,
                $resourceId,
            );

            /*
             * Fail closed if a protected mutation does not resolve
             * to a Curriculum.
             */
            if ($ownership === null) {
                return $this->forbidden();
            }

            /*
             * Historical ownerless Curricula retain legacy
             * management authoring behavior.
             */
            if (
                $ownership->teacher_subject_assignment_id
                === null
            ) {
                return $next($request);
            }

            return $this->forbidden();
        }

        /*
         * Global/platform management operations remain unchanged.
         */
        return $next($request);
    }

    private function resolveOwnership(
        string $resourceType,
        string $resourceId,
    ): ?object {
        if ($resourceType === 'curriculum') {
            return DB::table('curricula')
                ->where('id', $resourceId)
                ->select([
                    'teacher_subject_assignment_id',
                ])
                ->first();
        }

        if ($resourceType === 'curriculum_version') {
            return DB::table(
                'curriculum_versions as resource'
            )
                ->join(
                    'curricula as curriculum',
                    'curriculum.id',
                    '=',
                    'resource.curriculum_id',
                )
                ->where(
                    'resource.id',
                    $resourceId,
                )
                ->select([
                    'curriculum.teacher_subject_assignment_id',
                ])
                ->first();
        }

        $table = match ($resourceType) {
            'topic' => 'topics',
            'skill_version_placement' => 'skill_version_placements',
            'exam_template' => 'exam_templates',
            'exam_template_version' => 'exam_template_versions',
            'practice_activity' => 'practice_activities',
            'assessment_item' => 'assessment_items',
            'assessment_item_revision' => 'assessment_item_revisions',
            'lesson' => 'lessons',
            'lesson_revision' => 'lesson_revisions',
            default => null,
        };

        if ($table === null) {
            return null;
        }

        return DB::table(
            "{$table} as resource"
        )
            ->join(
                'curriculum_versions as version',
                'version.id',
                '=',
                'resource.curriculum_version_id',
            )
            ->join(
                'curricula as curriculum',
                'curriculum.id',
                '=',
                'version.curriculum_id',
            )
            ->where(
                'resource.id',
                $resourceId,
            )
            ->select([
                'curriculum.teacher_subject_assignment_id',
            ])
            ->first();
    }

    private function forbidden(): Response
    {
        return ApiResponse::error(
            'admin_curriculum_content_read_only',
            'Teacher-owned curriculum content is read-only for management users.',
            Response::HTTP_FORBIDDEN,
        );
    }
}

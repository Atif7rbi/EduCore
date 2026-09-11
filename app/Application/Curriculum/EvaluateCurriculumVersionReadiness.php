<?php

namespace App\Application\Curriculum;

use App\Models\CurriculumVersion;
use Illuminate\Support\Facades\DB;

class EvaluateCurriculumVersionReadiness
{
    public function execute(string $curriculumVersionId): array
    {
        $version = CurriculumVersion::query()
            ->findOrFail($curriculumVersionId);

        return $this->evaluate($version);
    }

    public function evaluate(
        CurriculumVersion $version,
    ): array {
        $versionId = $version->id;

        $activePracticeActivities =
            DB::table('practice_activities')
                ->where(
                    'curriculum_version_id',
                    $versionId
                )
                ->where('status', 'active')
                ->count();

        $learnerUsablePracticeActivities =
            DB::table('practice_activities as pa')
                ->leftJoin(
                    'lessons as l',
                    'l.id',
                    '=',
                    'pa.lesson_id'
                )
                ->where(
                    'pa.curriculum_version_id',
                    $versionId
                )
                ->where('pa.status', 'active')
                ->where(function ($query): void {
                    $query
                        ->whereNull('pa.lesson_id')
                        ->orWhere(function ($lessonQuery): void {
                            $lessonQuery
                                ->where(
                                    'l.status',
                                    'published'
                                )
                                ->whereNotNull(
                                    'l.published_revision_id'
                                );
                        });
                })
                ->count();

        $activePracticeHiddenByLesson =
            DB::table('practice_activities as pa')
                ->join(
                    'lessons as l',
                    'l.id',
                    '=',
                    'pa.lesson_id'
                )
                ->where(
                    'pa.curriculum_version_id',
                    $versionId
                )
                ->where('pa.status', 'active')
                ->where(function ($query): void {
                    $query
                        ->where(
                            'l.status',
                            '<>',
                            'published'
                        )
                        ->orWhereNull(
                            'l.published_revision_id'
                        );
                })
                ->count();

        $activeExamTemplates =
            DB::table('exam_templates')
                ->where(
                    'curriculum_version_id',
                    $versionId
                )
                ->where('status', 'active')
                ->count();

        $usableExamTemplates =
            DB::table('exam_templates as et')
                ->join(
                    'exam_template_versions as etv',
                    'etv.id',
                    '=',
                    'et.published_version_id'
                )
                ->where(
                    'et.curriculum_version_id',
                    $versionId
                )
                ->where('et.status', 'active')
                ->where(
                    'etv.status',
                    'published'
                )
                ->count();

        $counts = [
            'topics' =>
                DB::table('topics')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->count(),

            'skill_placements' =>
                DB::table('skill_version_placements')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->count(),

            'lessons' =>
                DB::table('lessons')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->count(),

            'published_lessons' =>
                DB::table('lessons')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'published')
                    ->count(),

            'draft_lessons' =>
                DB::table('lessons')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'draft')
                    ->count(),

            'unpublished_lessons' =>
                DB::table('lessons')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'unpublished')
                    ->count(),

            'assessment_items' =>
                DB::table('assessment_items')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->count(),

            'published_assessment_items' =>
                DB::table('assessment_items')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'published')
                    ->count(),

            'draft_assessment_items' =>
                DB::table('assessment_items')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'draft')
                    ->count(),

            'retired_assessment_items' =>
                DB::table('assessment_items')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'retired')
                    ->count(),

            'practice_activities' =>
                DB::table('practice_activities')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->count(),

            'active_practice_activities' =>
                $activePracticeActivities,

            'learner_usable_practice_activities' =>
                $learnerUsablePracticeActivities,

            'archived_practice_activities' =>
                DB::table('practice_activities')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'archived')
                    ->count(),

            'active_practice_hidden_by_lesson' =>
                $activePracticeHiddenByLesson,

            'exam_templates' =>
                DB::table('exam_templates')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->count(),

            'active_exam_templates' =>
                $activeExamTemplates,

            'usable_exam_templates' =>
                $usableExamTemplates,

            'archived_exam_templates' =>
                DB::table('exam_templates')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'archived')
                    ->count(),

            'active_exam_templates_without_published_version' =>
                max(
                    0,
                    $activeExamTemplates -
                    $usableExamTemplates
                ),

            'draft_exam_template_versions' =>
                DB::table('exam_template_versions')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'draft')
                    ->count(),

            'published_exam_template_versions' =>
                DB::table('exam_template_versions')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'published')
                    ->count(),

            'retired_exam_template_versions' =>
                DB::table('exam_template_versions')
                    ->where(
                        'curriculum_version_id',
                        $versionId
                    )
                    ->where('status', 'retired')
                    ->count(),
        ];

        $checks = [
            $this->check(
                'curriculum_version_is_draft',
                'Curriculum version must be draft.',
                $version->status === 'draft',
                $version->status,
            ),
            $this->check(
                'has_topic',
                'At least one topic is required.',
                $counts['topics'] >= 1,
                $counts['topics'],
            ),
            $this->check(
                'has_skill_placement',
                'At least one skill placement is required.',
                $counts['skill_placements'] >= 1,
                $counts['skill_placements'],
            ),
            $this->check(
                'has_published_lesson',
                'At least one published lesson is required.',
                $counts['published_lessons'] >= 1,
                $counts['published_lessons'],
            ),
            $this->check(
                'has_published_assessment_item',
                'At least one published assessment item is required.',
                $counts['published_assessment_items'] >= 1,
                $counts['published_assessment_items'],
            ),
            $this->check(
                'has_learner_usable_practice',
                'At least one learner-usable active practice activity is required.',
                $counts[
                    'learner_usable_practice_activities'
                ] >= 1,
                $counts[
                    'learner_usable_practice_activities'
                ],
            ),
            $this->check(
                'has_usable_exam_template',
                'At least one active exam template with a published version is required.',
                $counts['usable_exam_templates'] >= 1,
                $counts['usable_exam_templates'],
            ),
        ];

        $blockers = [];

        foreach ($checks as $check) {
            if (! $check['passed']) {
                $blockers[] = [
                    'code' => $check['code'],
                    'message' => $check['message'],
                    'value' => $check['value'],
                ];
            }
        }

        $warnings = [];

        $this->appendWarning(
            $warnings,
            'draft_lessons',
            'Draft lessons will not be visible to learners.',
            $counts['draft_lessons'],
        );

        $this->appendWarning(
            $warnings,
            'unpublished_lessons',
            'Unpublished lessons will not be visible to learners.',
            $counts['unpublished_lessons'],
        );

        $this->appendWarning(
            $warnings,
            'draft_assessment_items',
            'Draft assessment items are excluded from published learner content.',
            $counts['draft_assessment_items'],
        );

        $this->appendWarning(
            $warnings,
            'retired_assessment_items',
            'Retired assessment items are retained as historical content.',
            $counts['retired_assessment_items'],
        );

        $this->appendWarning(
            $warnings,
            'archived_practice_activities',
            'Archived practice activities are excluded from learner content.',
            $counts['archived_practice_activities'],
        );

        $this->appendWarning(
            $warnings,
            'active_practice_hidden_by_lesson',
            'Active practice activities linked to an unpublished lesson are hidden from learners.',
            $counts['active_practice_hidden_by_lesson'],
        );

        $this->appendWarning(
            $warnings,
            'archived_exam_templates',
            'Archived exam templates are excluded from active learner content.',
            $counts['archived_exam_templates'],
        );

        $this->appendWarning(
            $warnings,
            'active_exam_templates_without_published_version',
            'Active exam templates without a usable published version are not learner-ready.',
            $counts[
                'active_exam_templates_without_published_version'
            ],
        );

        $this->appendWarning(
            $warnings,
            'draft_exam_template_versions',
            'Draft exam template versions remain unpublished.',
            $counts['draft_exam_template_versions'],
        );

        $this->appendWarning(
            $warnings,
            'retired_exam_template_versions',
            'Retired exam template versions are retained as historical content.',
            $counts['retired_exam_template_versions'],
        );

        return [
            'curriculum_version' => [
                'id' => $version->id,
                'curriculum_id' =>
                    $version->curriculum_id,
                'version_number' =>
                    $version->version_number,
                'label' => $version->label,
                'status' => $version->status,
            ],
            'ready_to_publish' =>
                count($blockers) === 0,
            'checks' => $checks,
            'counts' => $counts,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    private function check(
        string $code,
        string $message,
        bool $passed,
        string|int $value,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'passed' => $passed,
            'value' => $value,
        ];
    }

    private function appendWarning(
        array &$warnings,
        string $code,
        string $message,
        int $value,
    ): void {
        if ($value < 1) {
            return;
        }

        $warnings[] = [
            'code' => $code,
            'message' => $message,
            'value' => $value,
        ];
    }
}

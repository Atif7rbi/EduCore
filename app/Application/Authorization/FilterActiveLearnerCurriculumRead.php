<?php

namespace App\Application\Authorization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class FilterActiveLearnerCurriculumRead
{
    public function curricula(
        Builder $query,
        string $learnerProfileId,
    ): Builder {
        return $query
            ->whereNotNull(
                'curricula.teacher_subject_assignment_id',
            )
            ->whereExists(
                function (
                    QueryBuilder $grant
                ) use ($learnerProfileId): void {
                    $grant
                        ->selectRaw('1')
                        ->from(
                            'teacher_subject_assignments',
                        )
                        ->join(
                            'student_enrollments',
                            'student_enrollments.teacher_subject_assignment_id',
                            '=',
                            'teacher_subject_assignments.id',
                        )
                        ->whereColumn(
                            'teacher_subject_assignments.id',
                            'curricula.teacher_subject_assignment_id',
                        )
                        ->where(
                            'teacher_subject_assignments.status',
                            'active',
                        )
                        ->where(
                            'student_enrollments.learner_profile_id',
                            $learnerProfileId,
                        )
                        ->where(
                            'student_enrollments.status',
                            'active',
                        );
                },
            );
    }

    public function versions(
        Builder $query,
        string $learnerProfileId,
    ): Builder {
        return $query->whereHas(
            'curriculum',
            function (
                Builder $curricula
            ) use ($learnerProfileId): void {
                $this->curricula(
                    $curricula,
                    $learnerProfileId,
                );
            },
        );
    }
}

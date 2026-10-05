<?php

namespace App\Application\TeacherAuthoring;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class FilterActiveTeacherCurriculumRead
{
    public function curricula(
        Builder $query,
        string $actorUserId,
    ): Builder {
        return $query
            ->whereNotNull(
                'curricula.teacher_subject_assignment_id',
            )
            ->whereExists(
                function (
                    QueryBuilder $authority
                ) use ($actorUserId): void {
                    $authority
                        ->selectRaw('1')
                        ->from('teacher_subject_assignments')
                        ->join(
                            'users',
                            'users.id',
                            '=',
                            'teacher_subject_assignments.teacher_id',
                        )
                        ->join(
                            'subjects',
                            'subjects.id',
                            '=',
                            'teacher_subject_assignments.subject_id',
                        )
                        ->whereColumn(
                            'teacher_subject_assignments.id',
                            'curricula.teacher_subject_assignment_id',
                        )
                        ->whereColumn(
                            'teacher_subject_assignments.subject_id',
                            'curricula.subject_id',
                        )
                        ->where(
                            'teacher_subject_assignments.teacher_id',
                            $actorUserId,
                        )
                        ->where(
                            'teacher_subject_assignments.status',
                            'active',
                        )
                        ->where(
                            'users.id',
                            $actorUserId,
                        )
                        ->where('users.role', 'teacher')
                        ->where('users.status', 'active')
                        ->whereNotNull('subjects.code')
                        ->where('subjects.status', 'active');
                },
            );
    }

    public function versions(
        Builder $query,
        string $actorUserId,
    ): Builder {
        return $query->whereHas(
            'curriculum',
            function (
                Builder $curricula
            ) use ($actorUserId): void {
                $this->curricula(
                    $curricula,
                    $actorUserId,
                );
            },
        );
    }
}

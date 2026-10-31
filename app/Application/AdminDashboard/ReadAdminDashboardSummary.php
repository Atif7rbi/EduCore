<?php

namespace App\Application\AdminDashboard;

use Illuminate\Database\Connection;

class ReadAdminDashboardSummary
{
    public function read(Connection $connection): array
    {
        $snapshot = $connection->selectOne(
            <<<'SQL'
SELECT to_char(
    transaction_timestamp() AT TIME ZONE 'UTC',
    'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'
) AS snapshot_at
SQL
        );

        $counts = [
            'subjects' => $connection->table('subjects')->count(),
            'curricula' => $connection->table('curricula')->count(),
            'teacher_owned_curricula' => $connection
                ->table('curricula')
                ->whereNotNull('teacher_subject_assignment_id')
                ->count(),
            'legacy_ownerless_curricula' => $connection
                ->table('curricula')
                ->whereNull('teacher_subject_assignment_id')
                ->count(),
            'curriculum_versions' => $connection
                ->table('curriculum_versions')
                ->count(),
            'topics' => $connection->table('topics')->count(),
            'lessons' => $connection->table('lessons')->count(),
            'skills' => $connection->table('skills')->count(),
            'assessment_items' => $connection
                ->table('assessment_items')
                ->count(),
            'practice_activities' => $connection
                ->table('practice_activities')
                ->count(),
            'exam_templates' => $connection
                ->table('exam_templates')
                ->count(),
            'learners' => $connection
                ->table('learner_profiles')
                ->count(),
        ];

        $readiness = [
            'published_curriculum_versions' => $connection
                ->table('curriculum_versions')
                ->where('status', 'published')
                ->count(),
            'published_teacher_owned_curriculum_versions' => $connection
                ->table('curriculum_versions as version')
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
            'published_legacy_ownerless_curriculum_versions' => $connection
                ->table('curriculum_versions as version')
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
            'published_lessons' => $connection
                ->table('lessons')
                ->where('status', 'published')
                ->count(),
            'active_practice_activities' => $connection
                ->table('practice_activities')
                ->where('status', 'active')
                ->count(),
            'active_exam_templates' => $connection
                ->table('exam_templates')
                ->where('status', 'active')
                ->count(),
        ];

        $accounts = $connection->table('users')
            ->selectRaw(
                <<<'SQL'
COUNT(*) FILTER (
    WHERE role = 'teacher' AND status = 'active'
) AS teacher_active,
COUNT(*) FILTER (
    WHERE role = 'teacher' AND status = 'disabled'
) AS teacher_disabled,
COUNT(*) FILTER (
    WHERE role = 'student' AND status = 'active'
) AS student_active,
COUNT(*) FILTER (
    WHERE role = 'student' AND status = 'disabled'
) AS student_disabled
SQL
            )
            ->first();

        $assignments = $connection
            ->table('teacher_subject_assignments')
            ->selectRaw(
                <<<'SQL'
COUNT(*) FILTER (
    WHERE status = 'active'
) AS active,
COUNT(*) FILTER (
    WHERE status = 'inactive'
) AS inactive
SQL
            )
            ->first();

        $enrollments = $connection
            ->table('student_enrollments')
            ->selectRaw(
                <<<'SQL'
COUNT(*) FILTER (
    WHERE status = 'pending'
) AS pending,
COUNT(*) FILTER (
    WHERE status = 'active'
) AS active,
COUNT(*) FILTER (
    WHERE status = 'inactive'
) AS inactive
SQL
            )
            ->first();

        $teacherActive = (int) ($accounts->teacher_active ?? 0);
        $teacherDisabled = (int) ($accounts->teacher_disabled ?? 0);
        $studentActive = (int) ($accounts->student_active ?? 0);
        $studentDisabled = (int) ($accounts->student_disabled ?? 0);
        $assignmentActive = (int) ($assignments->active ?? 0);
        $assignmentInactive = (int) ($assignments->inactive ?? 0);
        $enrollmentPending = (int) ($enrollments->pending ?? 0);
        $enrollmentActive = (int) ($enrollments->active ?? 0);
        $enrollmentInactive = (int) ($enrollments->inactive ?? 0);

        return [
            'counts' => $counts,
            'readiness' => $readiness,
            'snapshot_at' => (string) ($snapshot->snapshot_at ?? ''),
            'operations' => [
                'teacher_accounts' => [
                    'active' => $teacherActive,
                    'disabled' => $teacherDisabled,
                    'total' => $teacherActive + $teacherDisabled,
                ],
                'student_accounts' => [
                    'active' => $studentActive,
                    'disabled' => $studentDisabled,
                    'total' => $studentActive + $studentDisabled,
                ],
                'teacher_subject_assignments' => [
                    'active' => $assignmentActive,
                    'inactive' => $assignmentInactive,
                    'total' => $assignmentActive + $assignmentInactive,
                ],
                'student_enrollments' => [
                    'pending' => $enrollmentPending,
                    'active' => $enrollmentActive,
                    'inactive' => $enrollmentInactive,
                    'total' => $enrollmentPending
                        + $enrollmentActive
                        + $enrollmentInactive,
                ],
            ],
        ];
    }
}

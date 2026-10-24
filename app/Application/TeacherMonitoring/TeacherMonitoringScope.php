<?php

namespace App\Application\TeacherMonitoring;

use App\Models\Attempt;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class TeacherMonitoringScope
{
    public function resolveFinalizedAttempt(
        string $authenticatedTeacherUserId,
        string $studentEnrollmentId,
        string $attemptId,
    ): TeacherMonitoringContext {
        $this->ensureActiveTeacher($authenticatedTeacherUserId);

        $scope = $this->resolutionQuery(
            $authenticatedTeacherUserId,
        )
            ->join(
                'attempts as monitoring_attempt_identity',
                function (JoinClause $join): void {
                    $join->on(
                        'monitoring_attempt_identity.learner_profile_id',
                        '=',
                        'learner_profiles.id',
                    )->on(
                        'monitoring_attempt_identity.curriculum_version_id',
                        '=',
                        'curriculum_versions.id',
                    );
                },
            )
            ->where('student_enrollments.id', $studentEnrollmentId)
            ->where('monitoring_attempt_identity.id', $attemptId)
            ->whereIn(
                'monitoring_attempt_identity.status',
                ['submitted', 'abandoned'],
            )
            ->first($this->contextColumns());

        return $this->contextFromRow($scope, $studentEnrollmentId);
    }

    public function resolveEnrollment(
        string $authenticatedTeacherUserId,
        string $studentEnrollmentId,
        string $curriculumVersionId,
    ): TeacherMonitoringContext {
        $this->ensureActiveTeacher($authenticatedTeacherUserId);

        $scope = $this->resolutionQuery(
            $authenticatedTeacherUserId,
            $curriculumVersionId,
        )
            ->where('student_enrollments.id', $studentEnrollmentId)
            ->first($this->contextColumns());

        return $this->contextFromRow($scope, $studentEnrollmentId);
    }

    public function resolve(
        string $authenticatedTeacherUserId,
        string $teacherSubjectAssignmentId,
        string $studentEnrollmentId,
        string $studentUserId,
        string $curriculumVersionId,
    ): TeacherMonitoringContext {
        $this->ensureActiveTeacher($authenticatedTeacherUserId);

        /*
         * This is the authoritative read boundary. Every identity and
         * lifecycle fact is joined and constrained in one query so a
         * caller cannot gain access from a UUID or Curriculum owner alone.
         */
        $scope = $this->resolutionQuery(
            $authenticatedTeacherUserId,
            $curriculumVersionId,
        )
            ->where('student_enrollments.id', $studentEnrollmentId)
            ->where(
                'student_enrollments.teacher_subject_assignment_id',
                $teacherSubjectAssignmentId,
            )
            ->where('student_users.id', $studentUserId)
            ->where(
                'teacher_subject_assignments.id',
                $teacherSubjectAssignmentId,
            )
            ->first($this->contextColumns());

        return $this->contextFromRow($scope, $studentEnrollmentId);
    }

    /**
     * @param  Builder<Attempt>  $query
     * @return Builder<Attempt>
     */
    public function finalizedAttempts(
        Builder $query,
        string $authenticatedTeacherUserId,
        TeacherMonitoringContext $scope,
    ): Builder {
        return $query
            ->where('attempts.learner_profile_id', $scope->learnerProfileId)
            ->where('attempts.curriculum_version_id', $scope->curriculumVersionId)
            ->whereIn('attempts.status', ['submitted', 'abandoned'])
            ->whereExists(function (QueryBuilder $authority) use (
                $authenticatedTeacherUserId,
                $scope,
            ): void {
                $this->currentAuthority(
                    $authority,
                    $authenticatedTeacherUserId,
                    $scope,
                )->selectRaw('1')->whereColumn(
                    'monitoring_enrollments.learner_profile_id',
                    'attempts.learner_profile_id',
                )->whereColumn(
                    'monitoring_versions.id',
                    'attempts.curriculum_version_id',
                );
            });
    }

    /**
     * @param  Builder<Lesson>  $query
     * @return Builder<Lesson>
     */
    public function releasedLessons(
        Builder $query,
        string $authenticatedTeacherUserId,
        TeacherMonitoringContext $scope,
    ): Builder {
        return $query
            ->where(
                'lessons.curriculum_version_id',
                $scope->curriculumVersionId,
            )
            ->where('lessons.status', 'published')
            ->whereNotNull('lessons.published_revision_id')
            ->whereHas(
                'publishedRevision',
                fn (Builder $revisions): Builder => $revisions
                    ->where(
                        'lesson_revisions.curriculum_version_id',
                        $scope->curriculumVersionId,
                    )
                    ->whereNotNull('lesson_revisions.released_at'),
            )
            ->whereExists(function (QueryBuilder $authority) use (
                $authenticatedTeacherUserId,
                $scope,
            ): void {
                $this->currentAuthority(
                    $authority,
                    $authenticatedTeacherUserId,
                    $scope,
                )->selectRaw('1')->whereColumn(
                    'monitoring_versions.id',
                    'lessons.curriculum_version_id',
                );
            });
    }

    public function authorizedSummary(
        string $authenticatedTeacherUserId,
        TeacherMonitoringContext $scope,
    ): object {
        $summary = $this->currentAuthority(
            DB::query(),
            $authenticatedTeacherUserId,
            $scope,
        )->first([
            'monitoring_enrollments.id as enrollment_id',
            'monitoring_students.id as student_user_id',
            'monitoring_students.name as student_name',
            'monitoring_assignments.id as assignment_id',
            'monitoring_subjects.id as subject_id',
            'monitoring_subjects.code as subject_code',
            'monitoring_subjects.name as subject_name',
            'monitoring_curricula.id as curriculum_id',
            'monitoring_curricula.name as curriculum_name',
            'monitoring_versions.id as curriculum_version_id',
            'monitoring_versions.version_number',
            'monitoring_versions.label as version_label',
            'monitoring_versions.status as version_status',
        ]);

        if ($summary === null) {
            throw (new ModelNotFoundException)->setModel(
                StudentEnrollment::class,
                [$scope->studentEnrollmentId],
            );
        }

        return $summary;
    }

    /**
     * @param  Builder<LessonProgress>  $query
     * @return Builder<LessonProgress>
     */
    public function lessonProgresses(
        Builder $query,
        string $authenticatedTeacherUserId,
        TeacherMonitoringContext $scope,
    ): Builder {
        return $query
            ->where(
                'lesson_progresses.learner_profile_id',
                $scope->learnerProfileId,
            )
            ->whereHas(
                'lessonRevision',
                fn (Builder $revisions): Builder => $revisions
                    ->where(
                        'lesson_revisions.curriculum_version_id',
                        $scope->curriculumVersionId,
                    )
                    ->whereNotNull('lesson_revisions.released_at'),
            )
            ->whereExists(function (QueryBuilder $authority) use (
                $authenticatedTeacherUserId,
                $scope,
            ): void {
                $this->currentAuthority(
                    $authority,
                    $authenticatedTeacherUserId,
                    $scope,
                )->selectRaw('1')->whereColumn(
                    'monitoring_enrollments.learner_profile_id',
                    'lesson_progresses.learner_profile_id',
                );
            });
    }

    /**
     * Reasserts the complete current authorization relationship inside the
     * resource query. The context carries identifiers; it is not proof of
     * authority. The actor ID must come from the authenticated principal.
     */
    private function currentAuthority(
        QueryBuilder $authority,
        string $authenticatedTeacherUserId,
        TeacherMonitoringContext $scope,
    ): QueryBuilder {
        return $authority
            ->from('student_enrollments as monitoring_enrollments')
            ->join(
                'learner_profiles as monitoring_learners',
                'monitoring_learners.id',
                '=',
                'monitoring_enrollments.learner_profile_id',
            )
            ->join(
                'users as monitoring_students',
                'monitoring_students.id',
                '=',
                'monitoring_learners.user_id',
            )
            ->join(
                'teacher_subject_assignments as monitoring_assignments',
                'monitoring_assignments.id',
                '=',
                'monitoring_enrollments.teacher_subject_assignment_id',
            )
            ->join(
                'users as monitoring_teachers',
                'monitoring_teachers.id',
                '=',
                'monitoring_assignments.teacher_id',
            )
            ->join(
                'subjects as monitoring_subjects',
                'monitoring_subjects.id',
                '=',
                'monitoring_assignments.subject_id',
            )
            ->join(
                'curricula as monitoring_curricula',
                'monitoring_curricula.teacher_subject_assignment_id',
                '=',
                'monitoring_assignments.id',
            )
            ->join(
                'curriculum_versions as monitoring_versions',
                'monitoring_versions.curriculum_id',
                '=',
                'monitoring_curricula.id',
            )
            ->where(
                'monitoring_teachers.id',
                $authenticatedTeacherUserId,
            )
            ->where(
                'monitoring_teachers.id',
                $scope->teacherUserId,
            )
            ->where('monitoring_teachers.role', 'teacher')
            ->where('monitoring_teachers.status', 'active')
            ->where(
                'monitoring_assignments.id',
                $scope->teacherSubjectAssignmentId,
            )
            ->where(
                'monitoring_assignments.teacher_id',
                $authenticatedTeacherUserId,
            )
            ->where('monitoring_assignments.status', 'active')
            ->where(
                'monitoring_enrollments.id',
                $scope->studentEnrollmentId,
            )
            ->where(
                'monitoring_enrollments.teacher_subject_assignment_id',
                $scope->teacherSubjectAssignmentId,
            )
            ->where('monitoring_enrollments.status', 'active')
            ->where(
                'monitoring_learners.id',
                $scope->learnerProfileId,
            )
            ->where(
                'monitoring_students.id',
                $scope->studentUserId,
            )
            ->where('monitoring_students.role', 'student')
            ->where('monitoring_students.status', 'active')
            ->where(
                'monitoring_curricula.id',
                $scope->curriculumId,
            )
            ->where(
                'monitoring_curricula.subject_id',
                $scope->subjectId,
            )
            ->whereColumn(
                'monitoring_curricula.subject_id',
                'monitoring_assignments.subject_id',
            )
            ->where(
                'monitoring_versions.id',
                $scope->curriculumVersionId,
            )
            ->whereIn(
                'monitoring_versions.status',
                ['published', 'retired'],
            );
    }

    private function ensureActiveTeacher(
        string $authenticatedTeacherUserId,
    ): void {
        $isActiveTeacher = User::query()
            ->whereKey($authenticatedTeacherUserId)
            ->where('role', 'teacher')
            ->where('status', 'active')
            ->exists();

        if (! $isActiveTeacher) {
            throw new AuthorizationException(
                'Teacher monitoring access is not permitted.',
            );
        }
    }

    private function resolutionQuery(
        string $authenticatedTeacherUserId,
        ?string $curriculumVersionId = null,
    ): QueryBuilder {
        return DB::table('student_enrollments')
            ->join(
                'learner_profiles',
                'learner_profiles.id',
                '=',
                'student_enrollments.learner_profile_id',
            )
            ->join(
                'users as student_users',
                'student_users.id',
                '=',
                'learner_profiles.user_id',
            )
            ->join(
                'teacher_subject_assignments',
                'teacher_subject_assignments.id',
                '=',
                'student_enrollments.teacher_subject_assignment_id',
            )
            ->join(
                'users as teacher_users',
                'teacher_users.id',
                '=',
                'teacher_subject_assignments.teacher_id',
            )
            ->join(
                'curricula',
                'curricula.teacher_subject_assignment_id',
                '=',
                'teacher_subject_assignments.id',
            )
            ->join(
                'curriculum_versions',
                'curriculum_versions.curriculum_id',
                '=',
                'curricula.id',
            )
            ->where('student_enrollments.status', 'active')
            ->where('student_users.role', 'student')
            ->where('student_users.status', 'active')
            ->where(
                'teacher_subject_assignments.teacher_id',
                $authenticatedTeacherUserId,
            )
            ->where('teacher_subject_assignments.status', 'active')
            ->where('teacher_users.id', $authenticatedTeacherUserId)
            ->where('teacher_users.role', 'teacher')
            ->where('teacher_users.status', 'active')
            ->whereColumn(
                'curricula.subject_id',
                'teacher_subject_assignments.subject_id',
            )
            ->when(
                $curriculumVersionId !== null,
                fn (QueryBuilder $query): QueryBuilder => $query->where(
                    'curriculum_versions.id',
                    $curriculumVersionId,
                ),
            )
            ->whereIn(
                'curriculum_versions.status',
                ['published', 'retired'],
            );
    }

    /**
     * @return list<string>
     */
    private function contextColumns(): array
    {
        return [
            'teacher_users.id as teacher_user_id',
            'student_users.id as student_user_id',
            'learner_profiles.id as learner_profile_id',
            'student_enrollments.id as student_enrollment_id',
            'teacher_subject_assignments.id as teacher_subject_assignment_id',
            'teacher_subject_assignments.subject_id',
            'curricula.id as curriculum_id',
            'curriculum_versions.id as curriculum_version_id',
        ];
    }

    private function contextFromRow(
        ?object $scope,
        string $studentEnrollmentId,
    ): TeacherMonitoringContext {
        if ($scope === null) {
            throw (new ModelNotFoundException)->setModel(
                StudentEnrollment::class,
                [$studentEnrollmentId],
            );
        }

        return new TeacherMonitoringContext(
            teacherUserId: $scope->teacher_user_id,
            studentUserId: $scope->student_user_id,
            learnerProfileId: $scope->learner_profile_id,
            studentEnrollmentId: $scope->student_enrollment_id,
            teacherSubjectAssignmentId: $scope->teacher_subject_assignment_id,
            subjectId: $scope->subject_id,
            curriculumId: $scope->curriculum_id,
            curriculumVersionId: $scope->curriculum_version_id,
        );
    }
}

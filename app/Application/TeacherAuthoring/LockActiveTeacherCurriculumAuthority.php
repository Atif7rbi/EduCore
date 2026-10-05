<?php

namespace App\Application\TeacherAuthoring;

use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LockActiveTeacherCurriculumAuthority
{
    public function execute(
        string $actorUserId,
        string $teacherSubjectAssignmentId,
        string $curriculumId,
    ): LockedTeacherCurriculumAuthority {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Teacher curriculum authority must run inside a transaction.'
            );
        }

        /*
         * Identity discovery is intentionally unlocked and is not
         * authoritative. Every discovered fact is revalidated below.
         */
        $assignmentIdentity = TeacherSubjectAssignment::query()
            ->whereKey($teacherSubjectAssignmentId)
            ->firstOrFail([
                'id',
                'teacher_id',
                'subject_id',
            ]);

        /*
         * Canonical Phase H Teacher authority lock order:
         *
         * Teacher User
         * -> Subject
         * -> TeacherSubjectAssignment
         * -> Curriculum
         */
        $teacher = User::query()
            ->whereKey($actorUserId)
            ->lockForUpdate()
            ->firstOrFail();

        $subject = Subject::query()
            ->whereKey($assignmentIdentity->subject_id)
            ->lockForUpdate()
            ->firstOrFail();

        $assignment = TeacherSubjectAssignment::query()
            ->whereKey($assignmentIdentity->id)
            ->lockForUpdate()
            ->firstOrFail();

        $curriculum = Curriculum::query()
            ->whereKey($curriculumId)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $teacher->isTeacher() || ! $teacher->isActive()) {
            throw (new ModelNotFoundException)
                ->setModel(User::class, [$actorUserId]);
        }

        if (
            ! $subject->isCanonical()
            || ! $subject->isAvailableForNewContent()
        ) {
            throw (new ModelNotFoundException)
                ->setModel(Subject::class, [$subject->id]);
        }

        if (
            ! $assignment->isActive()
            || $assignment->teacher_id !== $teacher->id
            || $assignment->subject_id !== $subject->id
        ) {
            throw (new ModelNotFoundException)
                ->setModel(
                    TeacherSubjectAssignment::class,
                    [$assignment->id],
                );
        }

        if (
            $curriculum->teacher_subject_assignment_id
                !== $assignment->id
            || $curriculum->subject_id !== $subject->id
        ) {
            throw (new ModelNotFoundException)
                ->setModel(Curriculum::class, [$curriculum->id]);
        }

        return new LockedTeacherCurriculumAuthority(
            teacherUserId: $teacher->id,
            subjectId: $subject->id,
            teacherSubjectAssignmentId: $assignment->id,
            curriculumId: $curriculum->id,
        );
    }
}

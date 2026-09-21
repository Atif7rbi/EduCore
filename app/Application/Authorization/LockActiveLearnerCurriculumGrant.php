<?php

namespace App\Application\Authorization;

use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\LearnerProfile;
use App\Models\StudentEnrollment;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LockActiveLearnerCurriculumGrant
{
    public function execute(
        string $authenticatedUserId,
        string $learnerProfileId,
        string $curriculumVersionId,
    ): StudentEnrollment {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Learner curriculum authorization must run inside a transaction.'
            );
        }

        /*
         * Identity discovery is intentionally unlocked.
         *
         * Authoritative revalidation happens below under the
         * canonical lock protocol.
         */
        $versionIdentity = CurriculumVersion::query()
            ->whereKey($curriculumVersionId)
            ->firstOrFail([
                'id',
                'curriculum_id',
            ]);

        $curriculumIdentity = Curriculum::query()
            ->whereKey($versionIdentity->curriculum_id)
            ->firstOrFail([
                'id',
                'teacher_subject_assignment_id',
            ]);

        $assignmentId =
            $curriculumIdentity->teacher_subject_assignment_id;

        if (
            ! is_string($assignmentId)
            || $assignmentId === ''
        ) {
            throw (new ModelNotFoundException)
                ->setModel(
                    Curriculum::class,
                    [$curriculumIdentity->id],
                );
        }

        $assignmentIdentity =
            TeacherSubjectAssignment::query()
                ->whereKey($assignmentId)
                ->firstOrFail([
                    'id',
                    'teacher_id',
                ]);

        /*
         * Canonical Phase F authorization lock order:
         *
         * LearnerProfile
         * -> Learner User
         * -> Assignment Teacher User
         * -> TeacherSubjectAssignment
         * -> StudentEnrollment
         * -> Curriculum
         * -> CurriculumVersion
         */
        $learner = LearnerProfile::query()
            ->whereKey($learnerProfileId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $learner->user_id
                !== $authenticatedUserId
        ) {
            throw (new ModelNotFoundException)
                ->setModel(
                    LearnerProfile::class,
                    [$learnerProfileId],
                );
        }

        $learnerUser = User::query()
            ->whereKey($authenticatedUserId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            ! $learnerUser->isStudent()
            || ! $learnerUser->isActive()
        ) {
            throw (new ModelNotFoundException)
                ->setModel(
                    LearnerProfile::class,
                    [$learnerProfileId],
                );
        }

        User::query()
            ->whereKey($assignmentIdentity->teacher_id)
            ->lockForUpdate()
            ->firstOrFail();

        $assignment =
            TeacherSubjectAssignment::query()
                ->whereKey($assignmentIdentity->id)
                ->lockForUpdate()
                ->firstOrFail();

        if (! $assignment->isActive()) {
            throw (new ModelNotFoundException)
                ->setModel(
                    TeacherSubjectAssignment::class,
                    [$assignment->id],
                );
        }

        $enrollment = StudentEnrollment::query()
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->where(
                'teacher_subject_assignment_id',
                $assignment->id,
            )
            ->where('status', 'active')
            ->lockForUpdate()
            ->firstOrFail();

        Curriculum::query()
            ->whereKey($curriculumIdentity->id)
            ->where(
                'teacher_subject_assignment_id',
                $assignment->id,
            )
            ->lockForUpdate()
            ->firstOrFail();

        CurriculumVersion::query()
            ->whereKey($versionIdentity->id)
            ->where(
                'curriculum_id',
                $curriculumIdentity->id,
            )
            ->lockForUpdate()
            ->firstOrFail();

        return $enrollment;
    }
}

<?php

namespace App\Application\Enrollment;

use App\Application\Exceptions\StudentEnrollmentOperationConflict;
use App\Application\Support\TransactionManager;
use App\Models\LearnerProfile;
use App\Models\StudentEnrollment;
use App\Models\StudentEnrollmentTransition;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DeactivateStudentEnrollment
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockStudentEnrollmentOperation $operationLock,
    ) {}

    public function execute(
        string $actorUserId,
        string $enrollmentId,
        string $operationId,
        string $reason,
    ): StudentEnrollment {
        return $this->transactions->run(
            function () use (
                $actorUserId,
                $enrollmentId,
                $operationId,
                $reason,
            ): StudentEnrollment {
                $actorIdentity = User::query()
                    ->whereKey($actorUserId)
                    ->firstOrFail([
                        'id',
                        'role',
                    ]);

                $admin = null;

                if ($actorIdentity->isAdmin()) {
                    $admin = User::query()
                        ->whereKey($actorUserId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (! $admin->isActive()) {
                        throw (new ModelNotFoundException)
                            ->setModel(
                                User::class,
                                [$actorUserId]
                            );
                    }
                }

                $identity = StudentEnrollment::query()
                    ->whereKey($enrollmentId)
                    ->firstOrFail([
                        'id',
                        'learner_profile_id',
                        'teacher_subject_assignment_id',
                    ]);

                $learner = LearnerProfile::query()
                    ->whereKey($identity->learner_profile_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                User::query()
                    ->whereKey($learner->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $assignmentIdentity =
                    TeacherSubjectAssignment::query()
                        ->whereKey(
                            $identity->teacher_subject_assignment_id
                        )
                        ->firstOrFail([
                            'id',
                            'teacher_id',
                        ]);

                $teacher = User::query()
                    ->whereKey($assignmentIdentity->teacher_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $actorAuthorized = (
                    $admin !== null
                ) || (
                    $teacher->id === $actorUserId
                    && $teacher->isTeacher()
                    && $teacher->isActive()
                );

                if (! $actorAuthorized) {
                    throw (new ModelNotFoundException)
                        ->setModel(User::class, [$actorUserId]);
                }

                TeacherSubjectAssignment::query()
                    ->whereKey($assignmentIdentity->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->operationLock->acquire($operationId);

                $enrollment = StudentEnrollment::query()
                    ->whereKey($enrollmentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $replay =
                    StudentEnrollmentTransition::query()
                        ->where('operation_id', $operationId)
                        ->first();

                if ($replay !== null) {
                    $this->assertReplayMatches(
                        transition: $replay,
                        enrollment: $enrollment,
                        actorUserId: $actorUserId,
                        reason: $reason,
                    );

                    return $enrollment->refresh();
                }

                if ($enrollment->isInactive()) {
                    return $enrollment->refresh();
                }

                if (! $enrollment->isActive()) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            StudentEnrollment::class,
                            [$enrollmentId]
                        );
                }

                $enrollment->status = 'inactive';
                $enrollment->save();

                StudentEnrollmentTransition::query()->create([
                    'enrollment_id' => $enrollment->id,
                    'from_status' => 'active',
                    'to_status' => 'inactive',
                    'actor_user_id' => $actorUserId,
                    'operation_id' => $operationId,
                    'outcome' => 'deactivated',
                    'reason' => $reason,
                    'effective_at' => CarbonImmutable::now('UTC'),
                ]);

                return $enrollment->refresh();
            }
        );
    }

    private function assertReplayMatches(
        StudentEnrollmentTransition $transition,
        StudentEnrollment $enrollment,
        string $actorUserId,
        string $reason,
    ): void {
        if (
            $transition->enrollment_id !== $enrollment->id
            || $transition->actor_user_id !== $actorUserId
            || $transition->from_status !== 'active'
            || $transition->to_status !== 'inactive'
            || $transition->outcome !== 'deactivated'
            || $transition->reason !== $reason
        ) {
            throw new StudentEnrollmentOperationConflict;
        }
    }
}

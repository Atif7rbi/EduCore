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

class RequestStudentEnrollment
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockStudentEnrollmentOperation $operationLock,
    ) {}

    public function execute(
        string $actorUserId,
        string $learnerProfileId,
        string $assignmentId,
        string $operationId,
        string $reason,
    ): StudentEnrollment {
        return $this->transactions->run(
            function () use (
                $actorUserId,
                $learnerProfileId,
                $assignmentId,
                $operationId,
                $reason,
            ): StudentEnrollment {
                $learner = LearnerProfile::query()
                    ->whereKey($learnerProfileId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $learnerUser = User::query()
                    ->whereKey($learner->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $learnerUser->id !== $actorUserId
                    || ! $learnerUser->isStudent()
                    || ! $learnerUser->isActive()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(User::class, [$actorUserId]);
                }

                $assignmentIdentity =
                    TeacherSubjectAssignment::query()
                        ->whereKey($assignmentId)
                        ->firstOrFail([
                            'id',
                            'teacher_id',
                        ]);

                User::query()
                    ->whereKey($assignmentIdentity->teacher_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $assignment =
                    TeacherSubjectAssignment::query()
                        ->whereKey($assignmentId)
                        ->lockForUpdate()
                        ->firstOrFail();

                $this->operationLock->acquire($operationId);

                $replay =
                    StudentEnrollmentTransition::query()
                        ->where('operation_id', $operationId)
                        ->first();

                if ($replay !== null) {
                    $enrollment =
                        StudentEnrollment::query()
                            ->whereKey($replay->enrollment_id)
                            ->lockForUpdate()
                            ->firstOrFail();

                    $this->assertReplayMatches(
                        transition: $replay,
                        enrollment: $enrollment,
                        actorUserId: $actorUserId,
                        learnerProfileId: $learnerProfileId,
                        assignmentId: $assignmentId,
                        reason: $reason,
                    );

                    return $enrollment->refresh();
                }

                if (! $assignment->isActive()) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            TeacherSubjectAssignment::class,
                            [$assignmentId]
                        );
                }

                $enrollment = StudentEnrollment::query()
                    ->where(
                        'learner_profile_id',
                        $learnerProfileId
                    )
                    ->where(
                        'teacher_subject_assignment_id',
                        $assignmentId
                    )
                    ->lockForUpdate()
                    ->first();

                if ($enrollment !== null) {
                    if (
                        $enrollment->isPending()
                        || $enrollment->isActive()
                    ) {
                        return $enrollment->refresh();
                    }

                    $enrollment->status = 'pending';
                    $enrollment->save();

                    StudentEnrollmentTransition::query()
                        ->create([
                            'enrollment_id' => $enrollment->id,
                            'from_status' => 'inactive',
                            'to_status' => 'pending',
                            'actor_user_id' => $actorUserId,
                            'operation_id' => $operationId,
                            'outcome' => 'rejoined',
                            'reason' => $reason,
                            'effective_at' => CarbonImmutable::now('UTC'),
                        ]);

                    return $enrollment->refresh();
                }

                $enrollment =
                    StudentEnrollment::query()->create([
                        'learner_profile_id' => $learnerProfileId,
                        'teacher_subject_assignment_id' => $assignmentId,
                        'status' => 'pending',
                    ]);

                StudentEnrollmentTransition::query()->create([
                    'enrollment_id' => $enrollment->id,
                    'from_status' => null,
                    'to_status' => 'pending',
                    'actor_user_id' => $actorUserId,
                    'operation_id' => $operationId,
                    'outcome' => 'requested',
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
        string $learnerProfileId,
        string $assignmentId,
        string $reason,
    ): void {
        $shapeMatches = (
            $transition->from_status === null
            && $transition->to_status === 'pending'
            && $transition->outcome === 'requested'
        ) || (
            $transition->from_status === 'inactive'
            && $transition->to_status === 'pending'
            && $transition->outcome === 'rejoined'
        );

        if (
            ! $shapeMatches
            || $transition->actor_user_id !== $actorUserId
            || $transition->reason !== $reason
            || $enrollment->learner_profile_id !==
                $learnerProfileId
            || $enrollment->teacher_subject_assignment_id !==
                $assignmentId
        ) {
            throw new StudentEnrollmentOperationConflict;
        }
    }
}

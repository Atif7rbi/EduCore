<?php

namespace App\Application\TeacherAssignment;

use App\Application\Exceptions\TeacherSubjectAssignmentOperationConflict;
use App\Application\Support\TransactionManager;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\TeacherSubjectAssignmentTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReactivateTeacherSubjectAssignment
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockTeacherSubjectAssignmentOperation $operationLock,
    ) {}

    public function execute(
        string $actorUserId,
        string $assignmentId,
        string $operationId,
        string $reason,
    ): TeacherSubjectAssignment {
        return $this->transactions->run(
            function () use (
                $actorUserId,
                $assignmentId,
                $operationId,
                $reason,
            ): TeacherSubjectAssignment {
                $identity =
                    TeacherSubjectAssignment::query()
                        ->whereKey($assignmentId)
                        ->firstOrFail([
                            'id',
                            'teacher_id',
                            'subject_id',
                        ]);

                $actor = User::query()
                    ->whereKey($actorUserId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $actor->isAdmin() || ! $actor->isActive()) {
                    throw (new ModelNotFoundException)
                        ->setModel(User::class, [$actorUserId]);
                }

                $teacher = User::query()
                    ->whereKey($identity->teacher_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $subject = Subject::query()
                    ->whereKey($identity->subject_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->operationLock->acquire(
                    $operationId
                );

                $assignment =
                    TeacherSubjectAssignment::query()
                        ->whereKey($assignmentId)
                        ->lockForUpdate()
                        ->firstOrFail();

                $replay =
                    TeacherSubjectAssignmentTransition::query()
                        ->where(
                            'operation_id',
                            $operationId
                        )
                        ->first();

                if ($replay !== null) {
                    $this->assertReplayMatches(
                        transition: $replay,
                        assignment: $assignment,
                        actorUserId: $actorUserId,
                        reason: $reason,
                    );

                    return $assignment->refresh();
                }

                if (
                    ! $teacher->isTeacher()
                    || ! $teacher->isActive()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            User::class,
                            [$identity->teacher_id]
                        );
                }

                if (
                    ! $subject->isCanonical()
                    || ! $subject->isAvailableForNewContent()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            Subject::class,
                            [$identity->subject_id]
                        );
                }

                if ($assignment->status === 'active') {
                    return $assignment->refresh();
                }

                $assignment->status = 'active';
                $assignment->save();

                TeacherSubjectAssignmentTransition::query()
                    ->create([
                        'assignment_id' => $assignment->id,
                        'from_status' => 'inactive',
                        'to_status' => 'active',
                        'actor_user_id' => $actorUserId,
                        'operation_id' => $operationId,
                        'reason' => $reason,
                        'effective_at' => CarbonImmutable::now('UTC'),
                    ]);

                return $assignment->refresh();
            }
        );
    }

    private function assertReplayMatches(
        TeacherSubjectAssignmentTransition $transition,
        TeacherSubjectAssignment $assignment,
        string $actorUserId,
        string $reason,
    ): void {
        if (
            $transition->assignment_id !== $assignment->id
            || $transition->actor_user_id !== $actorUserId
            || $transition->from_status !== 'inactive'
            || $transition->to_status !== 'active'
            || $transition->reason !== $reason
        ) {
            throw new TeacherSubjectAssignmentOperationConflict;
        }
    }
}

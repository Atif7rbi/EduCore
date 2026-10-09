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

class AssignTeacherSubject
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LockTeacherSubjectAssignmentOperation $operationLock,
    ) {}

    public function execute(
        string $actorUserId,
        string $teacherUserId,
        string $subjectId,
        string $operationId,
        string $reason,
    ): TeacherSubjectAssignment {
        return $this->transactions->run(
            function () use (
                $actorUserId,
                $teacherUserId,
                $subjectId,
                $operationId,
                $reason,
            ): TeacherSubjectAssignment {
                $actor = User::query()
                    ->whereKey($actorUserId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $actor->isAdmin() || ! $actor->isActive()) {
                    throw (new ModelNotFoundException)
                        ->setModel(User::class, [$actorUserId]);
                }

                $teacher = User::query()
                    ->whereKey($teacherUserId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $subject = Subject::query()
                    ->whereKey($subjectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->operationLock->acquire(
                    $operationId
                );

                $replay =
                    TeacherSubjectAssignmentTransition::query()
                        ->where(
                            'operation_id',
                            $operationId
                        )
                        ->first();

                if ($replay !== null) {
                    $assignment =
                        TeacherSubjectAssignment::query()
                            ->whereKey(
                                $replay->assignment_id
                            )
                            ->lockForUpdate()
                            ->firstOrFail();

                    $this->assertReplayMatches(
                        transition: $replay,
                        assignment: $assignment,
                        actorUserId: $actorUserId,
                        teacherUserId: $teacherUserId,
                        subjectId: $subjectId,
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
                            [$teacherUserId]
                        );
                }

                if (
                    ! $subject->isCanonical()
                    || ! $subject->isAvailableForNewContent()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            Subject::class,
                            [$subjectId]
                        );
                }

                $assignment =
                    TeacherSubjectAssignment::query()
                        ->where(
                            'teacher_id',
                            $teacherUserId
                        )
                        ->where(
                            'subject_id',
                            $subjectId
                        )
                        ->lockForUpdate()
                        ->first();

                if ($assignment !== null) {
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
                            'effective_at' => CarbonImmutable::now(
                                'UTC'
                            ),
                        ]);

                    return $assignment->refresh();
                }

                $assignment =
                    TeacherSubjectAssignment::query()
                        ->create([
                            'teacher_id' => $teacherUserId,
                            'subject_id' => $subjectId,
                            'status' => 'active',
                        ]);

                TeacherSubjectAssignmentTransition::query()
                    ->create([
                        'assignment_id' => $assignment->id,
                        'from_status' => null,
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
        string $teacherUserId,
        string $subjectId,
        string $reason,
    ): void {
        if (
            $transition->actor_user_id !== $actorUserId
            || $transition->to_status !== 'active'
            || $transition->reason !== $reason
            || $assignment->teacher_id !== $teacherUserId
            || $assignment->subject_id !== $subjectId
        ) {
            throw new TeacherSubjectAssignmentOperationConflict;
        }
    }
}

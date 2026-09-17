<?php

namespace App\Application\Curriculum;

use App\Application\Support\TransactionManager;
use App\Models\Curriculum;
use App\Models\EducationStage;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CreateOwnedCurriculum
{
    public function __construct(
        private readonly TransactionManager $transactions,
    ) {}

    public function execute(
        string $actorUserId,
        string $teacherSubjectAssignmentId,
        string $name,
        ?string $educationStageId,
    ): Curriculum {
        return $this->transactions->run(
            function () use (
                $actorUserId,
                $teacherSubjectAssignmentId,
                $name,
                $educationStageId,
            ): Curriculum {
                /*
                 * Identity discovery is intentionally unlocked.
                 *
                 * Authoritative lock order:
                 *
                 * Teacher User
                 * -> Subject
                 * -> EducationStage, when supplied
                 * -> TeacherSubjectAssignment
                 * -> Curriculum INSERT
                 */
                $assignmentIdentity =
                    TeacherSubjectAssignment::query()
                        ->whereKey(
                            $teacherSubjectAssignmentId
                        )
                        ->firstOrFail([
                            'id',
                            'teacher_id',
                            'subject_id',
                        ]);

                $teacher = User::query()
                    ->whereKey($assignmentIdentity->teacher_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $subject = Subject::query()
                    ->whereKey($assignmentIdentity->subject_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $educationStage = null;

                if ($educationStageId !== null) {
                    $educationStage =
                        EducationStage::query()
                            ->whereKey($educationStageId)
                            ->lockForUpdate()
                            ->firstOrFail();
                }

                $assignment =
                    TeacherSubjectAssignment::query()
                        ->whereKey(
                            $teacherSubjectAssignmentId
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if (
                    $teacher->id !== $actorUserId
                    || ! $teacher->isTeacher()
                    || ! $teacher->isActive()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            User::class,
                            [$actorUserId]
                        );
                }

                if (
                    ! $subject->isCanonical()
                    || ! $subject->isAvailableForNewContent()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            Subject::class,
                            [$subject->id]
                        );
                }

                if (! $assignment->isActive()) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            TeacherSubjectAssignment::class,
                            [$assignment->id]
                        );
                }

                if (
                    $assignment->teacher_id !== $teacher->id
                    || $assignment->subject_id !== $subject->id
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            TeacherSubjectAssignment::class,
                            [$assignment->id]
                        );
                }

                if (
                    $educationStage !== null
                    && ! $educationStage
                        ->isAvailableForNewContent()
                ) {
                    throw (new ModelNotFoundException)
                        ->setModel(
                            EducationStage::class,
                            [$educationStage->id]
                        );
                }

                return Curriculum::query()->create([
                    'subject_id' => $subject->id,
                    'education_stage_id' => $educationStage?->id,
                    'teacher_subject_assignment_id' => $assignment->id,
                    'name' => $name,
                ]);
            }
        );
    }
}

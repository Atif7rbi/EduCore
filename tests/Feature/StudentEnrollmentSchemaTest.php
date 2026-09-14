<?php

namespace Tests\Feature;

use App\Models\LearnerProfile;
use App\Models\StudentEnrollment;
use App\Models\StudentEnrollmentTransition;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\TeacherSubjectAssignmentTransition;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentEnrollmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_student_can_request_active_teacher_assignment_once(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            learnerId: $learner->id,
            assignmentId: $assignment->id,
            actorId: $student->id,
        );

        $this->assertSame('pending', $enrollment->status);

        $this->assertDatabaseHas(
            'student_enrollment_transitions',
            [
                'enrollment_id' => $enrollment->id,
                'from_status' => null,
                'to_status' => 'pending',
                'actor_user_id' => $student->id,
                'outcome' => 'requested',
            ]
        );
    }

    public function test_enrollment_pair_is_unique(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $student,
            $learner,
            $assignment,
        ): void {
            $duplicate = StudentEnrollment::query()->create([
                'learner_profile_id' => $learner->id,
                'teacher_subject_assignment_id' => $assignment->id,
                'status' => 'pending',
            ]);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $duplicate->id,
                'from_status' => null,
                'to_status' => 'pending',
                'actor_user_id' => $student->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'requested',
                'reason' => 'Duplicate request.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_enrollment_cannot_be_created_active(): void
    {
        [, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $this->expectException(QueryException::class);

        StudentEnrollment::query()->create([
            'learner_profile_id' => $learner->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'status' => 'active',
        ]);
    }

    public function test_enrollment_cannot_be_created_inactive(): void
    {
        [, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $this->expectException(QueryException::class);

        StudentEnrollment::query()->create([
            'learner_profile_id' => $learner->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'status' => 'inactive',
        ]);
    }

    public function test_initial_request_requires_active_student_owner(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        DB::table('users')
            ->where('id', $student->id)
            ->update(['status' => 'disabled']);

        $this->expectException(QueryException::class);

        StudentEnrollment::query()->create([
            'learner_profile_id' => $learner->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'status' => 'pending',
        ]);
    }

    public function test_initial_request_requires_active_assignment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $assignment] = $this->activeTeacherAssignment();

        $this->transitionTeacherAssignment(
            $assignment,
            $admin,
            'inactive',
        );

        $this->expectException(QueryException::class);

        StudentEnrollment::query()->create([
            'learner_profile_id' => $learner->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'status' => 'pending',
        ]);
    }

    public function test_enrollment_identity_is_db_immutable(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        [, $otherLearner] = $this->studentIdentity();

        $this->expectException(QueryException::class);

        DB::table('student_enrollments')
            ->where('id', $enrollment->id)
            ->update([
                'learner_profile_id' => $otherLearner->id,
            ]);
    }

    public function test_enrollment_cannot_be_deleted(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->expectException(QueryException::class);

        DB::table('student_enrollments')
            ->where('id', $enrollment->id)
            ->delete();
    }

    public function test_enrollment_cannot_commit_without_authoritative_history(): void
    {
        [, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        DB::beginTransaction();

        try {
            StudentEnrollment::query()->create([
                'learner_profile_id' => $learner->id,
                'teacher_subject_assignment_id' => $assignment->id,
                'status' => 'pending',
            ]);

            $this->expectException(QueryException::class);

            DB::statement(
                'SET CONSTRAINTS ALL IMMEDIATE'
            );
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_status_change_requires_matching_history(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        DB::beginTransaction();

        try {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'active']);

            $this->expectException(QueryException::class);

            DB::statement(
                'SET CONSTRAINTS ALL IMMEDIATE'
            );
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_pending_can_be_accepted_by_owning_active_teacher(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        DB::transaction(function () use (
            $enrollment,
            $teacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'active']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'active',
                'actor_user_id' => $teacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'accepted',
                'reason' => 'Teacher accepted.',
                'effective_at' => now(),
            ]);
        });

        $this->assertSame(
            'active',
            $enrollment->refresh()->status
        );

        $this->assertTrue($admin->isAdmin());
    }

    public function test_acceptance_rejects_wrong_teacher(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();
        [, , $wrongTeacher] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $enrollment,
            $wrongTeacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'active']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'active',
                'actor_user_id' => $wrongTeacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'accepted',
                'reason' => 'Wrong teacher.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_acceptance_requires_active_assignment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->transitionTeacherAssignment(
            $assignment,
            $admin,
            'inactive',
        );

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $enrollment,
            $teacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'active']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'active',
                'actor_user_id' => $teacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'accepted',
                'reason' => 'Assignment inactive.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_pending_can_be_declined_after_assignment_becomes_inactive(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->transitionTeacherAssignment(
            $assignment,
            $admin,
            'inactive',
        );

        DB::transaction(function () use (
            $enrollment,
            $teacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'inactive']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'inactive',
                'actor_user_id' => $teacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'declined',
                'reason' => 'Teacher declined.',
                'effective_at' => now(),
            ]);
        });

        $this->assertSame(
            'inactive',
            $enrollment->refresh()->status
        );
    }

    public function test_active_enrollment_can_be_deactivated_by_admin_after_assignment_inactive(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->acceptEnrollment(
            $enrollment,
            $teacher,
        );

        $this->transitionTeacherAssignment(
            $assignment,
            $admin,
            'inactive',
        );

        DB::transaction(function () use (
            $enrollment,
            $admin,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'inactive']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'active',
                'to_status' => 'inactive',
                'actor_user_id' => $admin->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'deactivated',
                'reason' => 'Administrative deactivation.',
                'effective_at' => now(),
            ]);
        });

        $this->assertSame(
            'inactive',
            $enrollment->refresh()->status
        );
    }

    public function test_inactive_enrollment_rejoin_requires_active_student_and_assignment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->declineEnrollment(
            $enrollment,
            $teacher,
        );

        DB::transaction(function () use (
            $enrollment,
            $student,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'pending']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'inactive',
                'to_status' => 'pending',
                'actor_user_id' => $student->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'rejoined',
                'reason' => 'Student requested again.',
                'effective_at' => now(),
            ]);
        });

        $this->assertSame(
            'pending',
            $enrollment->refresh()->status
        );
    }

    public function test_initial_requested_transition_rejects_non_owner_actor(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $learner,
            $assignment,
            $teacher,
        ): void {
            $enrollment = StudentEnrollment::query()->create([
                'learner_profile_id' => $learner->id,
                'teacher_subject_assignment_id' => $assignment->id,
                'status' => 'pending',
            ]);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => null,
                'to_status' => 'pending',
                'actor_user_id' => $teacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'requested',
                'reason' => 'Wrong request actor.',
                'effective_at' => now(),
            ]);
        });

        $this->assertTrue($student->isStudent());
    }

    public function test_decline_rejects_wrong_teacher(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();
        [, , $wrongTeacher] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $enrollment,
            $wrongTeacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'inactive']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'inactive',
                'actor_user_id' => $wrongTeacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'declined',
                'reason' => 'Wrong teacher decline.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_owning_teacher_can_deactivate_active_enrollment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->acceptEnrollment(
            $enrollment,
            $teacher,
        );

        DB::transaction(function () use (
            $enrollment,
            $teacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'inactive']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'active',
                'to_status' => 'inactive',
                'actor_user_id' => $teacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'deactivated',
                'reason' => 'Teacher deactivated enrollment.',
                'effective_at' => now(),
            ]);
        });

        $this->assertSame(
            'inactive',
            $enrollment->refresh()->status
        );
    }

    public function test_unrelated_teacher_cannot_deactivate_active_enrollment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        [, , $wrongTeacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->acceptEnrollment(
            $enrollment,
            $teacher,
        );

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $enrollment,
            $wrongTeacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'inactive']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'active',
                'to_status' => 'inactive',
                'actor_user_id' => $wrongTeacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'deactivated',
                'reason' => 'Unauthorized deactivation.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_disabled_admin_cannot_deactivate_active_enrollment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->acceptEnrollment(
            $enrollment,
            $teacher,
        );

        DB::table('users')
            ->where('id', $admin->id)
            ->update(['status' => 'disabled']);

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $enrollment,
            $admin,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'inactive']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'active',
                'to_status' => 'inactive',
                'actor_user_id' => $admin->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'deactivated',
                'reason' => 'Disabled admin.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_rejoin_rejects_disabled_student_owner(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->declineEnrollment(
            $enrollment,
            $teacher,
        );

        DB::table('users')
            ->where('id', $student->id)
            ->update(['status' => 'disabled']);

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $enrollment,
            $student,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'pending']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'inactive',
                'to_status' => 'pending',
                'actor_user_id' => $student->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'rejoined',
                'reason' => 'Disabled student rejoin.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_rejoin_rejects_inactive_assignment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->declineEnrollment(
            $enrollment,
            $teacher,
        );

        $this->transitionTeacherAssignment(
            $assignment,
            $admin,
            'inactive',
        );

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $enrollment,
            $student,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'pending']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'inactive',
                'to_status' => 'pending',
                'actor_user_id' => $student->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'rejoined',
                'reason' => 'Inactive assignment rejoin.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_invalid_transition_shape_is_rejected(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->expectException(QueryException::class);

        StudentEnrollmentTransition::query()->create([
            'enrollment_id' => $enrollment->id,
            'from_status' => 'pending',
            'to_status' => 'pending',
            'actor_user_id' => $student->id,
            'operation_id' => (string) Str::uuid(),
            'outcome' => 'requested',
            'reason' => 'Invalid same-state event.',
            'effective_at' => now(),
        ]);
    }

    public function test_transition_operation_id_is_unique(): void
    {
        [$firstStudent, $firstLearner] =
            $this->studentIdentity();
        [, $firstAssignment] =
            $this->activeTeacherAssignment();

        [$secondStudent, $secondLearner] =
            $this->studentIdentity();
        [, $secondAssignment] =
            $this->activeTeacherAssignment();

        $operationId = (string) Str::uuid();

        $this->createEnrollmentWithInitialHistory(
            $firstLearner->id,
            $firstAssignment->id,
            $firstStudent->id,
            $operationId,
        );

        $this->expectException(QueryException::class);

        DB::transaction(function () use (
            $secondStudent,
            $secondLearner,
            $secondAssignment,
            $operationId,
        ): void {
            $enrollment = StudentEnrollment::query()->create([
                'learner_profile_id' => $secondLearner->id,
                'teacher_subject_assignment_id' => $secondAssignment->id,
                'status' => 'pending',
            ]);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => null,
                'to_status' => 'pending',
                'actor_user_id' => $secondStudent->id,
                'operation_id' => $operationId,
                'outcome' => 'requested',
                'reason' => 'Duplicate operation.',
                'effective_at' => now(),
            ]);
        });
    }

    public function test_transition_sequence_number_cannot_be_supplied_by_caller(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = StudentEnrollment::query()->create([
            'learner_profile_id' => $learner->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'status' => 'pending',
        ]);

        $this->expectException(QueryException::class);

        DB::table('student_enrollment_transitions')
            ->insert([
                'id' => (string) Str::uuid(),
                'sequence_number' => 999999,
                'enrollment_id' => $enrollment->id,
                'from_status' => null,
                'to_status' => 'pending',
                'actor_user_id' => $student->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'requested',
                'reason' => 'Caller sequence.',
                'effective_at' => now(),
                'created_at' => now(),
            ]);
    }

    public function test_transition_sequence_is_database_ordered(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment, $teacher] =
            $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $this->acceptEnrollment(
            $enrollment,
            $teacher,
        );

        $numbers = DB::table(
            'student_enrollment_transitions'
        )
            ->where('enrollment_id', $enrollment->id)
            ->orderBy('sequence_number')
            ->pluck('sequence_number')
            ->all();

        $this->assertCount(2, $numbers);
        $this->assertGreaterThan(
            $numbers[0],
            $numbers[1]
        );
    }

    public function test_transition_history_cannot_be_updated(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $transitionId = DB::table(
            'student_enrollment_transitions'
        )
            ->where('enrollment_id', $enrollment->id)
            ->value('id');

        $this->expectException(QueryException::class);

        DB::table('student_enrollment_transitions')
            ->where('id', $transitionId)
            ->update([
                'reason' => 'Rewritten history.',
            ]);
    }

    public function test_transition_history_cannot_be_deleted(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $assignment] = $this->activeTeacherAssignment();

        $enrollment = $this->createEnrollmentWithInitialHistory(
            $learner->id,
            $assignment->id,
            $student->id,
        );

        $transitionId = DB::table(
            'student_enrollment_transitions'
        )
            ->where('enrollment_id', $enrollment->id)
            ->value('id');

        $this->expectException(QueryException::class);

        DB::table('student_enrollment_transitions')
            ->where('id', $transitionId)
            ->delete();
    }

    private function studentIdentity(): array
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::query()->create([
            'user_id' => $student->id,
        ]);

        return [$student, $learner];
    }

    private function activeTeacherAssignment(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $subject = Subject::query()
            ->whereNotNull('code')
            ->where('status', 'active')
            ->firstOrFail();

        $assignment = null;

        DB::transaction(function () use (
            $admin,
            $teacher,
            $subject,
            &$assignment,
        ): void {
            $assignment =
                TeacherSubjectAssignment::query()->create([
                    'teacher_id' => $teacher->id,
                    'subject_id' => $subject->id,
                    'status' => 'active',
                ]);

            TeacherSubjectAssignmentTransition::query()
                ->create([
                    'assignment_id' => $assignment->id,
                    'from_status' => null,
                    'to_status' => 'active',
                    'actor_user_id' => $admin->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Test assignment.',
                    'effective_at' => now(),
                ]);
        });

        return [$admin, $assignment, $teacher];
    }

    private function transitionTeacherAssignment(
        TeacherSubjectAssignment $assignment,
        User $admin,
        string $status,
    ): void {
        DB::transaction(function () use (
            $assignment,
            $admin,
            $status,
        ): void {
            $from = $assignment->fresh()->status;

            DB::table('teacher_subject_assignments')
                ->where('id', $assignment->id)
                ->update(['status' => $status]);

            TeacherSubjectAssignmentTransition::query()
                ->create([
                    'assignment_id' => $assignment->id,
                    'from_status' => $from,
                    'to_status' => $status,
                    'actor_user_id' => $admin->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Test assignment transition.',
                    'effective_at' => now(),
                ]);
        });

        $assignment->refresh();
    }

    private function createEnrollmentWithInitialHistory(
        string $learnerId,
        string $assignmentId,
        string $actorId,
        ?string $operationId = null,
    ): StudentEnrollment {
        $enrollment = null;

        DB::transaction(function () use (
            $learnerId,
            $assignmentId,
            $actorId,
            $operationId,
            &$enrollment,
        ): void {
            $enrollment = StudentEnrollment::query()->create([
                'learner_profile_id' => $learnerId,
                'teacher_subject_assignment_id' => $assignmentId,
                'status' => 'pending',
            ]);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => null,
                'to_status' => 'pending',
                'actor_user_id' => $actorId,
                'operation_id' => $operationId
                    ?? (string) Str::uuid(),
                'outcome' => 'requested',
                'reason' => 'Student requested enrollment.',
                'effective_at' => now(),
            ]);
        });

        return $enrollment->refresh();
    }

    private function acceptEnrollment(
        StudentEnrollment $enrollment,
        User $teacher,
    ): void {
        DB::transaction(function () use (
            $enrollment,
            $teacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'active']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'active',
                'actor_user_id' => $teacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'accepted',
                'reason' => 'Teacher accepted.',
                'effective_at' => now(),
            ]);
        });

        $enrollment->refresh();
    }

    private function declineEnrollment(
        StudentEnrollment $enrollment,
        User $teacher,
    ): void {
        DB::transaction(function () use (
            $enrollment,
            $teacher,
        ): void {
            DB::table('student_enrollments')
                ->where('id', $enrollment->id)
                ->update(['status' => 'inactive']);

            StudentEnrollmentTransition::query()->create([
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'inactive',
                'actor_user_id' => $teacher->id,
                'operation_id' => (string) Str::uuid(),
                'outcome' => 'declined',
                'reason' => 'Teacher declined.',
                'effective_at' => now(),
            ]);
        });

        $enrollment->refresh();
    }
}

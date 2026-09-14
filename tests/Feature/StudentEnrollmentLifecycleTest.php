<?php

namespace Tests\Feature;

use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\DeclineStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Exceptions\StudentEnrollmentOperationConflict;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\LearnerProfile;
use App\Models\StudentEnrollment;
use App\Models\StudentEnrollmentTransition;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentEnrollmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_request_creates_pending_enrollment_and_requested_history(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $enrollment = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Initial request.',
            );

        $this->assertSame('pending', $enrollment->status);

        $this->assertDatabaseHas(
            'student_enrollment_transitions',
            [
                'enrollment_id' => $enrollment->id,
                'from_status' => null,
                'to_status' => 'pending',
                'actor_user_id' => $student->id,
                'operation_id' => $operationId,
                'outcome' => 'requested',
                'reason' => 'Initial request.',
            ],
        );
    }

    public function test_request_replay_returns_same_identity_without_duplicate_history(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $first = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Replay request.',
            );

        $second = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Replay request.',
            );

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            1,
            StudentEnrollmentTransition::query()
                ->where('operation_id', $operationId)
                ->count(),
        );
    }

    public function test_request_operation_replay_with_different_reason_conflicts(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Canonical reason.',
            );

        $this->expectException(
            StudentEnrollmentOperationConflict::class,
        );

        app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Different reason.',
            );
    }

    public function test_pending_request_with_new_operation_is_noop_without_fake_transition(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();

        $first = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                (string) Str::uuid(),
                'Initial request.',
            );

        $noopOperation = (string) Str::uuid();

        $same = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $noopOperation,
                'Same state.',
            );

        $this->assertSame($first->id, $same->id);
        $this->assertSame('pending', $same->status);

        $this->assertDatabaseMissing(
            'student_enrollment_transitions',
            ['operation_id' => $noopOperation],
        );
    }

    public function test_inactive_pair_is_reused_and_rejoined(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Initial decline.',
            );

        $rejoinOperation = (string) Str::uuid();

        $rejoined = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $rejoinOperation,
                'Request again.',
            );

        $this->assertSame($enrollment->id, $rejoined->id);
        $this->assertSame('pending', $rejoined->status);

        $this->assertDatabaseHas(
            'student_enrollment_transitions',
            [
                'enrollment_id' => $enrollment->id,
                'from_status' => 'inactive',
                'to_status' => 'pending',
                'operation_id' => $rejoinOperation,
                'outcome' => 'rejoined',
            ],
        );
    }

    public function test_request_replay_after_later_acceptance_returns_current_active_state(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $enrollment = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Stable request replay.',
            );

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Accept.',
            );

        $replay = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Stable request replay.',
            );

        $this->assertSame($enrollment->id, $replay->id);
        $this->assertSame('active', $replay->status);
    }

    public function test_request_replay_survives_later_assignment_deactivation(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, , $assignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $enrollment = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Replay after assignment change.',
            );

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate assignment.',
            );

        $replay = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Replay after assignment change.',
            );

        $this->assertSame($enrollment->id, $replay->id);
    }

    public function test_new_request_requires_active_student_actor(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();

        DB::table('users')
            ->where('id', $student->id)
            ->update(['status' => 'disabled']);

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                (string) Str::uuid(),
                'Disabled student.',
            );
    }

    public function test_new_request_requires_active_assignment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, , $assignment] = $this->teacherAssignment();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate assignment.',
            );

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                (string) Str::uuid(),
                'Inactive assignment.',
            );
    }

    public function test_teacher_acceptance_writes_authoritative_transition(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        $accepted = app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Teacher accepted.',
            );

        $this->assertSame('active', $accepted->status);

        $this->assertDatabaseHas(
            'student_enrollment_transitions',
            [
                'enrollment_id' => $enrollment->id,
                'from_status' => 'pending',
                'to_status' => 'active',
                'actor_user_id' => $teacher->id,
                'operation_id' => $operationId,
                'outcome' => 'accepted',
            ],
        );
    }

    public function test_acceptance_replay_is_idempotent(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        $first = app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Replay acceptance.',
            );

        $second = app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Replay acceptance.',
            );

        $this->assertSame($first->id, $second->id);
        $this->assertSame('active', $second->status);

        $this->assertSame(
            1,
            StudentEnrollmentTransition::query()
                ->where('operation_id', $operationId)
                ->count(),
        );
    }

    public function test_acceptance_replay_after_later_deactivation_does_not_restore_active(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Stable acceptance.',
            );

        app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Later deactivation.',
            );

        $replay = app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Stable acceptance.',
            );

        $this->assertSame('inactive', $replay->status);
    }

    public function test_acceptance_requires_owning_active_teacher(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();
        [, $wrongTeacher] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(AcceptStudentEnrollment::class)
            ->execute(
                $wrongTeacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Wrong teacher.',
            );
    }

    public function test_pending_enrollment_can_be_declined_after_assignment_deactivation(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Close assignment.',
            );

        $declined = app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Decline pending request.',
            );

        $this->assertSame('inactive', $declined->status);
    }

    public function test_decline_replay_after_rejoin_returns_current_pending_state(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Stable decline.',
            );

        app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                (string) Str::uuid(),
                'Rejoin.',
            );

        $replay = app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Stable decline.',
            );

        $this->assertSame('pending', $replay->status);
    }

    public function test_inactive_decline_with_new_operation_is_noop(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Decline.',
            );

        $noopOperation = (string) Str::uuid();

        $same = app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $noopOperation,
                'Already inactive.',
            );

        $this->assertSame('inactive', $same->status);

        $this->assertDatabaseMissing(
            'student_enrollment_transitions',
            ['operation_id' => $noopOperation],
        );
    }

    public function test_owning_teacher_can_deactivate_active_enrollment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        $result = app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Teacher deactivation.',
            );

        $this->assertSame('inactive', $result->status);
    }

    public function test_active_admin_can_deactivate_active_enrollment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        $result = app(DeactivateStudentEnrollment::class)
            ->execute(
                $admin->id,
                $enrollment->id,
                $operationId,
                'Admin deactivation.',
            );

        $this->assertSame('inactive', $result->status);

        $this->assertDatabaseHas(
            'student_enrollment_transitions',
            [
                'operation_id' => $operationId,
                'actor_user_id' => $admin->id,
                'outcome' => 'deactivated',
            ],
        );
    }

    public function test_disabled_admin_cannot_deactivate_enrollment(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        DB::table('users')
            ->where('id', $admin->id)
            ->update(['status' => 'disabled']);

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(DeactivateStudentEnrollment::class)
            ->execute(
                $admin->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Disabled admin.',
            );
    }

    public function test_inactive_deactivation_with_new_operation_is_noop(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Initial deactivation.',
            );

        $noopOperation = (string) Str::uuid();

        $same = app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $noopOperation,
                'Already inactive.',
            );

        $this->assertSame('inactive', $same->status);

        $this->assertDatabaseMissing(
            'student_enrollment_transitions',
            ['operation_id' => $noopOperation],
        );
    }

    public function test_operation_id_is_globally_conflicting_across_lifecycle_services(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $enrollment = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Request operation.',
            );

        $this->expectException(
            StudentEnrollmentOperationConflict::class,
        );

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Request operation.',
            );
    }

    public function test_request_replay_requires_student_actor_to_remain_active(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, , $assignment] = $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Replay actor validity.',
            );

        DB::table('users')
            ->where('id', $student->id)
            ->update(['status' => 'disabled']);

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $operationId,
                'Replay actor validity.',
            );
    }

    public function test_active_pair_request_with_new_operation_is_noop(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        $noopOperation = (string) Str::uuid();

        $same = app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                $noopOperation,
                'Already enrolled.',
            );

        $this->assertSame($enrollment->id, $same->id);
        $this->assertSame('active', $same->status);

        $this->assertDatabaseMissing(
            'student_enrollment_transitions',
            ['operation_id' => $noopOperation],
        );
    }

    public function test_active_accept_with_new_operation_is_noop(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        $noopOperation = (string) Str::uuid();

        $same = app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $noopOperation,
                'Already active.',
            );

        $this->assertSame('active', $same->status);

        $this->assertDatabaseMissing(
            'student_enrollment_transitions',
            ['operation_id' => $noopOperation],
        );
    }

    public function test_accept_rejects_inactive_enrollment_state(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Decline first.',
            );

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Invalid accept.',
            );
    }

    public function test_accept_replay_with_different_reason_conflicts(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Canonical accept.',
            );

        $this->expectException(
            StudentEnrollmentOperationConflict::class,
        );

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Different accept reason.',
            );
    }

    public function test_disabled_owning_teacher_cannot_accept(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        DB::table('users')
            ->where('id', $teacher->id)
            ->update(['status' => 'disabled']);

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Disabled teacher accept.',
            );
    }

    public function test_decline_replay_is_idempotent(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        $first = app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Replay decline.',
            );

        $second = app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Replay decline.',
            );

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            1,
            StudentEnrollmentTransition::query()
                ->where('operation_id', $operationId)
                ->count(),
        );
    }

    public function test_decline_rejects_active_enrollment_state(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Invalid decline.',
            );
    }

    public function test_disabled_owning_teacher_cannot_decline(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        DB::table('users')
            ->where('id', $teacher->id)
            ->update(['status' => 'disabled']);

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(DeclineStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Disabled teacher decline.',
            );
    }

    public function test_teacher_can_deactivate_after_assignment_becomes_inactive(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [$admin, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Assignment inactive.',
            );

        $result = app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Close enrollment.',
            );

        $this->assertSame('inactive', $result->status);
    }

    public function test_deactivate_rejects_pending_enrollment_state(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Invalid pending deactivation.',
            );
    }

    public function test_deactivation_replay_is_idempotent(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        $operationId = (string) Str::uuid();

        $first = app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Replay deactivation.',
            );

        $second = app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                $operationId,
                'Replay deactivation.',
            );

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            1,
            StudentEnrollmentTransition::query()
                ->where('operation_id', $operationId)
                ->count(),
        );
    }

    public function test_disabled_owning_teacher_cannot_deactivate(): void
    {
        [$student, $learner] = $this->studentIdentity();
        [, $teacher, $assignment] = $this->teacherAssignment();

        $enrollment = $this->activeEnrollment(
            $student,
            $learner,
            $teacher,
            $assignment,
        );

        DB::table('users')
            ->where('id', $teacher->id)
            ->update(['status' => 'disabled']);

        $this->expectException(
            ModelNotFoundException::class,
        );

        app(DeactivateStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Disabled teacher deactivation.',
            );
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

    private function teacherAssignment(): array
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

        $assignment = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Lifecycle test assignment.',
            );

        return [$admin, $teacher, $assignment];
    }

    private function request(
        User $student,
        LearnerProfile $learner,
        TeacherSubjectAssignment $assignment,
    ): StudentEnrollment {
        return app(RequestStudentEnrollment::class)
            ->execute(
                $student->id,
                $learner->id,
                $assignment->id,
                (string) Str::uuid(),
                'Lifecycle test request.',
            );
    }

    private function activeEnrollment(
        User $student,
        LearnerProfile $learner,
        User $teacher,
        TeacherSubjectAssignment $assignment,
    ): StudentEnrollment {
        $enrollment = $this->request(
            $student,
            $learner,
            $assignment,
        );

        return app(AcceptStudentEnrollment::class)
            ->execute(
                $teacher->id,
                $enrollment->id,
                (string) Str::uuid(),
                'Lifecycle test acceptance.',
            );
    }
}

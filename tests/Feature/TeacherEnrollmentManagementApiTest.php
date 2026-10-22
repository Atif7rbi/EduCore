<?php

namespace Tests\Feature;

use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\LearnerProfile;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherEnrollmentManagementApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_teacher_reads_only_own_pending_and_active_enrollments(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $otherTeacher = $this->user('teacher');
        $ownAssignment = $this->assignment($admin, $teacher, 'mathematics');
        $foreignAssignment = $this->assignment($admin, $otherTeacher, 'physics');
        $pending = $this->enrollment(
            $this->user('student', name: 'طالب معلّق'),
            $ownAssignment,
        );
        $active = $this->enrollment(
            $this->user('student', name: 'طالب نشط'),
            $ownAssignment,
        );
        $foreign = $this->enrollment(
            $this->user('student', name: 'طالب معلم آخر'),
            $foreignAssignment,
        );

        app(AcceptStudentEnrollment::class)->execute(
            actorUserId: $teacher->id,
            enrollmentId: $active->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher enrollment read active fixture.',
        );

        $pendingResponse = $this
            ->actingAs($teacher)
            ->getJson('/api/teacher/enrollments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $pending->id)
            ->assertJsonPath('data.items.0.accepted_at', null)
            ->assertJsonPath('data.items.0.student.name', 'طالب معلّق')
            ->assertJsonPath(
                'data.items.0.teacher_subject_assignment.id',
                $ownAssignment->id,
            )
            ->assertJsonMissing(['email' => $pending->learnerProfile->user->email])
            ->assertJsonMissing(['id' => $foreign->id]);

        $this->assertArrayNotHasKey(
            'learner_profile_id',
            $pendingResponse->json('data.items.0.student'),
        );

        $activeResponse = $this
            ->actingAs($teacher)
            ->getJson('/api/teacher/enrollments?status=active')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $active->id)
            ->assertJsonMissing(['id' => $foreign->id]);

        $this->assertNotNull(
            $activeResponse->json('data.items.0.accepted_at'),
        );
    }

    public function test_teacher_reads_are_paginated_filtered_and_do_not_leak_foreign_assignments(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $otherTeacher = $this->user('teacher');
        $firstAssignment = $this->assignment($admin, $teacher, 'mathematics');
        $secondAssignment = $this->assignment($admin, $teacher, 'physics');
        $foreignAssignment = $this->assignment($admin, $otherTeacher, 'chemistry');
        $first = $this->enrollment($this->user('student'), $firstAssignment);
        $second = $this->enrollment($this->user('student'), $secondAssignment);
        $this->enrollment($this->user('student'), $foreignAssignment);

        $response = $this
            ->actingAs($teacher)
            ->getJson('/api/teacher/enrollments?status=pending&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonCount(1, 'data.items');

        $expectedFirst = collect([$first, $second])
            ->sortByDesc(
                fn (StudentEnrollment $enrollment): string => $enrollment->created_at->toISOString().'|'.$enrollment->id,
            )
            ->first();

        $this->assertSame(
            $expectedFirst->id,
            $response->json('data.items.0.id'),
        );

        $this
            ->actingAs($teacher)
            ->getJson(
                '/api/teacher/enrollments?status=pending&assignment_id='
                .$firstAssignment->id
            )
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $first->id);

        $this
            ->actingAs($teacher)
            ->getJson(
                '/api/teacher/enrollments?status=pending&assignment_id='
                .$foreignAssignment->id
            )
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        $this
            ->actingAs($teacher)
            ->getJson('/api/teacher/enrollments?status=inactive')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_teacher_reads_preserve_authentication_role_and_active_user_boundaries(): void
    {
        $student = $this->user('student');
        $admin = $this->user('admin');
        $disabledTeacher = $this->user('teacher', 'disabled');

        $this->getJson('/api/teacher/enrollments?status=pending')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        foreach ([$student, $admin] as $principal) {
            $this->actingAs($principal)
                ->getJson('/api/teacher/enrollments?status=pending')
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'teacher_forbidden');
        }

        $this->actingAs($disabledTeacher)
            ->getJson('/api/teacher/enrollments?status=pending')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'account_disabled');
    }

    public function test_inactive_assignment_enrollments_are_not_exposed(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $assignment = $this->assignment($admin, $teacher, 'mathematics');
        $this->enrollment($this->user('student'), $assignment);

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $admin->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher enrollment read inactive assignment fixture.',
        );

        $this->actingAs($teacher)
            ->getJson('/api/teacher/enrollments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_accept_and_decline_routes_reuse_canonical_lifecycle_and_history(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $assignment = $this->assignment($admin, $teacher, 'mathematics');
        $accepted = $this->enrollment($this->user('student'), $assignment);
        $declined = $this->enrollment($this->user('student'), $assignment);
        $acceptOperation = (string) Str::uuid();
        $declineOperation = (string) Str::uuid();
        $acceptPayload = [
            'operation_id' => $acceptOperation,
            'reason' => 'Teacher accepted enrollment request.',
        ];

        $this->actingAs($teacher)
            ->postJson(
                "/api/teacher/enrollments/{$accepted->id}/accept",
                $acceptPayload,
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($teacher)
            ->postJson(
                "/api/teacher/enrollments/{$accepted->id}/accept",
                $acceptPayload,
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($teacher)
            ->postJson(
                "/api/teacher/enrollments/{$declined->id}/decline",
                [
                    'operation_id' => $declineOperation,
                    'reason' => 'Teacher declined enrollment request.',
                ],
            )
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('student_enrollment_transitions', [
            'enrollment_id' => $accepted->id,
            'operation_id' => $acceptOperation,
            'actor_user_id' => $teacher->id,
            'outcome' => 'accepted',
        ]);
        $this->assertDatabaseHas('student_enrollment_transitions', [
            'enrollment_id' => $declined->id,
            'operation_id' => $declineOperation,
            'actor_user_id' => $teacher->id,
            'outcome' => 'declined',
        ]);
        $this->assertSame(
            1,
            \DB::table('student_enrollment_transitions')
                ->where('operation_id', $acceptOperation)
                ->count(),
        );
    }

    public function test_other_teacher_cannot_decide_foreign_enrollment(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $otherTeacher = $this->user('teacher');
        $assignment = $this->assignment($admin, $teacher, 'mathematics');
        $enrollment = $this->enrollment($this->user('student'), $assignment);

        $this->actingAs($otherTeacher)
            ->postJson(
                "/api/teacher/enrollments/{$enrollment->id}/accept",
                [
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Unauthorized foreign decision.',
                ],
            )
            ->assertNotFound();

        $this->assertTrue($enrollment->refresh()->isPending());
    }

    private function enrollment(
        User $student,
        TeacherSubjectAssignment $assignment,
    ): StudentEnrollment {
        $learner = LearnerProfile::query()->create([
            'user_id' => $student->id,
        ]);

        return app(RequestStudentEnrollment::class)->execute(
            actorUserId: $student->id,
            learnerProfileId: $learner->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher enrollment management fixture.',
        );
    }

    private function assignment(
        User $admin,
        User $teacher,
        string $subjectCode,
    ): TeacherSubjectAssignment {
        $subject = Subject::query()
            ->where('code', $subjectCode)
            ->firstOrFail();

        return app(AssignTeacherSubject::class)->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $subject->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher enrollment management assignment fixture.',
        );
    }

    private function user(
        string $role,
        string $status = 'active',
        ?string $name = null,
    ): User {
        return User::factory()->create([
            'name' => $name ?? fake()->name(),
            'role' => $role,
            'status' => $status,
        ]);
    }
}

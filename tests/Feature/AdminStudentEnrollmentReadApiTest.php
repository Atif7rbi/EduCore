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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminStudentEnrollmentReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_lists_only_enrollments_for_exact_student_learner_profile(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $mathematics =
            $this->assignment(
                $admin,
                $teacher,
                'mathematics',
            );

        $physics =
            $this->assignment(
                $admin,
                $teacher,
                'physics',
            );

        [$student, $profile] =
            $this->studentIdentity(
                'Student One',
                'student.one@example.test',
            );

        [$otherStudent, $otherProfile] =
            $this->studentIdentity(
                'Student Two',
                'student.two@example.test',
            );

        $first = $this->requestEnrollment(
            $student,
            $profile,
            $mathematics,
        );

        $second = $this->requestEnrollment(
            $student,
            $profile,
            $physics,
        );

        $other = $this->requestEnrollment(
            $otherStudent,
            $otherProfile,
            $mathematics,
        );

        $response = $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/students/'
                .$student->id
                .'/enrollments'
            )
            ->assertOk()
            ->assertJsonCount(
                2,
                'data',
            );

        $data = $response->json('data');

        $ids = array_column(
            $data,
            'id',
        );

        $this->assertContains(
            $first->id,
            $ids,
        );

        $this->assertContains(
            $second->id,
            $ids,
        );

        $this->assertNotContains(
            $other->id,
            $ids,
        );

        foreach ($data as $enrollment) {
            $this->assertSame(
                $student->id,
                $enrollment['student']['user_id'],
            );

            $this->assertSame(
                $profile->id,
                $enrollment['student']['learner_profile_id'],
            );
        }
    }

    public function test_admin_enrollment_detail_exposes_relationship_and_transition_provenance(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $assignment =
            $this->assignment(
                $admin,
                $teacher,
                'mathematics',
            );

        [$student, $profile] =
            $this->studentIdentity();

        $requestOperation =
            (string) Str::uuid();

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $student->id,
            learnerProfileId: $profile->id,
            assignmentId: $assignment->id,
            operationId: $requestOperation,
            reason: 'Student requested enrollment.',
        );

        $acceptOperation =
            (string) Str::uuid();

        app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacher->id,
            enrollmentId: $enrollment->id,
            operationId: $acceptOperation,
            reason: 'Teacher accepted enrollment.',
        );

        /*
         * Actor account state can change after the
         * historical transition. The transition keeps
         * canonical actor_user_id provenance, while
         * actor_current is explicitly current-state
         * display context.
         */
        $teacher->forceFill([
            'status' => 'disabled',
        ])->save();

        $response = $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/student-enrollments/'
                .$enrollment->id
            )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $enrollment->id,
            )
            ->assertJsonPath(
                'data.student.user_id',
                $student->id,
            )
            ->assertJsonPath(
                'data.student.learner_profile_id',
                $profile->id,
            )
            ->assertJsonPath(
                'data.status',
                'active',
            )
            ->assertJsonPath(
                'data.teacher_subject_assignment.id',
                $assignment->id,
            )
            ->assertJsonPath(
                'data.teacher_subject_assignment.teacher.user_id',
                $teacher->id,
            )
            ->assertJsonPath(
                'data.teacher_subject_assignment.subject.code',
                'mathematics',
            )
            ->assertJsonCount(
                2,
                'data.transitions',
            );

        $history =
            $response->json(
                'data.transitions'
            );

        $this->assertSame(
            [
                'requested',
                'accepted',
            ],
            array_column(
                $history,
                'outcome',
            ),
        );

        $this->assertSame(
            $requestOperation,
            $history[0]['operation_id'],
        );

        $this->assertSame(
            $student->id,
            $history[0]['actor_user_id'],
        );

        $this->assertSame(
            $acceptOperation,
            $history[1]['operation_id'],
        );

        $this->assertSame(
            $teacher->id,
            $history[1]['actor_user_id'],
        );

        $this->assertSame(
            'disabled',
            $history[1]['actor_current']['status'],
        );

        $this->assertSame(
            'accepted',
            $history[1]['outcome'],
        );
    }

    public function test_active_enrollment_is_not_presented_as_effective_access_when_assignment_is_inactive(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $assignment =
            $this->assignment(
                $admin,
                $teacher,
                'mathematics',
            );

        [$student, $profile] =
            $this->studentIdentity();

        $enrollment =
            $this->requestEnrollment(
                $student,
                $profile,
                $assignment,
            );

        app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacher->id,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher accepted.',
        );

        app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            actorUserId: $admin->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'Assignment deactivated.',
        );

        $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/student-enrollments/'
                .$enrollment->id
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'active',
            )
            ->assertJsonPath(
                'data.teacher_subject_assignment.status',
                'inactive',
            )
            ->assertJsonMissingPath(
                'data.effective_access',
            );
    }

    public function test_student_enrollment_route_uses_user_uuid_not_learner_profile_uuid(): void
    {
        $admin = $this->admin();

        [$student, $profile] =
            $this->studentIdentity();

        $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/students/'
                .$student->id
                .'/enrollments'
            )
            ->assertOk();

        $this
            ->getJson(
                '/api/admin/students/'
                .$profile->id
                .'/enrollments'
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_malformed_student_without_learner_profile_fails_closed(): void
    {
        $admin = $this->admin();

        $student =
            User::factory()->create([
                'role' => 'student',
                'status' => 'active',
            ]);

        $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/students/'
                .$student->id
                .'/enrollments'
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_admin_enrollment_reads_enforce_management_boundary(): void
    {
        $missingEnrollment =
            (string) Str::uuid();

        $this
            ->getJson(
                '/api/admin/student-enrollments/'
                .$missingEnrollment
            )
            ->assertStatus(401)
            ->assertJsonPath(
                'error.code',
                'unauthenticated',
            );

        $teacher = $this->teacher();

        $this
            ->actingAs($teacher)
            ->getJson(
                '/api/admin/student-enrollments/'
                .$missingEnrollment
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'management_forbidden',
            );

        $disabledAdmin =
            User::factory()->create([
                'role' => 'admin',
                'status' => 'disabled',
            ]);

        $this
            ->actingAs($disabledAdmin)
            ->getJson(
                '/api/admin/student-enrollments/'
                .$missingEnrollment
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'account_disabled',
            );
    }

    public function test_phase_g_does_not_expose_routine_admin_enrollment_creation_acceptance_or_decline(): void
    {
        $admin = $this->admin();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/student-enrollments',
                [],
            )
            ->assertStatus(404);

        $enrollmentId =
            (string) Str::uuid();

        $this
            ->postJson(
                '/api/admin/student-enrollments/'
                .$enrollmentId
                .'/accept',
                [],
            )
            ->assertStatus(404);

        $this
            ->postJson(
                '/api/admin/student-enrollments/'
                .$enrollmentId
                .'/decline',
                [],
            )
            ->assertStatus(404);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function teacher(): User
    {
        return User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0:User,1:LearnerProfile}
     */
    private function studentIdentity(
        string $name = 'Student',
        string $email =
            'student@example.test',
    ): array {
        $student =
            User::factory()->create([
                'name' => $name,
                'email' => $email,
                'role' => 'student',
                'status' => 'active',
            ]);

        $profile =
            LearnerProfile::query()
                ->create([
                    'user_id' => $student->id,
                ]);

        return [
            $student,
            $profile,
        ];
    }

    private function assignment(
        User $admin,
        User $teacher,
        string $subjectCode,
    ): TeacherSubjectAssignment {
        $subject =
            Subject::query()
                ->where(
                    'code',
                    $subjectCode,
                )
                ->firstOrFail();

        return app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $subject->id,
            operationId: (string) Str::uuid(),
            reason: 'G3 assignment fixture.',
        );
    }

    private function requestEnrollment(
        User $student,
        LearnerProfile $profile,
        TeacherSubjectAssignment $assignment,
    ): StudentEnrollment {
        return app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $student->id,
            learnerProfileId: $profile->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'G3 enrollment fixture.',
        );
    }
}

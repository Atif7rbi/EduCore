<?php

namespace Tests\Feature;

use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\LearnerProfile;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentEnrollmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_request_enrollment(): void
    {
        [$student, $learner] =
            $this->studentIdentity();

        [, , $assignment] =
            $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $response = $this
            ->actingAs($student)
            ->postJson(
                '/api/student/enrollments',
                [
                    'teacher_subject_assignment_id' => $assignment->id,
                    'operation_id' => $operationId,
                    'reason' => '  I want to join this teacher.  ',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.learner_profile_id',
                $learner->id
            )
            ->assertJsonPath(
                'data.teacher_subject_assignment_id',
                $assignment->id
            )
            ->assertJsonPath(
                'data.status',
                'pending'
            );

        $enrollment = StudentEnrollment::query()
            ->where(
                'learner_profile_id',
                $learner->id
            )
            ->where(
                'teacher_subject_assignment_id',
                $assignment->id
            )
            ->firstOrFail();

        $this->assertDatabaseHas(
            'student_enrollment_transitions',
            [
                'enrollment_id' => $enrollment->id,
                'operation_id' => $operationId,
                'outcome' => 'requested',
                'reason' => 'I want to join this teacher.',
            ]
        );
    }

    public function test_student_request_replay_is_http_idempotent(): void
    {
        [$student] = $this->studentIdentity();

        [, , $assignment] =
            $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $payload = [
            'teacher_subject_assignment_id' => $assignment->id,
            'operation_id' => $operationId,
            'reason' => 'Idempotent enrollment request.',
        ];

        $first = $this
            ->actingAs($student)
            ->postJson(
                '/api/student/enrollments',
                $payload
            )
            ->assertOk();

        $second = $this
            ->actingAs($student)
            ->postJson(
                '/api/student/enrollments',
                $payload
            )
            ->assertOk();

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id')
        );

        $this->assertSame(
            1,
            \DB::table(
                'student_enrollment_transitions'
            )
                ->where(
                    'operation_id',
                    $operationId
                )
                ->count()
        );
    }

    public function test_student_request_operation_conflict_maps_to_409(): void
    {
        [$firstStudent] =
            $this->studentIdentity();

        [$secondStudent] =
            $this->studentIdentity();

        [, , $firstAssignment] =
            $this->teacherAssignment();

        [, , $secondAssignment] =
            $this->teacherAssignment();

        $operationId = (string) Str::uuid();

        $this
            ->actingAs($firstStudent)
            ->postJson(
                '/api/student/enrollments',
                [
                    'teacher_subject_assignment_id' => $firstAssignment->id,
                    'operation_id' => $operationId,
                    'reason' => 'Same operation facts.',
                ]
            )
            ->assertOk();

        $this
            ->actingAs($secondStudent)
            ->postJson(
                '/api/student/enrollments',
                [
                    'teacher_subject_assignment_id' => $secondAssignment->id,
                    'operation_id' => $operationId,
                    'reason' => 'Same operation facts.',
                ]
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'student_enrollment_operation_conflict'
            );
    }

    public function test_student_request_rejects_client_supplied_authority_fields(): void
    {
        [$student] = $this->studentIdentity();

        [, , $assignment] =
            $this->teacherAssignment();

        $this
            ->actingAs($student)
            ->postJson(
                '/api/student/enrollments',
                [
                    'teacher_subject_assignment_id' => $assignment->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Authority injection attempt.',
                    'actor_user_id' => (string) Str::uuid(),
                    'learner_profile_id' => (string) Str::uuid(),
                    'status' => 'active',
                ]
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'actor_user_id',
                        'learner_profile_id',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_student_request_requires_valid_transport_payload(): void
    {
        [$student] = $this->studentIdentity();

        $this
            ->actingAs($student)
            ->postJson(
                '/api/student/enrollments',
                [
                    'teacher_subject_assignment_id' => 'not-a-uuid',
                    'operation_id' => 'not-a-uuid',
                    'reason' => '   ',
                ]
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'teacher_subject_assignment_id',
                        'operation_id',
                        'reason',
                    ],
                ],
            ]);
    }

    public function test_student_enrollment_route_requires_student_and_learner_identity(): void
    {
        [, , $assignment] =
            $this->teacherAssignment();

        $payload = [
            'teacher_subject_assignment_id' => $assignment->id,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'Authorization boundary.',
        ];

        $this
            ->postJson(
                '/api/student/enrollments',
                $payload
            )
            ->assertStatus(401);

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/student/enrollments',
                $payload
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'student_forbidden'
            );

        $studentWithoutProfile =
            User::factory()->create([
                'role' => 'student',
                'status' => 'active',
            ]);

        $this
            ->actingAs($studentWithoutProfile)
            ->postJson(
                '/api/student/enrollments',
                $payload
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    public function test_disabled_student_is_rejected_before_enrollment_service(): void
    {
        [$student] = $this->studentIdentity();

        [, , $assignment] =
            $this->teacherAssignment();

        $student->forceFill([
            'status' => 'disabled',
        ])->save();

        $this
            ->actingAs($student)
            ->postJson(
                '/api/student/enrollments',
                [
                    'teacher_subject_assignment_id' => $assignment->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Disabled request.',
                ]
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'account_disabled'
            );
    }

    public function test_owning_teacher_can_accept_enrollment(): void
    {
        [$student, $learner] =
            $this->studentIdentity();

        [, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->pendingEnrollment(
            $student,
            $learner,
            $assignment,
        );

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollment->id.
                    '/accept',
                $this->operationPayload(
                    'Teacher accepted.'
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'active'
            );
    }

    public function test_owning_teacher_can_decline_enrollment(): void
    {
        [$student, $learner] =
            $this->studentIdentity();

        [, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->pendingEnrollment(
            $student,
            $learner,
            $assignment,
        );

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollment->id.
                    '/decline',
                $this->operationPayload(
                    'Teacher declined.'
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'inactive'
            );
    }

    public function test_owning_teacher_can_deactivate_active_enrollment(): void
    {
        [$student, $learner] =
            $this->studentIdentity();

        [, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->pendingEnrollment(
            $student,
            $learner,
            $assignment,
        );

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollment->id.
                    '/accept',
                $this->operationPayload(
                    'Teacher accepted.'
                )
            )
            ->assertOk();

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollment->id.
                    '/deactivate',
                $this->operationPayload(
                    'Teacher deactivated.'
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'inactive'
            );
    }

    public function test_teacher_routes_enforce_teacher_middleware(): void
    {
        $enrollmentId =
            (string) Str::uuid();

        $payload = $this->operationPayload(
            'Authorization probe.'
        );

        $this
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollmentId.
                    '/accept',
                $payload
            )
            ->assertStatus(401);

        [$student] = $this->studentIdentity();

        $this
            ->actingAs($student)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollmentId.
                    '/accept',
                $payload
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'teacher_forbidden'
            );

        $disabledTeacher =
            User::factory()->create([
                'role' => 'teacher',
                'status' => 'disabled',
            ]);

        $this
            ->actingAs($disabledTeacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollmentId.
                    '/accept',
                $payload
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'account_disabled'
            );
    }

    public function test_non_owning_teacher_receives_not_found(): void
    {
        [$student, $learner] =
            $this->studentIdentity();

        [, , $assignment] =
            $this->teacherAssignment();

        [, $otherTeacher] =
            $this->teacherAssignment();

        $enrollment = $this->pendingEnrollment(
            $student,
            $learner,
            $assignment,
        );

        $this
            ->actingAs($otherTeacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollment->id.
                    '/accept',
                $this->operationPayload(
                    'Unauthorized acceptance.'
                )
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    public function test_admin_can_deactivate_active_enrollment(): void
    {
        [$student, $learner] =
            $this->studentIdentity();

        [$admin, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->pendingEnrollment(
            $student,
            $learner,
            $assignment,
        );

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollment->id.
                    '/accept',
                $this->operationPayload(
                    'Teacher accepted.'
                )
            )
            ->assertOk();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/student-enrollments/'.
                    $enrollment->id.
                    '/deactivate',
                $this->operationPayload(
                    'Admin deactivated.'
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'inactive'
            );
    }

    public function test_admin_deactivation_route_rejects_non_admin(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/admin/student-enrollments/'.
                    Str::uuid().
                    '/deactivate',
                $this->operationPayload(
                    'Unauthorized admin action.'
                )
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'management_forbidden'
            );
    }

    public function test_lifecycle_operation_rejects_client_authority_fields(): void
    {
        [$student, $learner] =
            $this->studentIdentity();

        [, $teacher, $assignment] =
            $this->teacherAssignment();

        $enrollment = $this->pendingEnrollment(
            $student,
            $learner,
            $assignment,
        );

        $this
            ->actingAs($teacher)
            ->postJson(
                '/api/teacher/enrollments/'.
                    $enrollment->id.
                    '/accept',
                [
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Authority injection.',
                    'actor_user_id' => (string) Str::uuid(),
                    'status' => 'inactive',
                ]
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed'
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'actor_user_id',
                        'status',
                    ],
                ],
            ]);
    }

    /**
     * @return array{0:User,1:LearnerProfile}
     */
    private function studentIdentity(): array
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::query()
            ->create([
                'user_id' => $student->id,
            ]);

        return [$student, $learner];
    }

    /**
     * @return array{0:User,1:User,2:TeacherSubjectAssignment}
     */
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
            ->orderBy('sort_order')
            ->orderBy('id')
            ->firstOrFail();

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            (string) Str::uuid(),
            'HTTP enrollment test assignment.',
        );

        return [
            $admin,
            $teacher,
            $assignment,
        ];
    }

    private function pendingEnrollment(
        User $student,
        LearnerProfile $learner,
        TeacherSubjectAssignment $assignment,
    ): StudentEnrollment {
        return app(
            RequestStudentEnrollment::class
        )->execute(
            $student->id,
            $learner->id,
            $assignment->id,
            (string) Str::uuid(),
            'HTTP enrollment pending fixture.',
        );
    }

    /**
     * @return array{operation_id:string,reason:string}
     */
    private function operationPayload(
        string $reason,
    ): array {
        return [
            'operation_id' => (string) Str::uuid(),
            'reason' => $reason,
        ];
    }
}

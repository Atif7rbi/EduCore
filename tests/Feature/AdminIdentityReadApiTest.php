<?php

namespace Tests\Feature;

use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Identity\ProvisionTeacher;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminIdentityReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_teacher_read_models_expose_identity_provisioning_and_assignment_truth(): void
    {
        $admin = $this->admin();

        $provisionedTeacher = app(
            ProvisionTeacher::class
        )->execute(
            actorUserId: $admin->id,
            name: 'Alpha Teacher',
            email: 'alpha.teacher@example.test',
        );

        $assignedTeacher =
            User::factory()->create([
                'name' => 'Zulu Teacher',
                'email' => 'zulu.teacher@example.test',
                'role' => 'teacher',
                'status' => 'active',
            ]);

        $subjectId = DB::table('subjects')
            ->where(
                'code',
                'mathematics',
            )
            ->value('id');

        $this->assertIsString(
            $subjectId
        );

        app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $assignedTeacher->id,
            subjectId: $subjectId,
            operationId: (string) Str::uuid(),
            reason: 'G1-B read model fixture',
        );

        User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/teachers'
            )
            ->assertOk()
            ->assertJsonCount(
                2,
                'data',
            );

        $data =
            $response->json('data');

        $this->assertSame(
            [
                $provisionedTeacher->id,
                $assignedTeacher->id,
            ],
            array_column(
                $data,
                'user_id',
            ),
        );

        $this->assertSame(
            'pending_setup',
            $data[0]['provisioning']['state'],
        );

        $this->assertSame(
            $admin->id,
            $data[0]['provisioning']['provisioned_by_user_id'],
        );

        $this->assertSame(
            [
                'active' => 0,
                'inactive' => 0,
                'total' => 0,
            ],
            $data[0]['assignment_counts'],
        );

        $this->assertSame(
            'untracked',
            $data[1]['provisioning']['state'],
        );

        $this->assertSame(
            [
                'active' => 1,
                'inactive' => 0,
                'total' => 1,
            ],
            $data[1]['assignment_counts'],
        );

        $this
            ->getJson(
                '/api/admin/teachers/'
                .$provisionedTeacher->id
            )
            ->assertOk()
            ->assertJsonPath(
                'data.user_id',
                $provisionedTeacher->id,
            )
            ->assertJsonPath(
                'data.role',
                'teacher',
            )
            ->assertJsonPath(
                'data.status',
                'disabled',
            )
            ->assertJsonPath(
                'data.provisioning.state',
                'pending_setup',
            );
    }

    public function test_teacher_detail_rejects_non_teacher_user_identity(): void
    {
        $admin = $this->admin();

        $student =
            $this->studentWithProfile();

        $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/teachers/'
                .$student['user']->id
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_admin_student_read_models_use_user_uuid_and_expose_learner_profile_uuid(): void
    {
        $admin = $this->admin();

        $teacher =
            User::factory()->create([
                'role' => 'teacher',
                'status' => 'active',
            ]);

        $subjectId = DB::table('subjects')
            ->where(
                'code',
                'mathematics',
            )
            ->value('id');

        $this->assertIsString(
            $subjectId
        );

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $subjectId,
            operationId: (string) Str::uuid(),
            reason: 'G1-B student fixture',
        );

        $student =
            $this->studentWithProfile(
                name: 'Student One',
                email: 'student.one@example.test',
            );

        app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $student['user']->id,
            learnerProfileId: $student['profile']->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'G1-B enrollment fixture',
        );

        $response = $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/students'
            )
            ->assertOk()
            ->assertJsonCount(
                1,
                'data',
            )
            ->assertJsonPath(
                'data.0.user_id',
                $student['user']->id,
            )
            ->assertJsonPath(
                'data.0.learner_profile_id',
                $student['profile']->id,
            )
            ->assertJsonPath(
                'data.0.enrollment_counts.pending',
                1,
            )
            ->assertJsonPath(
                'data.0.enrollment_counts.active',
                0,
            )
            ->assertJsonPath(
                'data.0.enrollment_counts.total',
                1,
            );

        $this
            ->getJson(
                '/api/admin/students/'
                .$student['user']->id
            )
            ->assertOk()
            ->assertJsonPath(
                'data.user_id',
                $student['user']->id,
            )
            ->assertJsonPath(
                'data.learner_profile_id',
                $student['profile']->id,
            );

        /*
         * CDA-009:
         * learner_profile_id is not a Student
         * route identity.
         */
        $this
            ->getJson(
                '/api/admin/students/'
                .$student['profile']->id
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_student_read_model_exposes_missing_profile_without_attribute_inference(): void
    {
        $admin = $this->admin();

        $student =
            User::factory()->create([
                'name' => 'Malformed Student',
                'email' => 'malformed.student@example.test',
                'role' => 'student',
                'status' => 'active',
            ]);

        $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/students/'
                .$student->id
            )
            ->assertOk()
            ->assertJsonPath(
                'data.user_id',
                $student->id,
            )
            ->assertJsonPath(
                'data.learner_profile_id',
                null,
            )
            ->assertJsonPath(
                'data.enrollment_counts.total',
                0,
            );
    }

    public function test_admin_identity_reads_reject_guest_and_non_admin(): void
    {
        $this
            ->getJson(
                '/api/admin/teachers'
            )
            ->assertStatus(401);

        $student =
            $this->studentWithProfile();

        $this->actingAs(
            $student['user']
        );

        $this
            ->getJson(
                '/api/admin/teachers'
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'management_forbidden',
            );

        $this
            ->getJson(
                '/api/admin/students'
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'management_forbidden',
            );
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function studentWithProfile(
        string $name = 'Student',
        string $email = 'student@example.test',
    ): array {
        $user =
            User::factory()->create([
                'name' => $name,
                'email' => $email,
                'role' => 'student',
                'status' => 'active',
            ]);

        $profile =
            LearnerProfile::query()
                ->create([
                    'user_id' => $user->id,
                ]);

        return [
            'user' => $user,
            'profile' => $profile,
        ];
    }
}

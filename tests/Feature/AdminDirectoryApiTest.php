<?php

namespace Tests\Feature;

use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeclineStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDirectoryApiTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'array',
        ]);

        $this->connection = DB::connection();

        $this->assertSame(
            0,
            $this->connection->transactionLevel()
        );

        $this->connection->beginTransaction();
        $this->connection->statement(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
        );
    }

    protected function tearDown(): void
    {
        while (
            isset($this->connection)
            && $this->connection->transactionLevel() > 0
        ) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function test_directory_routes_enforce_admin_authorization_and_role_scope(): void
    {
        $teacher = $this->user(
            'Teacher',
            'teacher.scope@example.test',
            'teacher',
        );
        $student = $this->user(
            'Student',
            'student.scope@example.test',
            'student',
        );
        $admin = $this->user(
            'Admin',
            'admin.scope@example.test',
            'admin',
        );
        $disabledAdmin = $this->user(
            'Disabled Admin',
            'disabled.admin.scope@example.test',
            'admin',
            'disabled',
        );

        $this->freeze();

        $this->getJson('/api/admin/directories/teachers')
            ->assertStatus(401)
            ->assertJsonPath(
                'error.code',
                'unauthenticated'
            );

        foreach ([$teacher, $student] as $wrongRole) {
            $this->actingAs($wrongRole)
                ->getJson('/api/admin/directories/teachers')
                ->assertStatus(403)
                ->assertJsonPath(
                    'error.code',
                    'management_forbidden'
                );
        }

        $this->actingAs($disabledAdmin)
            ->getJson('/api/admin/directories/students')
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'account_disabled'
            );

        $this->actingAs($admin)
            ->getJson('/api/admin/directories/teachers')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $teacher->id
            );

        $this->getJson('/api/admin/directories/students')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $student->id
            );
    }

    public function test_validation_defaults_and_boundaries(): void
    {
        $admin = $this->admin();
        $this->freeze();
        $this->actingAs($admin);

        $this->getJson('/api/admin/directories/teachers')
            ->assertOk()
            ->assertJsonPath(
                'data.pagination.current_page',
                1
            )
            ->assertJsonPath(
                'data.pagination.per_page',
                20
            )
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath(
                'data.pagination.total',
                0
            )
            ->assertJsonPath(
                'data.pagination.last_page',
                1
            );

        $invalidQueries = [
            ['page' => 0],
            ['page' => '1.5'],
            ['page' => 'invalid'],
            ['page' => [1]],
            ['per_page' => 0],
            ['per_page' => 51],
            ['per_page' => '1.5'],
            ['search' => str_repeat('a', 121)],
            ['search' => ['unsafe']],
            ['status' => 'pending'],
        ];

        foreach ($invalidQueries as $query) {
            $this->getJson(
                '/api/admin/directories/teachers?'
                .http_build_query($query)
            )
                ->assertStatus(422)
                ->assertJsonPath(
                    'error.code',
                    'validation_failed'
                );
        }
    }

    public function test_extreme_page_is_preserved_without_offset_overflow(): void
    {
        $admin = $this->admin();
        $this->user(
            'Extreme Page Teacher',
            'extreme.page.teacher@example.test',
            'teacher',
        );
        $this->student(
            'Extreme Page Student',
            'extreme.page.student@example.test',
        );

        $this->freeze();
        $this->actingAs($admin);

        foreach (['teachers', 'students'] as $resource) {
            $this->directory(
                $resource,
                [
                    'page' => PHP_INT_MAX,
                    'per_page' => 50,
                ]
            )
                ->assertJsonCount(0, 'data.items')
                ->assertJsonPath(
                    'data.pagination.current_page',
                    PHP_INT_MAX
                )
                ->assertJsonPath(
                    'data.pagination.per_page',
                    50
                )
                ->assertJsonPath(
                    'data.pagination.total',
                    1
                )
                ->assertJsonPath(
                    'data.pagination.last_page',
                    1
                );
        }
    }

    public function test_teacher_search_is_case_insensitive_literal_and_uuid_aware(): void
    {
        $admin = $this->admin();
        $special = $this->user(
            'ALPHA %_\\ Teacher',
            'special.teacher@example.test',
            'teacher',
        );
        $plain = $this->user(
            'Beta Teacher',
            'beta.teacher@example.test',
            'teacher',
        );

        $this->freeze();
        $this->actingAs($admin);

        $this->directory(
            'teachers',
            ['search' => 'alpha']
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $special->id
            );

        $this->directory(
            'teachers',
            [
                'search' => 'BETA.TEACHER@EXAMPLE.TEST',
            ]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $plain->id
            );

        foreach (['%', '_', '\\'] as $literal) {
            $this->directory(
                'teachers',
                ['search' => $literal]
            )
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath(
                    'data.items.0.user_id',
                    $special->id
                );
        }

        $this->directory(
            'teachers',
            ['search' => $plain->id]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $plain->id
            );
    }

    public function test_student_uuid_search_and_nullable_profile_semantics(): void
    {
        $admin = $this->admin();
        $withProfile = $this->student(
            'Profile Student',
            'profile.student@example.test',
        );
        $withoutProfile = $this->user(
            'No Profile Student',
            'no.profile.student@example.test',
            'student',
        );

        $this->freeze();
        $this->actingAs($admin);

        foreach (
            [
                $withProfile['user']->id,
                $withProfile['profile']->id,
            ] as $uuid
        ) {
            $this->directory(
                'students',
                ['search' => $uuid]
            )
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath(
                    'data.items.0.user_id',
                    $withProfile['user']->id
                );
        }

        $this->directory(
            'students',
            ['search' => 'no.profile']
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $withoutProfile->id
            )
            ->assertJsonPath(
                'data.items.0.learner_profile_id',
                null
            )
            ->assertJsonPath(
                'data.items.0.learner_profile_created_at',
                null
            );
    }

    public function test_uuid_search_is_literal_role_and_status_scoped(): void
    {
        $admin = $this->admin();
        $teacher = $this->user(
            'Scoped Teacher',
            'scoped.teacher@example.test',
            'teacher',
        );
        $disabledTeacher = $this->user(
            'Disabled Scoped Teacher',
            'disabled.scoped.teacher@example.test',
            'teacher',
            'disabled',
        );
        $student = $this->student(
            'Scoped Student',
            'scoped.student@example.test',
        );
        $invalidUuidLike =
            '123e4567-e89b-12d3-a456-42661417400z';
        $literalTeacher = $this->user(
            $invalidUuidLike,
            'literal.uuid.teacher@example.test',
            'teacher',
        );

        $this->freeze();
        $this->actingAs($admin);

        $this->directory(
            'teachers',
            ['search' => $invalidUuidLike]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $literalTeacher->id
            );

        $this->directory(
            'teachers',
            ['search' => $student['user']->id]
        )
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath(
                'data.pagination.total',
                0
            );

        $this->directory(
            'students',
            ['search' => $teacher->id]
        )
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath(
                'data.pagination.total',
                0
            );

        foreach (['teachers', 'students'] as $resource) {
            $this->directory(
                $resource,
                ['search' => $admin->id]
            )
                ->assertJsonCount(0, 'data.items')
                ->assertJsonPath(
                    'data.pagination.total',
                    0
                );
        }

        $this->directory(
            'teachers',
            ['search' => $student['profile']->id]
        )
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath(
                'data.pagination.total',
                0
            );

        $this->directory(
            'students',
            ['search' => $student['profile']->id]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $student['user']->id
            );

        $this->directory(
            'teachers',
            [
                'search' => $disabledTeacher->id,
                'status' => 'active',
            ]
        )
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath(
                'data.pagination.total',
                0
            );

        $this->directory(
            'teachers',
            [
                'search' => $disabledTeacher->id,
                'status' => 'disabled',
            ]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.user_id',
                $disabledTeacher->id
            );
    }

    public function test_order_pagination_status_totals_and_out_of_range_behavior(): void
    {
        $admin = $this->admin();

        $alphaB = $this->user(
            'Alpha',
            'b@example.test',
            'teacher',
        );
        $alphaA = $this->user(
            'Alpha',
            'a@example.test',
            'teacher',
        );
        $beta = $this->user(
            'Beta',
            'beta@example.test',
            'teacher',
            'disabled',
        );

        $this->freeze();
        $this->actingAs($admin);

        $firstPage = $this->directory(
            'teachers',
            [
                'page' => 1,
                'per_page' => 2,
            ]
        )
            ->assertJsonPath(
                'data.pagination.total',
                3
            )
            ->assertJsonPath(
                'data.pagination.last_page',
                2
            );

        $this->assertSame(
            [$alphaA->id, $alphaB->id],
            array_column(
                $firstPage->json('data.items'),
                'user_id'
            )
        );

        $this->directory(
            'teachers',
            [
                'status' => 'disabled',
            ]
        )
            ->assertJsonPath(
                'data.pagination.total',
                1
            )
            ->assertJsonPath(
                'data.items.0.user_id',
                $beta->id
            );

        $this->directory(
            'teachers',
            [
                'page' => 9,
                'per_page' => 2,
            ]
        )
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath(
                'data.pagination.current_page',
                9
            )
            ->assertJsonPath(
                'data.pagination.last_page',
                2
            );
    }

    public function test_grouped_aggregates_preserve_one_item_per_user(): void
    {
        $admin = $this->admin();
        $teacher = $this->user(
            'Aggregate Teacher',
            'aggregate.teacher@example.test',
            'teacher',
        );
        $provisionedTeacher = $this->user(
            'Provisioned Teacher',
            'provisioned.teacher@example.test',
            'teacher',
            'disabled',
        );
        $student = $this->student(
            'Aggregate Student',
            'aggregate.student@example.test',
        );

        $subjectIds = DB::table('subjects')
            ->where('status', 'active')
            ->orderBy('code')
            ->limit(3)
            ->pluck('id')
            ->all();

        $this->assertCount(3, $subjectIds);

        $assignments = [];

        foreach ($subjectIds as $subjectId) {
            $assignments[] = app(
                AssignTeacherSubject::class
            )->execute(
                actorUserId: $admin->id,
                teacherUserId: $teacher->id,
                subjectId: $subjectId,
                operationId: (string) Str::uuid(),
                reason: 'Admin directory aggregate fixture',
            );
        }

        DB::table(
            'teacher_account_provisionings'
        )->insert([
            'teacher_user_id' => $provisionedTeacher->id,
            'provisioned_by_user_id' => $admin->id,
            'setup_completed_at' => null,
            'created_at' => now(),
        ]);

        app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            actorUserId: $admin->id,
            assignmentId: $assignments[2]->id,
            operationId: (string) Str::uuid(),
            reason: 'Admin directory inactive aggregate fixture',
        );

        $enrollments = [];

        foreach (array_slice($assignments, 0, 2) as $assignment) {
            $enrollments[] = app(
                RequestStudentEnrollment::class
            )->execute(
                actorUserId: $student['user']->id,
                learnerProfileId: $student['profile']->id,
                assignmentId: $assignment->id,
                operationId: (string) Str::uuid(),
                reason: 'Admin directory enrollment fixture',
            );
        }

        app(AcceptStudentEnrollment::class)
            ->execute(
                actorUserId: $teacher->id,
                enrollmentId: $enrollments[0]->id,
                operationId: (string) Str::uuid(),
                reason: 'Admin directory active fixture',
            );

        app(DeclineStudentEnrollment::class)
            ->execute(
                actorUserId: $teacher->id,
                enrollmentId: $enrollments[1]->id,
                operationId: (string) Str::uuid(),
                reason: 'Admin directory inactive fixture',
            );

        $pendingAssignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $this->user(
                'Pending Teacher',
                'pending.teacher@example.test',
                'teacher',
            )->id,
            subjectId: $subjectIds[0],
            operationId: (string) Str::uuid(),
            reason: 'Admin directory pending assignment fixture',
        );

        app(RequestStudentEnrollment::class)
            ->execute(
                actorUserId: $student['user']->id,
                learnerProfileId: $student['profile']->id,
                assignmentId: $pendingAssignment->id,
                operationId: (string) Str::uuid(),
                reason: 'Admin directory pending fixture',
            );

        $this->freeze();
        $this->actingAs($admin);

        $this->directory(
            'teachers',
            ['search' => $teacher->id]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.assignment_counts',
                [
                    'active' => 2,
                    'inactive' => 1,
                    'total' => 3,
                ]
            )
            ->assertJsonMissingPath(
                'data.items.0.password'
            );

        $this->directory(
            'teachers',
            ['search' => $provisionedTeacher->id]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.provisioning.state',
                'pending_setup'
            )
            ->assertJsonPath(
                'data.items.0.provisioning.provisioned_by_user_id',
                $admin->id
            );

        $this->directory(
            'students',
            ['search' => $student['user']->id]
        )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.enrollment_counts',
                [
                    'pending' => 1,
                    'active' => 1,
                    'inactive' => 1,
                    'total' => 3,
                ]
            )
            ->assertJsonMissingPath(
                'data.items.0.password'
            );
    }

    public function test_legacy_lists_and_details_remain_arrays_and_no_read_writes_occur(): void
    {
        $admin = $this->admin();
        $teacher = $this->user(
            'Legacy Teacher',
            'legacy.teacher@example.test',
            'teacher',
        );
        $student = $this->student(
            'Legacy Student',
            'legacy.student@example.test',
        );

        $before = $this->tableCounts();
        $this->freeze();
        $this->actingAs($admin);

        $this->getJson('/api/admin/teachers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.user_id',
                $teacher->id
            );

        $this->getJson(
            '/api/admin/teachers/'.$teacher->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.user_id',
                $teacher->id
            );

        $this->getJson('/api/admin/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.user_id',
                $student['user']->id
            );

        $this->getJson(
            '/api/admin/students/'
            .$student['user']->id
        )
            ->assertOk()
            ->assertJsonPath(
                'data.learner_profile_id',
                $student['profile']->id
            );

        $this->assertSame(
            $before,
            $this->tableCounts()
        );
    }

    private function freeze(): void
    {
        $this->connection->statement(
            'SET TRANSACTION READ ONLY'
        );
    }

    private function directory(
        string $resource,
        array $query,
    ) {
        return $this->getJson(
            '/api/admin/directories/'
            .$resource
            .'?'
            .http_build_query($query)
        )->assertOk();
    }

    private function admin(): User
    {
        return $this->user(
            'Directory Admin',
            'directory.admin.'
            .Str::uuid()
            .'@example.test',
            'admin',
        );
    }

    private function user(
        string $name,
        string $email,
        string $role,
        string $status = 'active',
    ): User {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function student(
        string $name,
        string $email,
    ): array {
        $user = $this->user(
            $name,
            $email,
            'student',
        );
        $profile = LearnerProfile::query()
            ->create([
                'user_id' => $user->id,
            ]);

        return [
            'user' => $user,
            'profile' => $profile,
        ];
    }

    private function tableCounts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'profiles' => DB::table(
                'learner_profiles'
            )->count(),
            'assignments' => DB::table(
                'teacher_subject_assignments'
            )->count(),
            'enrollments' => DB::table(
                'student_enrollments'
            )->count(),
        ];
    }
}

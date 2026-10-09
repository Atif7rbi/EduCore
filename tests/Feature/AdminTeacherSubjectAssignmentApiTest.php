<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTeacherSubjectAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_and_list_teacher_subjects_in_canonical_order(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $mathematics = $this->subject(
            'mathematics'
        );

        $physics = $this->subject(
            'physics'
        );

        $physicsOperation =
            (string) Str::uuid();

        $mathematicsOperation =
            (string) Str::uuid();

        /*
         * Create deliberately out of catalog order.
         * Read order must come from canonical Subject
         * ordering, not insertion order.
         */
        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $physics->id,
                    'operation_id' => $physicsOperation,
                    'reason' => 'Assign physics.',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.teacher_user_id',
                $teacher->id,
            )
            ->assertJsonPath(
                'data.subject.id',
                $physics->id,
            )
            ->assertJsonPath(
                'data.subject.code',
                'physics',
            )
            ->assertJsonPath(
                'data.status',
                'active',
            );

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $mathematics->id,
                    'operation_id' => $mathematicsOperation,
                    'reason' => 'Assign mathematics.',
                ],
            )
            ->assertOk();

        /*
         * A different Teacher assignment must not leak
         * into this Teacher's read model.
         */
        $otherTeacher = $this->teacher();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$otherTeacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $mathematics->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Other teacher fixture.',
                ],
            )
            ->assertOk();

        $response = $this
            ->actingAs($admin)
            ->getJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments'
            )
            ->assertOk()
            ->assertJsonCount(
                2,
                'data',
            );

        $data = $response->json(
            'data'
        );

        $expectedCodes =
            Subject::query()
                ->whereIn(
                    'id',
                    [
                        $mathematics->id,
                        $physics->id,
                    ],
                )
                ->orderBy('sort_order')
                ->orderBy('code')
                ->orderBy('id')
                ->pluck('code')
                ->all();

        $actualCodes = array_map(
            static fn (
                array $assignment
            ): string => $assignment['subject']['code'],
            $data,
        );

        $this->assertSame(
            $expectedCodes,
            $actualCodes,
        );

        foreach ($data as $assignment) {
            $this->assertSame(
                $teacher->id,
                $assignment[
                    'teacher_user_id'
                ],
            );

            $this->assertSame(
                'active',
                $assignment['status'],
            );
        }

        $this->assertDatabaseHas(
            'teacher_subject_assignment_transitions',
            [
                'operation_id' => $physicsOperation,
                'to_status' => 'active',
                'reason' => 'Assign physics.',
            ],
        );

        $this->assertDatabaseHas(
            'teacher_subject_assignment_transitions',
            [
                'operation_id' => $mathematicsOperation,
                'to_status' => 'active',
                'reason' => 'Assign mathematics.',
            ],
        );
    }

    public function test_assignment_http_replay_is_idempotent(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $subject =
            $this->subject(
                'mathematics'
            );

        $operationId =
            (string) Str::uuid();

        $payload = [
            'subject_id' => $subject->id,
            'operation_id' => $operationId,
            'reason' => 'Idempotent assignment.',
        ];

        $first = $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                $payload,
            )
            ->assertOk();

        $second = $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                $payload,
            )
            ->assertOk();

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
        );

        $this->assertSame(
            1,
            DB::table(
                'teacher_subject_assignment_transitions'
            )
                ->where(
                    'operation_id',
                    $operationId,
                )
                ->count(),
        );

        $this->assertSame(
            1,
            TeacherSubjectAssignment::query()
                ->where(
                    'teacher_id',
                    $teacher->id,
                )
                ->where(
                    'subject_id',
                    $subject->id,
                )
                ->count(),
        );
    }

    public function test_assignment_operation_conflict_maps_to_409(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $mathematics =
            $this->subject(
                'mathematics'
            );

        $physics =
            $this->subject(
                'physics'
            );

        $operationId =
            (string) Str::uuid();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $mathematics->id,
                    'operation_id' => $operationId,
                    'reason' => 'Canonical facts.',
                ],
            )
            ->assertOk();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $physics->id,
                    'operation_id' => $operationId,
                    'reason' => 'Canonical facts.',
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'teacher_subject_assignment_operation_conflict',
            );

        $this->assertSame(
            1,
            DB::table(
                'teacher_subject_assignment_transitions'
            )
                ->where(
                    'operation_id',
                    $operationId,
                )
                ->count(),
        );
    }

    public function test_admin_can_deactivate_and_reactivate_same_assignment_identity(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $assignment =
            $this->assign(
                admin: $admin,
                teacher: $teacher,
                subject: $this->subject(
                    'mathematics'
                ),
            );

        $deactivateOperation =
            (string) Str::uuid();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/'
                .'teacher-subject-assignments/'
                .$assignment->id
                .'/deactivate',
                [
                    'operation_id' => $deactivateOperation,
                    'reason' => 'Administrative deactivation.',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $assignment->id,
            )
            ->assertJsonPath(
                'data.status',
                'inactive',
            );

        /*
         * Exact replay must not create another
         * transition.
         */
        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/'
                .'teacher-subject-assignments/'
                .$assignment->id
                .'/deactivate',
                [
                    'operation_id' => $deactivateOperation,
                    'reason' => 'Administrative deactivation.',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'inactive',
            );

        $this->assertSame(
            1,
            DB::table(
                'teacher_subject_assignment_transitions'
            )
                ->where(
                    'operation_id',
                    $deactivateOperation,
                )
                ->count(),
        );

        $reactivateOperation =
            (string) Str::uuid();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/'
                .'teacher-subject-assignments/'
                .$assignment->id
                .'/reactivate',
                [
                    'operation_id' => $reactivateOperation,
                    'reason' => 'Administrative reactivation.',
                ],
            )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $assignment->id,
            )
            ->assertJsonPath(
                'data.status',
                'active',
            );

        $this->assertSame(
            $assignment->id,
            TeacherSubjectAssignment::query()
                ->where(
                    'teacher_id',
                    $teacher->id,
                )
                ->where(
                    'subject_id',
                    $assignment
                        ->subject_id,
                )
                ->firstOrFail()
                ->id,
        );
    }

    public function test_deactivation_remains_available_after_teacher_disable_but_reactivation_rechecks_eligibility(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $assignment =
            $this->assign(
                admin: $admin,
                teacher: $teacher,
                subject: $this->subject(
                    'mathematics'
                ),
            );

        $teacher->forceFill([
            'status' => 'disabled',
        ])->save();

        /*
         * Historical cleanup/deactivation remains
         * permitted after Teacher disable.
         */
        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/'
                .'teacher-subject-assignments/'
                .$assignment->id
                .'/deactivate',
                $this->operationPayload(
                    'Deactivate after teacher disable.'
                ),
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'inactive',
            );

        /*
         * Reactivation is a new effective grant and
         * therefore requires current Teacher
         * eligibility.
         */
        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/'
                .'teacher-subject-assignments/'
                .$assignment->id
                .'/reactivate',
                $this->operationPayload(
                    'Rejected while teacher disabled.'
                ),
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );

        $teacher->forceFill([
            'status' => 'active',
        ])->save();

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/'
                .'teacher-subject-assignments/'
                .$assignment->id
                .'/reactivate',
                $this->operationPayload(
                    'Reactivate after teacher restoration.'
                ),
            )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'active',
            );
    }

    public function test_assignment_requires_active_teacher_and_active_canonical_subject(): void
    {
        $admin = $this->admin();

        $disabledTeacher =
            User::factory()->create([
                'role' => 'teacher',
                'status' => 'disabled',
            ]);

        $mathematics =
            $this->subject(
                'mathematics'
            );

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$disabledTeacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $mathematics->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Disabled teacher.',
                ],
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );

        $teacher = $this->teacher();

        $physics =
            $this->subject(
                'physics'
            );

        DB::table('subjects')
            ->where(
                'id',
                $physics->id,
            )
            ->update([
                'status' => 'inactive',
            ]);

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $physics->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Inactive subject.',
                ],
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );

        $student =
            User::factory()->create([
                'role' => 'student',
                'status' => 'active',
            ]);

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$student->id
                .'/subject-assignments',
                [
                    'subject_id' => $mathematics->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Wrong User role.',
                ],
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_assignment_endpoints_reject_client_supplied_authority_fields(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $subject =
            $this->subject(
                'mathematics'
            );

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $subject->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Authority injection.',
                    'actor_user_id' => (string) Str::uuid(),
                    'teacher_id' => (string) Str::uuid(),
                    'teacher_user_id' => (string) Str::uuid(),
                    'status' => 'inactive',
                ],
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'actor_user_id',
                        'teacher_id',
                        'teacher_user_id',
                        'status',
                    ],
                ],
            ]);

        $assignment =
            $this->assign(
                admin: $admin,
                teacher: $teacher,
                subject: $subject,
            );

        $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/'
                .'teacher-subject-assignments/'
                .$assignment->id
                .'/deactivate',
                [
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Lifecycle injection.',
                    'subject_id' => (string) Str::uuid(),
                    'status' => 'inactive',
                ],
            )
            ->assertStatus(422)
            ->assertJsonPath(
                'error.code',
                'validation_failed',
            )
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'subject_id',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_assignment_admin_routes_enforce_management_boundary(): void
    {
        $teacher = $this->teacher();

        $subject =
            $this->subject(
                'mathematics'
            );

        $payload = [
            'subject_id' => $subject->id,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'Authorization probe.',
        ];

        $this
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                $payload,
            )
            ->assertStatus(401)
            ->assertJsonPath(
                'error.code',
                'unauthenticated',
            );

        $nonAdmin =
            User::factory()->create([
                'role' => 'teacher',
                'status' => 'active',
            ]);

        $this
            ->actingAs($nonAdmin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                $payload,
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
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                $payload,
            )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'account_disabled',
            );
    }

    public function test_assignment_list_requires_teacher_user_identity(): void
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
                '/api/admin/teachers/'
                .$student->id
                .'/subject-assignments'
            )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
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

    private function subject(
        string $code,
    ): Subject {
        return Subject::query()
            ->where('code', $code)
            ->firstOrFail();
    }

    private function assign(
        User $admin,
        User $teacher,
        Subject $subject,
    ): TeacherSubjectAssignment {
        $response = $this
            ->actingAs($admin)
            ->postJson(
                '/api/admin/teachers/'
                .$teacher->id
                .'/subject-assignments',
                [
                    'subject_id' => $subject->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'HTTP assignment fixture.',
                ],
            )
            ->assertOk();

        return TeacherSubjectAssignment::query()
            ->whereKey(
                $response->json('data.id')
            )
            ->firstOrFail();
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

<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\TeacherSubjectAssignmentTransition;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherSubjectAssignmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_teacher_can_be_assigned_once_to_active_canonical_subject(): void
    {
        $teacher = $this->teacher();
        $subject = $this->canonicalSubject();

        $assignment = TeacherSubjectAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'status' => 'active',
        ]);

        $this->assertNotNull($assignment->id);
        $this->assertSame($teacher->id, $assignment->teacher_id);
        $this->assertSame($subject->id, $assignment->subject_id);
        $this->assertTrue($assignment->isActive());

        $this->assertTrue(
            $assignment->teacher->isTeacher()
        );

        $this->assertSame(
            $subject->id,
            $assignment->subject->id
        );
    }

    public function test_teacher_subject_pair_is_unique(): void
    {
        $teacher = $this->teacher();
        $subject = $this->canonicalSubject();

        $this->assignment($teacher, $subject);

        $this->expectException(QueryException::class);

        $this->assignment($teacher, $subject);
    }

    public function test_assignment_cannot_be_created_inactive(): void
    {
        $teacher = $this->teacher();
        $subject = $this->canonicalSubject();

        $this->expectException(QueryException::class);

        TeacherSubjectAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'status' => 'inactive',
        ]);
    }

    public function test_assignment_target_must_be_active_teacher(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $subject = $this->canonicalSubject();

        $this->expectException(QueryException::class);

        TeacherSubjectAssignment::query()->create([
            'teacher_id' => $student->id,
            'subject_id' => $subject->id,
            'status' => 'active',
        ]);
    }

    public function test_disabled_teacher_cannot_receive_new_assignment(): void
    {
        $teacher = $this->teacher('disabled');
        $subject = $this->canonicalSubject();

        $this->expectException(QueryException::class);

        TeacherSubjectAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'status' => 'active',
        ]);
    }

    public function test_legacy_subject_cannot_receive_new_assignment(): void
    {
        $teacher = $this->teacher();

        $subject = Subject::query()->create([
            'name' => 'Legacy Subject '.Str::uuid(),
        ]);

        $this->expectException(QueryException::class);

        TeacherSubjectAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'status' => 'active',
        ]);
    }

    public function test_inactive_canonical_subject_cannot_receive_new_assignment(): void
    {
        $teacher = $this->teacher();
        $subject = $this->canonicalSubject();

        DB::table('subjects')
            ->where('id', $subject->id)
            ->update([
                'status' => 'inactive',
            ]);

        $this->expectException(QueryException::class);

        TeacherSubjectAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'status' => 'active',
        ]);
    }

    public function test_assignment_identity_is_db_immutable(): void
    {
        $teacher = $this->teacher();
        $otherTeacher = $this->teacher();
        $subject = $this->canonicalSubject();

        $assignment = $this->assignment(
            $teacher,
            $subject,
        );

        $this->expectException(QueryException::class);

        DB::table('teacher_subject_assignments')
            ->where('id', $assignment->id)
            ->update([
                'teacher_id' => $otherTeacher->id,
            ]);
    }

    public function test_assignment_can_be_deactivated_even_if_teacher_is_disabled(): void
    {
        $teacher = $this->teacher();
        $subject = $this->canonicalSubject();

        $assignment = $this->assignment(
            $teacher,
            $subject,
        );

        DB::table('users')
            ->where('id', $teacher->id)
            ->update([
                'status' => 'disabled',
            ]);

        DB::table('teacher_subject_assignments')
            ->where('id', $assignment->id)
            ->update([
                'status' => 'inactive',
            ]);

        $this->assertDatabaseHas(
            'teacher_subject_assignments',
            [
                'id' => $assignment->id,
                'status' => 'inactive',
            ]
        );
    }

    public function test_reactivation_requires_current_teacher_and_subject_eligibility(): void
    {
        $teacher = $this->teacher();
        $subject = $this->canonicalSubject();

        $assignment = $this->assignment(
            $teacher,
            $subject,
        );

        DB::table('teacher_subject_assignments')
            ->where('id', $assignment->id)
            ->update([
                'status' => 'inactive',
            ]);

        DB::table('subjects')
            ->where('id', $subject->id)
            ->update([
                'status' => 'inactive',
            ]);

        $this->expectException(QueryException::class);

        DB::table('teacher_subject_assignments')
            ->where('id', $assignment->id)
            ->update([
                'status' => 'active',
            ]);
    }

    public function test_assignment_cannot_be_deleted(): void
    {
        $assignment = $this->assignment(
            $this->teacher(),
            $this->canonicalSubject(),
        );

        $this->expectException(QueryException::class);

        DB::table('teacher_subject_assignments')
            ->where('id', $assignment->id)
            ->delete();
    }

    public function test_assignment_cannot_commit_without_authoritative_transition_history(): void
    {
        $teacher = $this->teacher();
        $subject = $this->canonicalSubject();

        DB::beginTransaction();

        try {
            TeacherSubjectAssignment::query()->create([
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'status' => 'active',
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

    public function test_status_change_cannot_commit_without_matching_transition(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        DB::beginTransaction();

        try {
            $assignment = $this->assignment(
                $this->teacher(),
                $this->canonicalSubject(),
            );

            $this->transition(
                $assignment,
                $admin,
                (string) Str::uuid(),
            );

            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        DB::beginTransaction();

        try {
            DB::table('teacher_subject_assignments')
                ->where('id', $assignment->id)
                ->update([
                    'status' => 'inactive',
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

    public function test_transition_history_chain_must_match_previous_status(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        DB::beginTransaction();

        try {
            $assignment = $this->assignment(
                $this->teacher(),
                $this->canonicalSubject(),
            );

            $this->transition(
                $assignment,
                $admin,
                (string) Str::uuid(),
            );

            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        $this->expectException(QueryException::class);

        TeacherSubjectAssignmentTransition::query()
            ->create([
                'assignment_id' => $assignment->id,
                'from_status' => 'inactive',
                'to_status' => 'active',
                'actor_user_id' => $admin->id,
                'operation_id' => (string) Str::uuid(),
                'reason' => 'Invalid historical chain.',
                'effective_at' => now(),
            ]);
    }

    public function test_matching_state_and_transition_can_commit_atomically(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        DB::beginTransaction();

        try {
            $assignment = $this->assignment(
                $this->teacher(),
                $this->canonicalSubject(),
            );

            $this->transition(
                $assignment,
                $admin,
                (string) Str::uuid(),
            );

            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        DB::beginTransaction();

        try {
            DB::table('teacher_subject_assignments')
                ->where('id', $assignment->id)
                ->update([
                    'status' => 'inactive',
                ]);

            TeacherSubjectAssignmentTransition::query()
                ->create([
                    'assignment_id' => $assignment->id,
                    'from_status' => 'active',
                    'to_status' => 'inactive',
                    'actor_user_id' => $admin->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Assignment deactivated.',
                    'effective_at' => now(),
                ]);

            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        $this->assertDatabaseHas(
            'teacher_subject_assignments',
            [
                'id' => $assignment->id,
                'status' => 'inactive',
            ]
        );

        $this->assertDatabaseHas(
            'teacher_subject_assignment_transitions',
            [
                'assignment_id' => $assignment->id,
                'from_status' => 'active',
                'to_status' => 'inactive',
            ]
        );
    }

    public function test_creation_transition_can_be_recorded(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $assignment = $this->assignment(
            $this->teacher(),
            $this->canonicalSubject(),
        );

        $operationId = (string) Str::uuid();

        $transition =
            TeacherSubjectAssignmentTransition::query()
                ->create([
                    'assignment_id' => $assignment->id,
                    'from_status' => null,
                    'to_status' => 'active',
                    'actor_user_id' => $admin->id,
                    'operation_id' => $operationId,
                    'reason' => 'Initial assignment.',
                    'effective_at' => now(),
                ]);

        $this->assertNotNull($transition->id);
        $this->assertSame(
            $assignment->id,
            $transition->assignment->id
        );
        $this->assertSame(
            $admin->id,
            $transition->actor->id
        );
    }

    public function test_invalid_transition_shape_is_db_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $assignment = $this->assignment(
            $this->teacher(),
            $this->canonicalSubject(),
        );

        $this->expectException(QueryException::class);

        TeacherSubjectAssignmentTransition::query()
            ->create([
                'assignment_id' => $assignment->id,
                'from_status' => 'active',
                'to_status' => 'active',
                'actor_user_id' => $admin->id,
                'operation_id' => (string) Str::uuid(),
                'reason' => 'Invalid same-state transition.',
                'effective_at' => now(),
            ]);
    }

    public function test_transition_actor_must_be_active_admin(): void
    {
        $teacherActor = $this->teacher();

        DB::beginTransaction();

        try {
            $assignment = $this->assignment(
                $this->teacher(),
                $this->canonicalSubject(),
            );

            $this->expectException(QueryException::class);

            TeacherSubjectAssignmentTransition::query()
                ->create([
                    'assignment_id' => $assignment->id,
                    'from_status' => null,
                    'to_status' => 'active',
                    'actor_user_id' => $teacherActor->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Invalid actor.',
                    'effective_at' => now(),
                ]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_transition_sequence_number_cannot_be_supplied_by_caller(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        DB::beginTransaction();

        try {
            $assignment = $this->assignment(
                $this->teacher(),
                $this->canonicalSubject(),
            );

            $this->expectException(QueryException::class);

            DB::table(
                'teacher_subject_assignment_transitions'
            )->insert([
                'id' => (string) Str::uuid(),
                'sequence_number' => 999999,
                'assignment_id' => $assignment->id,
                'from_status' => null,
                'to_status' => 'active',
                'actor_user_id' => $admin->id,
                'operation_id' => (string) Str::uuid(),
                'reason' => 'Caller-controlled sequence.',
                'effective_at' => now(),
                'created_at' => now(),
            ]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_transition_sequence_is_database_ordered(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        DB::beginTransaction();

        try {
            $assignment = $this->assignment(
                $this->teacher(),
                $this->canonicalSubject(),
            );

            $first = $this->transition(
                $assignment,
                $admin,
                (string) Str::uuid(),
            );

            DB::table('teacher_subject_assignments')
                ->where('id', $assignment->id)
                ->update([
                    'status' => 'inactive',
                ]);

            $second =
                TeacherSubjectAssignmentTransition::query()
                    ->create([
                        'assignment_id' => $assignment->id,
                        'from_status' => 'active',
                        'to_status' => 'inactive',
                        'actor_user_id' => $admin->id,
                        'operation_id' => (string) Str::uuid(),
                        'reason' => 'Deactivated.',
                        'effective_at' => now()->subYear(),
                    ]);

            $firstSequence = DB::table(
                'teacher_subject_assignment_transitions'
            )
                ->where('id', $first->id)
                ->value('sequence_number');

            $secondSequence = DB::table(
                'teacher_subject_assignment_transitions'
            )
                ->where('id', $second->id)
                ->value('sequence_number');

            $this->assertIsInt($firstSequence);
            $this->assertIsInt($secondSequence);

            $this->assertGreaterThan(
                $firstSequence,
                $secondSequence,
            );

            DB::statement(
                'SET CONSTRAINTS ALL IMMEDIATE'
            );

            DB::rollBack();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    public function test_transition_operation_id_is_unique(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $assignment = $this->assignment(
            $this->teacher(),
            $this->canonicalSubject(),
        );

        $operationId = (string) Str::uuid();

        $this->transition(
            $assignment,
            $admin,
            $operationId,
        );

        $this->expectException(QueryException::class);

        $this->transition(
            $assignment,
            $admin,
            $operationId,
        );
    }

    public function test_transition_history_cannot_be_updated(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $assignment = $this->assignment(
            $this->teacher(),
            $this->canonicalSubject(),
        );

        $transition = $this->transition(
            $assignment,
            $admin,
            (string) Str::uuid(),
        );

        $this->expectException(QueryException::class);

        DB::table('teacher_subject_assignment_transitions')
            ->where('id', $transition->id)
            ->update([
                'reason' => 'Rewritten history.',
            ]);
    }

    public function test_transition_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $assignment = $this->assignment(
            $this->teacher(),
            $this->canonicalSubject(),
        );

        $transition = $this->transition(
            $assignment,
            $admin,
            (string) Str::uuid(),
        );

        $this->expectException(QueryException::class);

        DB::table('teacher_subject_assignment_transitions')
            ->where('id', $transition->id)
            ->delete();
    }

    private function teacher(
        string $status = 'active',
    ): User {
        return User::factory()->create([
            'role' => 'teacher',
            'status' => $status,
        ]);
    }

    private function canonicalSubject(): Subject
    {
        return Subject::query()
            ->whereNotNull('code')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->firstOrFail();
    }

    private function assignment(
        User $teacher,
        Subject $subject,
    ): TeacherSubjectAssignment {
        return TeacherSubjectAssignment::query()->create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'status' => 'active',
        ]);
    }

    private function transition(
        TeacherSubjectAssignment $assignment,
        User $actor,
        string $operationId,
    ): TeacherSubjectAssignmentTransition {
        return TeacherSubjectAssignmentTransition::query()
            ->create([
                'assignment_id' => $assignment->id,
                'from_status' => null,
                'to_status' => 'active',
                'actor_user_id' => $actor->id,
                'operation_id' => $operationId,
                'reason' => 'Initial assignment.',
                'effective_at' => now(),
            ]);
    }
}

<?php

namespace Tests\Feature;

use App\Application\Exceptions\TeacherSubjectAssignmentOperationConflict;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Application\TeacherAssignment\ReactivateTeacherSubjectAssignment;
use App\Models\Subject;
use App\Models\TeacherSubjectAssignment;
use App\Models\TeacherSubjectAssignmentTransition;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherSubjectAssignmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_active_teacher_to_active_canonical_subject(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();
        $operationId = (string) Str::uuid();

        $assignment = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                $operationId,
                'Initial assignment.',
            );

        $this->assertSame('active', $assignment->status);
        $this->assertSame($teacher->id, $assignment->teacher_id);
        $this->assertSame($subject->id, $assignment->subject_id);

        $this->assertDatabaseHas(
            'teacher_subject_assignment_transitions',
            [
                'assignment_id' => $assignment->id,
                'from_status' => null,
                'to_status' => 'active',
                'actor_user_id' => $admin->id,
                'operation_id' => $operationId,
                'reason' => 'Initial assignment.',
            ]
        );
    }

    public function test_assignment_replay_returns_same_identity_without_duplicate_history(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();
        $operationId = (string) Str::uuid();

        $service = app(AssignTeacherSubject::class);

        $first = $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Initial assignment.',
        );

        $second = $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Initial assignment.',
        );

        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            1,
            TeacherSubjectAssignmentTransition::query()
                ->where('operation_id', $operationId)
                ->count()
        );
    }

    public function test_assignment_replay_survives_later_teacher_disable(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();
        $operationId = (string) Str::uuid();

        $service = app(AssignTeacherSubject::class);

        $first = $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Initial assignment.',
        );

        DB::table('users')
            ->where('id', $teacher->id)
            ->update([
                'status' => 'disabled',
            ]);

        $replay = $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Initial assignment.',
        );

        $this->assertSame($first->id, $replay->id);
    }

    public function test_assignment_replay_survives_later_subject_deactivation(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();
        $operationId = (string) Str::uuid();

        $service = app(AssignTeacherSubject::class);

        $first = $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Initial assignment.',
        );

        DB::table('subjects')
            ->where('id', $subject->id)
            ->update([
                'status' => 'inactive',
            ]);

        $replay = $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Initial assignment.',
        );

        $this->assertSame($first->id, $replay->id);
    }

    public function test_assignment_operation_replay_with_different_facts_conflicts(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();
        $operationId = (string) Str::uuid();

        $service = app(AssignTeacherSubject::class);

        $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Initial assignment.',
        );

        $this->expectException(
            TeacherSubjectAssignmentOperationConflict::class
        );

        $service->execute(
            $admin->id,
            $teacher->id,
            $subject->id,
            $operationId,
            'Different reason.',
        );
    }

    public function test_assign_reuses_inactive_assignment_identity_and_reactivates_it(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $assignment = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Initial assignment.',
            );

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Temporarily disabled.',
            );

        $reactivated = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Assigned again.',
            );

        $this->assertSame($assignment->id, $reactivated->id);
        $this->assertSame('active', $reactivated->status);

        $this->assertSame(
            1,
            TeacherSubjectAssignment::query()
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $subject->id)
                ->count()
        );
    }

    public function test_deactivation_writes_authoritative_transition(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        $operationId = (string) Str::uuid();

        $result = app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Administrative deactivation.',
        );

        $this->assertSame('inactive', $result->status);

        $this->assertDatabaseHas(
            'teacher_subject_assignment_transitions',
            [
                'assignment_id' => $assignment->id,
                'from_status' => 'active',
                'to_status' => 'inactive',
                'actor_user_id' => $admin->id,
                'operation_id' => $operationId,
                'reason' => 'Administrative deactivation.',
            ]
        );
    }

    public function test_deactivation_is_allowed_after_teacher_is_disabled(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        DB::table('users')
            ->where('id', $assignment->teacher_id)
            ->update(['status' => 'disabled']);

        $result = app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            $admin->id,
            $assignment->id,
            (string) Str::uuid(),
            'Close disabled teacher assignment.',
        );

        $this->assertSame('inactive', $result->status);
    }

    public function test_deactivation_is_allowed_after_subject_is_inactive(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        DB::table('subjects')
            ->where('id', $assignment->subject_id)
            ->update([
                'status' => 'inactive',
            ]);

        $result = app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            $admin->id,
            $assignment->id,
            (string) Str::uuid(),
            'Close inactive subject assignment.',
        );

        $this->assertSame('inactive', $result->status);
    }

    public function test_deactivation_replay_is_idempotent(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        $operationId = (string) Str::uuid();
        $service = app(
            DeactivateTeacherSubjectAssignment::class
        );

        $first = $service->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Deactivate.',
        );

        $second = $service->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Deactivate.',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame('inactive', $second->status);

        $this->assertSame(
            1,
            TeacherSubjectAssignmentTransition::query()
                ->where('operation_id', $operationId)
                ->count()
        );
    }

    public function test_deactivation_operation_cannot_be_replayed_for_different_assignment(): void
    {
        [$admin, $firstAssignment] = $this->activeAssignment();

        $teacher = $this->teacher();
        $subject = $this->subject();

        $secondAssignment = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Second assignment.',
            );

        $operationId = (string) Str::uuid();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $firstAssignment->id,
                $operationId,
                'Shared operation.',
            );

        $this->expectException(
            TeacherSubjectAssignmentOperationConflict::class
        );

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $secondAssignment->id,
                $operationId,
                'Shared operation.',
            );
    }

    public function test_deactivation_replay_after_later_reactivation_does_not_mutate_current_state(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        $deactivateOperation = (string) Str::uuid();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                $deactivateOperation,
                'Deactivate.',
            );

        app(ReactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Reactivate later.',
            );

        $replay = app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            $admin->id,
            $assignment->id,
            $deactivateOperation,
            'Deactivate.',
        );

        $this->assertSame('active', $replay->status);

        $this->assertSame(
            1,
            TeacherSubjectAssignmentTransition::query()
                ->where(
                    'operation_id',
                    $deactivateOperation,
                )
                ->count()
        );
    }

    public function test_reactivation_uses_same_assignment_identity(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate.',
            );

        $operationId = (string) Str::uuid();

        $reactivated = app(
            ReactivateTeacherSubjectAssignment::class
        )->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Reactivate.',
        );

        $this->assertSame($assignment->id, $reactivated->id);
        $this->assertSame('active', $reactivated->status);

        $this->assertDatabaseHas(
            'teacher_subject_assignment_transitions',
            [
                'assignment_id' => $assignment->id,
                'from_status' => 'inactive',
                'to_status' => 'active',
                'operation_id' => $operationId,
            ]
        );
    }

    public function test_reactivation_replay_survives_later_teacher_disable(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate.',
            );

        $operationId = (string) Str::uuid();

        $service = app(
            ReactivateTeacherSubjectAssignment::class
        );

        $first = $service->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Reactivate.',
        );

        DB::table('users')
            ->where('id', $assignment->teacher_id)
            ->update([
                'status' => 'disabled',
            ]);

        $replay = $service->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Reactivate.',
        );

        $this->assertSame($first->id, $replay->id);
    }

    public function test_reactivation_replay_survives_later_subject_deactivation(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate.',
            );

        $operationId = (string) Str::uuid();

        $service = app(
            ReactivateTeacherSubjectAssignment::class
        );

        $first = $service->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Reactivate.',
        );

        DB::table('subjects')
            ->where('id', $assignment->subject_id)
            ->update([
                'status' => 'inactive',
            ]);

        $replay = $service->execute(
            $admin->id,
            $assignment->id,
            $operationId,
            'Reactivate.',
        );

        $this->assertSame($first->id, $replay->id);
    }

    public function test_reactivation_requires_teacher_to_be_active(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate.',
            );

        DB::table('users')
            ->where('id', $assignment->teacher_id)
            ->update(['status' => 'disabled']);

        $this->expectException(ModelNotFoundException::class);

        app(ReactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Reactivate.',
            );
    }

    public function test_reactivation_requires_subject_to_be_active(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate.',
            );

        DB::table('subjects')
            ->where('id', $assignment->subject_id)
            ->update(['status' => 'inactive']);

        $this->expectException(ModelNotFoundException::class);

        app(ReactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Reactivate.',
            );
    }

    public function test_disabled_admin_cannot_assign_teacher_subject(): void
    {
        $admin = $this->admin();

        DB::table('users')
            ->where('id', $admin->id)
            ->update([
                'status' => 'disabled',
            ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $this->teacher()->id,
                $this->subject()->id,
                (string) Str::uuid(),
                'Disabled admin.',
            );
    }

    public function test_disabled_admin_cannot_deactivate_assignment(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        DB::table('users')
            ->where('id', $admin->id)
            ->update([
                'status' => 'disabled',
            ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Disabled admin.',
            );
    }

    public function test_disabled_admin_cannot_reactivate_assignment(): void
    {
        [$admin, $assignment] = $this->activeAssignment();

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Deactivate.',
            );

        DB::table('users')
            ->where('id', $admin->id)
            ->update([
                'status' => 'disabled',
            ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(ReactivateTeacherSubjectAssignment::class)
            ->execute(
                $admin->id,
                $assignment->id,
                (string) Str::uuid(),
                'Disabled admin.',
            );
    }

    public function test_non_admin_actor_cannot_mutate_assignment(): void
    {
        $teacherActor = $this->teacher();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $this->expectException(ModelNotFoundException::class);

        app(AssignTeacherSubject::class)
            ->execute(
                $teacherActor->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Unauthorized.',
            );
    }

    public function test_same_state_with_new_operation_is_noop_without_fake_transition(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $assignment = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Initial assignment.',
            );

        $before = TeacherSubjectAssignmentTransition::query()
            ->where('assignment_id', $assignment->id)
            ->count();

        $noopOperation = (string) Str::uuid();

        $same = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                $noopOperation,
                'Already active.',
            );

        $after = TeacherSubjectAssignmentTransition::query()
            ->where('assignment_id', $assignment->id)
            ->count();

        $this->assertSame($assignment->id, $same->id);
        $this->assertSame($before, $after);

        $this->assertDatabaseMissing(
            'teacher_subject_assignment_transitions',
            ['operation_id' => $noopOperation]
        );
    }

    /**
     * @return array{User, TeacherSubjectAssignment}
     */
    private function activeAssignment(): array
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $assignment = app(AssignTeacherSubject::class)
            ->execute(
                $admin->id,
                $teacher->id,
                $subject->id,
                (string) Str::uuid(),
                'Initial assignment.',
            );

        return [$admin, $assignment];
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

    private function subject(): Subject
    {
        return Subject::query()
            ->whereNotNull('code')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->firstOrFail();
    }
}

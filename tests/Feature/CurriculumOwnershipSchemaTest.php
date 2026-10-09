<?php

namespace Tests\Feature;

use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurriculumOwnershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_curricula_has_nullable_owner_column_and_composite_foreign_key(): void
    {
        $column = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'curricula')
            ->where(
                'column_name',
                'teacher_subject_assignment_id'
            )
            ->first();

        $this->assertNotNull($column);
        $this->assertSame('YES', $column->is_nullable);

        $constraint = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(oid) AS definition
FROM pg_constraint
WHERE conrelid = 'curricula'::regclass
  AND conname =
      'fk_curricula_teacher_assignment_subject'
SQL);

        $this->assertNotNull($constraint);

        $definition = (string) $constraint->definition;

        $this->assertStringContainsString(
            'FOREIGN KEY (teacher_subject_assignment_id, subject_id)',
            $definition,
        );

        $this->assertStringContainsString(
            'REFERENCES teacher_subject_assignments(id, subject_id)',
            $definition,
        );
    }

    public function test_future_curriculum_insert_requires_owner(): void
    {
        $subject = $this->canonicalSubject();

        $this->expectException(QueryException::class);

        DB::table('curricula')->insert([
            'id' => (string) Str::uuid(),
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => null,
            'name' => 'Ownerless Curriculum',
            'created_at' => now(),
            'updated_at' => null,
        ]);
    }

    public function test_valid_active_assignment_can_own_curriculum(): void
    {
        [$assignment, $subject] =
            $this->activeAssignment();

        $curriculumId = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'name' => 'Owned Curriculum',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $this->assertDatabaseHas('curricula', [
            'id' => $curriculumId,
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => $assignment->id,
        ]);
    }

    public function test_owner_assignment_subject_must_match_curriculum_subject(): void
    {
        [$assignment] = $this->activeAssignment();

        $otherSubject = $this->canonicalSubject(
            code: 'physics',
            name: 'Physics Test Subject',
        );

        $this->expectException(QueryException::class);

        DB::table('curricula')->insert([
            'id' => (string) Str::uuid(),
            'subject_id' => $otherSubject->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'name' => 'Mismatched Ownership',
            'created_at' => now(),
            'updated_at' => null,
        ]);
    }

    public function test_inactive_assignment_cannot_own_new_curriculum(): void
    {
        [$assignment, $subject] =
            $this->activeAssignment();

        DB::table('teacher_subject_assignments')
            ->where('id', $assignment->id)
            ->update([
                'status' => 'inactive',
            ]);

        /*
         * Keep the assignment history consistent for the
         * deferred DB contract.
         */
        DB::table(
            'teacher_subject_assignment_transitions'
        )->insert([
            'id' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'from_status' => 'active',
            'to_status' => 'inactive',
            'actor_user_id' => $this->activeAdmin()->id,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'Schema test deactivation',
            'effective_at' => now(),
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('curricula')->insert([
            'id' => (string) Str::uuid(),
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'name' => 'Inactive Assignment Curriculum',
            'created_at' => now(),
            'updated_at' => null,
        ]);
    }

    public function test_inactive_subject_cannot_receive_new_owned_curriculum(): void
    {
        [$assignment, $subject] =
            $this->activeAssignment();

        DB::table('subjects')
            ->where('id', $subject->id)
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        $this->expectException(QueryException::class);

        DB::table('curricula')->insert([
            'id' => (string) Str::uuid(),
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'name' => 'Inactive Subject Curriculum',
            'created_at' => now(),
            'updated_at' => null,
        ]);
    }

    public function test_disabled_teacher_cannot_receive_new_owned_curriculum(): void
    {
        [$assignment, $subject] =
            $this->activeAssignment();

        DB::table('users')
            ->where('id', $assignment->teacher_id)
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);

        $this->expectException(QueryException::class);

        DB::table('curricula')->insert([
            'id' => (string) Str::uuid(),
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'name' => 'Disabled Teacher Curriculum',
            'created_at' => now(),
            'updated_at' => null,
        ]);
    }

    public function test_curriculum_owner_is_db_immutable(): void
    {
        [$firstAssignment, $subject] =
            $this->activeAssignment();

        $secondTeacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $admin = $this->activeAdmin();

        $secondAssignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $secondTeacher->id,
            subjectId: $subject->id,
            operationId: (string) Str::uuid(),
            reason: 'Second owner candidate',
        );

        $curriculumId = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => $firstAssignment->id,
            'name' => 'Immutable Owner Curriculum',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $this->expectException(QueryException::class);

        DB::table('curricula')
            ->where('id', $curriculumId)
            ->update([
                'teacher_subject_assignment_id' => $secondAssignment->id,
            ]);
    }

    public function test_curriculum_subject_is_db_immutable(): void
    {
        [$assignment, $subject] =
            $this->activeAssignment();

        $curriculumId = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subject->id,
            'teacher_subject_assignment_id' => $assignment->id,
            'name' => 'Immutable Subject Curriculum',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $otherSubject = $this->canonicalSubject(
            code: 'physics',
            name: 'Physics Immutable Test',
        );

        $this->expectException(QueryException::class);

        DB::table('curricula')
            ->where('id', $curriculumId)
            ->update([
                'subject_id' => $otherSubject->id,
            ]);
    }

    private function activeAssignment(): array
    {
        $admin = $this->activeAdmin();

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $subject = $this->canonicalSubject();

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $subject->id,
            operationId: (string) Str::uuid(),
            reason: 'Curriculum ownership schema test',
        );

        return [$assignment, $subject];
    }

    private function activeAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function canonicalSubject(
        string $code = 'mathematics',
        string $name = 'Mathematics Test Subject',
    ): Subject {
        $existing = Subject::query()
            ->where('code', $code)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $id,
            'name' => $name.' '.$id,
            'code' => $code,
            'icon_key' => 'subjects/'.$code.'/icon',
            'thumbnail_key' => 'subjects/'.$code.'/thumbnail',
            'sort_order' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        return Subject::query()
            ->whereKey($id)
            ->firstOrFail();
    }
}

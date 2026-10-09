<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\Curriculum;
use App\Models\EducationStage;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateOwnedCurriculumTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_expose_curriculum_ownership_relationships(): void
    {
        $owner = (new Curriculum)
            ->teacherSubjectAssignment();

        $this->assertInstanceOf(
            BelongsTo::class,
            $owner
        );

        $this->assertSame(
            'teacher_subject_assignment_id',
            $owner->getForeignKeyName()
        );

        $curricula = (new TeacherSubjectAssignment)
            ->curricula();

        $this->assertInstanceOf(
            HasMany::class,
            $curricula
        );

        $this->assertSame(
            'teacher_subject_assignment_id',
            $curricula->getForeignKeyName()
        );
    }

    public function test_teacher_can_create_curriculum_through_own_active_assignment(): void
    {
        [$teacher, $assignment] =
            $this->activeAssignment();

        $stage = EducationStage::query()
            ->where('code', 'secondary')
            ->firstOrFail();

        $curriculum = app(
            CreateOwnedCurriculum::class
        )->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'Owned Secondary Curriculum',
            educationStageId: $stage->id,
        );

        $this->assertSame(
            $assignment->id,
            $curriculum->teacher_subject_assignment_id
        );

        $this->assertSame(
            $assignment->subject_id,
            $curriculum->subject_id
        );

        $this->assertSame(
            $stage->id,
            $curriculum->education_stage_id
        );
    }

    public function test_other_teacher_cannot_create_through_foreign_assignment(): void
    {
        [, $assignment] = $this->activeAssignment();

        $otherTeacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $otherTeacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'Foreign Assignment Attempt',
            educationStageId: null,
        );
    }

    public function test_inactive_assignment_cannot_create_curriculum(): void
    {
        [$teacher, $assignment] =
            $this->activeAssignment();

        DB::table('teacher_subject_assignments')
            ->where('id', $assignment->id)
            ->update([
                'status' => 'inactive',
            ]);

        DB::table(
            'teacher_subject_assignment_transitions'
        )->insert([
            'id' => (string) Str::uuid(),
            'assignment_id' => $assignment->id,
            'from_status' => 'active',
            'to_status' => 'inactive',
            'actor_user_id' => $this->admin()->id,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'Application boundary test',
            'effective_at' => now(),
            'created_at' => now(),
        ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'Inactive Assignment Attempt',
            educationStageId: null,
        );
    }

    public function test_inactive_stage_cannot_receive_new_curriculum(): void
    {
        [$teacher, $assignment] =
            $this->activeAssignment();

        $stage = EducationStage::query()
            ->where('code', 'secondary')
            ->firstOrFail();

        DB::table('education_stages')
            ->where('id', $stage->id)
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        $this->expectException(
            ModelNotFoundException::class
        );

        app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'Inactive Stage Attempt',
            educationStageId: $stage->id,
        );
    }

    public function test_admin_curriculum_create_and_update_routes_are_disabled(): void
    {
        $this->actingAs($this->admin());

        $subjectId = DB::table('subjects')
            ->where('code', 'mathematics')
            ->value('id');

        $this->assertNotNull($subjectId);

        $this->postJson(
            "/api/admin/subjects/{$subjectId}/curricula",
            [
                'name' => 'Admin Write Attempt',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_authoring_disabled'
            );

        /*
         * UUID is sufficient: boundary must reject before aggregate
         * mutation semantics are exposed to Admin.
         */
        $this->putJson(
            '/api/admin/curricula/'
            .Str::uuid(),
            [
                'name' => 'Admin Update Attempt',
            ]
        )
            ->assertStatus(409)
            ->assertJsonPath(
                'error.code',
                'admin_curriculum_authoring_disabled'
            );
    }

    private function activeAssignment(): array
    {
        $admin = $this->admin();

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $subjectId = DB::table('subjects')
            ->where('code', 'mathematics')
            ->value('id');

        $this->assertNotNull($subjectId);

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: (string) $subjectId,
            operationId: (string) Str::uuid(),
            reason: 'Owned curriculum test assignment',
        );

        return [$teacher, $assignment];
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}

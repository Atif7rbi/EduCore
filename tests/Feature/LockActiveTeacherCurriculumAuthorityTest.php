<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Application\TeacherAuthoring\FilterActiveTeacherCurriculumRead;
use App\Application\TeacherAuthoring\LockActiveTeacherCurriculumAuthority;
use App\Application\TeacherAuthoring\LockedTeacherCurriculumAuthority;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesHistoricalOwnerlessCurriculumFixtures;
use Tests\TestCase;

class LockActiveTeacherCurriculumAuthorityTest extends TestCase
{
    use CreatesHistoricalOwnerlessCurriculumFixtures;
    use RefreshDatabase;

    public function test_active_teacher_has_locked_authority_over_exact_owned_curriculum(): void
    {
        [$teacher, $assignment, $curriculum] =
            $this->authorityFixture();

        $authority = $this->authorize(
            $teacher->id,
            $assignment->id,
            $curriculum->id,
        );

        $this->assertInstanceOf(
            LockedTeacherCurriculumAuthority::class,
            $authority,
        );
        $this->assertSame($teacher->id, $authority->teacherUserId);
        $this->assertSame($assignment->subject_id, $authority->subjectId);
        $this->assertSame(
            $assignment->id,
            $authority->teacherSubjectAssignmentId,
        );
        $this->assertSame($curriculum->id, $authority->curriculumId);
    }

    public function test_foreign_teacher_is_rejected(): void
    {
        [, $assignment, $curriculum] = $this->authorityFixture();

        $otherTeacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->expectAuthorityNotFound(
            $otherTeacher->id,
            $assignment->id,
            $curriculum->id,
        );
    }

    public function test_disabled_teacher_is_rejected(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();

        DB::table('users')
            ->where('id', $teacher->id)
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);

        $this->expectAuthorityNotFound(
            $teacher->id,
            $assignment->id,
            $curriculum->id,
        );
    }

    public function test_inactive_assignment_is_rejected(): void
    {
        [$teacher, $assignment, $curriculum, $admin] =
            $this->authorityFixture();

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $admin->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'H1 inactive assignment authority test.',
        );

        $this->expectAuthorityNotFound(
            $teacher->id,
            $assignment->id,
            $curriculum->id,
        );
    }

    public function test_foreign_assignment_is_rejected(): void
    {
        [$teacher] =
            $this->authorityFixture();

        [, $foreignAssignment, $foreignCurriculum] =
            $this->authorityFixture();

        $this->expectAuthorityNotFound(
            $teacher->id,
            $foreignAssignment->id,
            $foreignCurriculum->id,
        );
    }

    public function test_legacy_ownerless_curriculum_is_rejected(): void
    {
        [$teacher, $assignment] = $this->authorityFixture();

        $curriculum =
            $this->createHistoricalOwnerlessCurriculumFixture(
                'H1 ownerless authority fixture',
            );

        $this->expectAuthorityNotFound(
            $teacher->id,
            $assignment->id,
            $curriculum->id,
        );
    }

    public function test_assignment_curriculum_subject_and_ownership_mismatch_is_rejected(): void
    {
        [$teacher, $mathematicsAssignment, , $admin] =
            $this->authorityFixture();

        $physicsId = $this->canonicalSubjectId('physics');

        $physicsAssignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $physicsId,
            operationId: (string) Str::uuid(),
            reason: 'H1 mismatched assignment fixture.',
        );

        $physicsCurriculum = app(
            CreateOwnedCurriculum::class
        )->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $physicsAssignment->id,
            name: 'H1 mismatched Curriculum fixture',
            educationStageId: null,
        );

        $this->expectAuthorityNotFound(
            $teacher->id,
            $mathematicsAssignment->id,
            $physicsCurriculum->id,
        );
    }

    public function test_noncanonical_subject_is_rejected(): void
    {
        [$teacher, $assignment, $curriculum] =
            $this->authorityFixture();

        DB::statement(
            'ALTER TABLE subjects DISABLE TRIGGER '
            .'trg_subjects_catalog_immutability'
        );

        try {
            DB::table('subjects')
                ->where('id', $assignment->subject_id)
                ->update(['code' => null]);
        } finally {
            DB::statement(
                'ALTER TABLE subjects ENABLE TRIGGER '
                .'trg_subjects_catalog_immutability'
            );
        }

        $this->expectAuthorityNotFound(
            $teacher->id,
            $assignment->id,
            $curriculum->id,
        );
    }

    public function test_inactive_subject_is_rejected(): void
    {
        [$teacher, $assignment, $curriculum] =
            $this->authorityFixture();

        DB::table('subjects')
            ->where('id', $assignment->subject_id)
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        $this->expectAuthorityNotFound(
            $teacher->id,
            $assignment->id,
            $curriculum->id,
        );
    }

    public function test_teacher_read_filter_includes_only_current_exact_authority(): void
    {
        [$teacher, , $curriculum] = $this->authorityFixture();

        $versionId = (string) Str::uuid();

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'H1 current authority',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $filter = app(FilterActiveTeacherCurriculumRead::class);

        $this->assertSame(
            [$curriculum->id],
            $filter->curricula(
                Curriculum::query(),
                $teacher->id,
            )->pluck('id')->all(),
        );

        $this->assertSame(
            [$versionId],
            $filter->versions(
                CurriculumVersion::query(),
                $teacher->id,
            )->pluck('id')->all(),
        );
    }

    public function test_teacher_read_filter_excludes_foreign_teacher_content(): void
    {
        [, , $curriculum] = $this->authorityFixture();

        $otherTeacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->assertFilterExcludes(
            $otherTeacher->id,
            $curriculum->id,
        );
    }

    public function test_teacher_read_filter_excludes_inactive_assignment_content(): void
    {
        [$teacher, $assignment, $curriculum, $admin] =
            $this->authorityFixture();

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $admin->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'H1 inactive assignment filter test.',
        );

        $this->assertFilterExcludes(
            $teacher->id,
            $curriculum->id,
        );
    }

    public function test_teacher_read_filter_excludes_disabled_teacher_content(): void
    {
        [$teacher, , $curriculum] = $this->authorityFixture();

        DB::table('users')
            ->where('id', $teacher->id)
            ->update(['status' => 'disabled']);

        $this->assertFilterExcludes(
            $teacher->id,
            $curriculum->id,
        );
    }

    public function test_teacher_read_filter_excludes_ownerless_content(): void
    {
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $curriculum =
            $this->createHistoricalOwnerlessCurriculumFixture(
                'H1 ownerless filter fixture',
            );

        $this->assertFilterExcludes(
            $teacher->id,
            $curriculum->id,
        );
    }

    public function test_teacher_read_filter_requires_exact_assignment_and_subject_identity(): void
    {
        $sql = app(FilterActiveTeacherCurriculumRead::class)
            ->curricula(
                Curriculum::query(),
                (string) Str::uuid(),
            )
            ->toSql();

        $this->assertStringContainsString(
            '"teacher_subject_assignments"."id" = '
            .'"curricula"."teacher_subject_assignment_id"',
            $sql,
        );

        $this->assertStringContainsString(
            '"teacher_subject_assignments"."subject_id" = '
            .'"curricula"."subject_id"',
            $sql,
        );
    }

    public function test_teacher_read_filter_excludes_noncanonical_subject(): void
    {
        [$teacher, $assignment, $curriculum] =
            $this->authorityFixture();

        DB::statement(
            'ALTER TABLE subjects DISABLE TRIGGER '
            .'trg_subjects_catalog_immutability'
        );

        try {
            DB::table('subjects')
                ->where('id', $assignment->subject_id)
                ->update(['code' => null]);
        } finally {
            DB::statement(
                'ALTER TABLE subjects ENABLE TRIGGER '
                .'trg_subjects_catalog_immutability'
            );
        }

        $this->assertFilterExcludes(
            $teacher->id,
            $curriculum->id,
        );
    }

    public function test_teacher_read_filter_excludes_inactive_subject(): void
    {
        [$teacher, $assignment, $curriculum] =
            $this->authorityFixture();

        DB::table('subjects')
            ->where('id', $assignment->subject_id)
            ->update(['status' => 'inactive']);

        $this->assertFilterExcludes(
            $teacher->id,
            $curriculum->id,
        );
    }

    private function authorityFixture(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $assignment = app(
            AssignTeacherSubject::class
        )->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $this->canonicalSubjectId(),
            operationId: (string) Str::uuid(),
            reason: 'H1 Teacher authority fixture.',
        );

        $curriculum = app(
            CreateOwnedCurriculum::class
        )->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'H1 Teacher authority Curriculum',
            educationStageId: null,
        );

        return [$teacher, $assignment, $curriculum, $admin];
    }

    private function authorize(
        string $actorUserId,
        string $teacherSubjectAssignmentId,
        string $curriculumId,
    ): LockedTeacherCurriculumAuthority {
        return DB::transaction(
            fn (): LockedTeacherCurriculumAuthority => app(
                LockActiveTeacherCurriculumAuthority::class
            )->execute(
                $actorUserId,
                $teacherSubjectAssignmentId,
                $curriculumId,
            )
        );
    }

    private function expectAuthorityNotFound(
        string $actorUserId,
        string $teacherSubjectAssignmentId,
        string $curriculumId,
    ): void {
        try {
            $this->authorize(
                $actorUserId,
                $teacherSubjectAssignmentId,
                $curriculumId,
            );
            $this->fail('Expected Teacher authority to fail closed.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertFilterExcludes(
        string $actorUserId,
        string $curriculumId,
    ): void {
        $ids = app(FilterActiveTeacherCurriculumRead::class)
            ->curricula(Curriculum::query(), $actorUserId)
            ->pluck('id')
            ->all();

        $this->assertNotContains($curriculumId, $ids);
    }
}

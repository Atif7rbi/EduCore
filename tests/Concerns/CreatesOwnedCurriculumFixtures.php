<?php

namespace Tests\Concerns;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\Curriculum;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

trait CreatesOwnedCurriculumFixtures
{
    protected function canonicalSubjectId(
        string $code = 'mathematics',
    ): string {
        $subjectId = DB::table('subjects')
            ->where('code', $code)
            ->where('status', 'active')
            ->value('id');

        if (! is_string($subjectId) || $subjectId === '') {
            throw new RuntimeException(
                "Expected active canonical Subject: {$code}."
            );
        }

        return $subjectId;
    }

    protected function createOwnedCurriculumFixture(
        string $name,
        string $subjectCode = 'mathematics',
        ?string $educationStageId = null,
    ): Curriculum {
        $subjectId =
            $this->canonicalSubjectId($subjectCode);

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
            subjectId: $subjectId,
            operationId: (string) Str::uuid(),
            reason: 'Owned Curriculum test fixture',
        );

        return app(
            CreateOwnedCurriculum::class
        )->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: $name,
            educationStageId: $educationStageId,
        );
    }
}

<?php

namespace Tests\Feature;

use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\EducationStage;
use App\Models\Skill;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherWorkspaceReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_sees_own_active_and_inactive_assignment_metadata_only(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $otherTeacher = $this->user('teacher');

        $active = $this->assign(
            $admin,
            $teacher,
            'mathematics',
        );

        $inactive = $this->assign(
            $admin,
            $teacher,
            'physics',
        );

        $foreign = $this->assign(
            $admin,
            $otherTeacher,
            'chemistry',
        );

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $admin->id,
            assignmentId: $inactive->id,
            operationId: (string) Str::uuid(),
            reason: 'H1 inactive metadata visibility test.',
        );

        $response = $this->actingAs($teacher)
            ->getJson('/api/teacher/subject-assignments')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $data = collect($response->json('data'));

        $this->assertSame(
            [$active->id, $inactive->id],
            $data->pluck('id')->all(),
        );
        $this->assertSame(
            ['active', 'inactive'],
            $data->pluck('status')->all(),
        );
        $this->assertNotContains(
            $foreign->id,
            $data->pluck('id')->all(),
        );
        $this->assertSame(
            [$teacher->id, $teacher->id],
            $data->pluck('teacher_user_id')->all(),
        );
    }

    public function test_teacher_reads_only_active_education_stage_references(): void
    {
        $teacher = $this->user('teacher');

        $inactive = EducationStage::query()
            ->where('code', 'middle')
            ->firstOrFail();

        DB::table('education_stages')
            ->where('id', $inactive->id)
            ->update(['status' => 'inactive']);

        $expected = EducationStage::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $response = $this->actingAs($teacher)
            ->getJson('/api/teacher/education-stages')
            ->assertOk();

        $this->assertSame(
            $expected,
            collect($response->json('data'))->pluck('id')->all(),
        );
        $this->assertNotContains(
            $inactive->id,
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_teacher_reads_skill_references_in_stable_order(): void
    {
        $teacher = $this->user('teacher');

        $geometry = Skill::query()->create([
            'name' => 'Geometry',
            'description' => 'Geometry reference',
        ]);

        $algebra = Skill::query()->create([
            'name' => 'Algebra',
            'description' => 'Algebra reference',
        ]);

        $response = $this->actingAs($teacher)
            ->getJson('/api/teacher/skills')
            ->assertOk();

        $this->assertSame(
            [$algebra->id, $geometry->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_teacher_reference_and_assignment_resources_are_read_only(): void
    {
        $teacher = $this->user('teacher');

        foreach ([
            '/api/teacher/subject-assignments',
            '/api/teacher/education-stages',
            '/api/teacher/skills',
        ] as $uri) {
            $this->actingAs($teacher)
                ->postJson($uri, [])
                ->assertStatus(405);

            $this->actingAs($teacher)
                ->putJson($uri, [])
                ->assertStatus(405);

            $this->actingAs($teacher)
                ->patchJson($uri, [])
                ->assertStatus(405);

            $this->actingAs($teacher)
                ->deleteJson($uri)
                ->assertStatus(405);
        }
    }

    public function test_teacher_reads_preserve_authentication_and_role_boundaries(): void
    {
        $student = $this->user('student');
        $admin = $this->user('admin');

        foreach ([
            '/api/teacher/subject-assignments',
            '/api/teacher/education-stages',
            '/api/teacher/skills',
        ] as $uri) {
            $this->app['auth']->forgetGuards();

            $this->getJson($uri)
                ->assertStatus(401)
                ->assertJsonPath('error.code', 'unauthenticated');

            $this->actingAs($student)
                ->getJson($uri)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'teacher_forbidden');

            $this->actingAs($admin)
                ->getJson($uri)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'teacher_forbidden');
        }
    }

    public function test_disabled_teacher_cannot_read_teacher_workspace(): void
    {
        $teacher = $this->user('teacher', 'disabled');

        foreach ([
            '/api/teacher/subject-assignments',
            '/api/teacher/education-stages',
            '/api/teacher/skills',
        ] as $uri) {
            $this->actingAs($teacher)
                ->getJson($uri)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'account_disabled');
        }
    }

    private function assign(
        User $admin,
        User $teacher,
        string $subjectCode,
    ): TeacherSubjectAssignment {
        $subjectId = (string) DB::table(
            'subjects'
        )
            ->where('code', $subjectCode)
            ->value('id');

        return app(AssignTeacherSubject::class)->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $subjectId,
            operationId: (string) Str::uuid(),
            reason: 'H1 Teacher workspace assignment fixture.',
        );
    }

    private function user(
        string $role,
        string $status = 'active',
    ): User {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
        ]);
    }
}

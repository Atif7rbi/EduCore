<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Curriculum\EvaluateCurriculumVersionReadiness;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationStage;
use App\Models\Skill;
use App\Models\SkillVersionPlacement;
use App\Models\TeacherSubjectAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesHistoricalOwnerlessCurriculumFixtures;
use Tests\TestCase;

class TeacherCurriculumAuthoringApiTest extends TestCase
{
    use CreatesHistoricalOwnerlessCurriculumFixtures;
    use RefreshDatabase;

    public function test_active_teacher_creates_and_updates_curriculum_through_own_assignment(): void
    {
        [$teacher, $assignment] = $this->authorityFixture();
        $stage = EducationStage::query()->where('code', 'secondary')->firstOrFail();

        $response = $this->actingAs($teacher)->postJson(
            $this->assignmentUri($assignment).'/curricula',
            [
                'name' => 'Teacher H2 Curriculum',
                'education_stage_id' => $stage->id,
            ],
        )->assertCreated();

        $curriculumId = $response->json('data.id');

        $response
            ->assertJsonPath('data.subject_id', $assignment->subject_id)
            ->assertJsonPath('data.teacher_subject_assignment_id', $assignment->id)
            ->assertJsonPath('data.education_stage_id', $stage->id);

        $this->actingAs($teacher)->putJson(
            $this->curriculumUri($assignment, $curriculumId),
            ['name' => 'Teacher H2 Curriculum Updated'],
        )
            ->assertOk()
            ->assertJsonPath('data.name', 'Teacher H2 Curriculum Updated');
    }

    public function test_teacher_cannot_mutate_curriculum_ownership_provenance(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();

        $this->actingAs($teacher)->putJson(
            $this->curriculumUri($assignment, $curriculum),
            [
                'name' => 'Provenance attempt',
                'subject_id' => (string) Str::uuid(),
                'teacher_subject_assignment_id' => (string) Str::uuid(),
            ],
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $curriculum->refresh();

        $this->assertSame($assignment->id, $curriculum->teacher_subject_assignment_id);
        $this->assertSame($assignment->subject_id, $curriculum->subject_id);
    }

    public function test_curriculum_creation_rejects_foreign_and_inactive_assignments(): void
    {
        [, $assignment] = $this->authorityFixture();
        $otherTeacher = $this->user('teacher');

        $this->actingAs($otherTeacher)->postJson(
            $this->assignmentUri($assignment).'/curricula',
            ['name' => 'Foreign assignment'],
        )->assertNotFound();

        [$teacher, $inactiveAssignment, , $admin] = $this->authorityFixture('physics');

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $admin->id,
            assignmentId: $inactiveAssignment->id,
            operationId: (string) Str::uuid(),
            reason: 'H2 inactive assignment test.',
        );

        $this->actingAs($teacher)->postJson(
            $this->assignmentUri($inactiveAssignment).'/curricula',
            ['name' => 'Inactive assignment'],
        )->assertNotFound();
    }

    public function test_curriculum_creation_rejects_disabled_teacher_and_inactive_stage(): void
    {
        [$teacher, $assignment] = $this->authorityFixture();
        $teacher->update(['status' => 'disabled']);

        $this->actingAs($teacher)->postJson(
            $this->assignmentUri($assignment).'/curricula',
            ['name' => 'Disabled teacher'],
        )->assertStatus(403);

        [$activeTeacher, $activeAssignment] = $this->authorityFixture('physics');
        $stage = EducationStage::query()->where('code', 'middle')->firstOrFail();
        DB::table('education_stages')->where('id', $stage->id)->update(['status' => 'inactive']);

        $this->actingAs($activeTeacher)->postJson(
            $this->assignmentUri($activeAssignment).'/curricula',
            ['name' => 'Inactive stage', 'education_stage_id' => $stage->id],
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_curriculum_creation_rejects_noncanonical_and_inactive_subjects(): void
    {
        [$teacher, $assignment] = $this->authorityFixture();

        DB::statement('ALTER TABLE subjects DISABLE TRIGGER trg_subjects_catalog_immutability');

        try {
            DB::table('subjects')->where('id', $assignment->subject_id)->update(['code' => null]);
        } finally {
            DB::statement('ALTER TABLE subjects ENABLE TRIGGER trg_subjects_catalog_immutability');
        }

        $this->actingAs($teacher)->postJson(
            $this->assignmentUri($assignment).'/curricula',
            ['name' => 'Noncanonical subject'],
        )->assertNotFound();

        [$otherTeacher, $otherAssignment] = $this->authorityFixture('physics');
        DB::table('subjects')->where('id', $otherAssignment->subject_id)->update(['status' => 'inactive']);

        $this->actingAs($otherTeacher)->postJson(
            $this->assignmentUri($otherAssignment).'/curricula',
            ['name' => 'Inactive subject'],
        )->assertNotFound();
    }

    public function test_foreign_and_ownerless_curriculum_updates_are_hidden(): void
    {
        [$teacher, $assignment] = $this->authorityFixture();
        [, , $foreignCurriculum] = $this->authorityFixture('physics');
        $ownerless = $this->createHistoricalOwnerlessCurriculumFixture('H2 ownerless');

        foreach ([$foreignCurriculum, $ownerless] as $curriculum) {
            $this->actingAs($teacher)->putJson(
                $this->curriculumUri($assignment, $curriculum),
                ['name' => 'Hidden update'],
            )->assertNotFound();
        }
    }

    public function test_teacher_creates_and_updates_draft_version_only_under_authorized_curriculum(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();

        $response = $this->actingAs($teacher)->postJson(
            $this->curriculumUri($assignment, $curriculum).'/versions',
            ['version_number' => 1, 'label' => 'H2 Draft'],
        )->assertCreated();

        $versionId = $response->json('data.id');

        $this->actingAs($teacher)->putJson(
            $this->versionUri($assignment, $curriculum, $versionId),
            ['version_number' => 2, 'label' => 'H2 Draft Revised'],
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version_number', 2);

        [, , $foreignCurriculum] = $this->authorityFixture('physics');

        $this->actingAs($teacher)->postJson(
            $this->curriculumUri($assignment, $foreignCurriculum).'/versions',
            ['version_number' => 1, 'label' => 'Foreign'],
        )->assertNotFound();
    }

    public function test_version_creation_revalidates_assignment_and_teacher_authority(): void
    {
        [$teacher, $assignment, $curriculum, $admin] = $this->authorityFixture();

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $admin->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'H2 version inactive assignment test.',
        );

        $this->actingAs($teacher)->postJson(
            $this->curriculumUri($assignment, $curriculum).'/versions',
            ['version_number' => 1, 'label' => 'Blocked inactive'],
        )->assertNotFound();

        [$disabledTeacher, $activeAssignment, $ownedCurriculum] =
            $this->authorityFixture('physics');
        $disabledTeacher->update(['status' => 'disabled']);

        $this->actingAs($disabledTeacher)->postJson(
            $this->curriculumUri($activeAssignment, $ownedCurriculum).'/versions',
            ['version_number' => 1, 'label' => 'Blocked disabled'],
        )->assertStatus(403);
    }

    public function test_version_creation_rejects_ownerless_curriculum(): void
    {
        [$teacher, $assignment] = $this->authorityFixture();
        $ownerless = $this->createHistoricalOwnerlessCurriculumFixture('H2 ownerless version');

        $this->actingAs($teacher)->postJson(
            $this->curriculumUri($assignment, $ownerless).'/versions',
            ['version_number' => 1, 'label' => 'Ownerless blocked'],
        )->assertNotFound();
    }

    public function test_published_and_retired_versions_reject_metadata_and_structural_updates(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();

        foreach (['published', 'retired'] as $status) {
            $version = $this->version($curriculum, $status, $status === 'published' ? 1 : 2);
            $base = $this->versionUri($assignment, $curriculum, $version);

            $this->actingAs($teacher)->putJson(
                $base,
                ['version_number' => 9, 'label' => 'Blocked'],
            )->assertStatus(409);

            $this->actingAs($teacher)->postJson(
                $base.'/topics',
                ['name' => 'Blocked topic'],
            )->assertStatus(409);

            $this->actingAs($teacher)->postJson(
                $base.'/skill-placements',
                ['skill_id' => $this->skill()->id],
            )->assertStatus(409);
        }
    }

    public function test_version_lifecycle_allows_forward_transitions_and_rejects_reverse_transition(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();
        $version = $this->version($curriculum, 'draft');

        $this->app->instance(
            EvaluateCurriculumVersionReadiness::class,
            new class extends EvaluateCurriculumVersionReadiness
            {
                public function evaluate(CurriculumVersion $version): array
                {
                    return ['ready_to_publish' => true, 'blockers' => []];
                }
            },
        );

        $base = $this->versionUri($assignment, $curriculum, $version);

        $this->actingAs($teacher)->postJson($base.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->actingAs($teacher)->postJson($base.'/retire')
            ->assertOk()
            ->assertJsonPath('data.status', 'retired');

        $this->actingAs($teacher)->postJson($base.'/publish')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'integrity_conflict');

        $draft = $this->version($curriculum, 'draft', 2);

        $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $curriculum, $draft).'/retire',
        )->assertStatus(409);
    }

    public function test_topic_mutation_is_draft_only_and_exactly_version_bound(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();
        $draft = $this->version($curriculum, 'draft');
        $other = $this->version($curriculum, 'draft', 2);

        $response = $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $curriculum, $draft).'/topics',
            ['name' => 'H2 Topic', 'display_order' => 3],
        )->assertCreated();

        $topicId = $response->json('data.id');

        $this->actingAs($teacher)->putJson(
            $this->versionUri($assignment, $curriculum, $draft).'/topics/'.$topicId,
            ['name' => 'H2 Topic Updated', 'display_order' => 4],
        )->assertOk();

        $this->actingAs($teacher)->putJson(
            $this->versionUri($assignment, $curriculum, $other).'/topics/'.$topicId,
            ['name' => 'Cross version', 'display_order' => 0],
        )->assertNotFound();
    }

    public function test_foreign_and_ownerless_taxonomy_access_is_hidden(): void
    {
        [$teacher, $assignment] = $this->authorityFixture();
        [, , $foreign] = $this->authorityFixture('physics');
        $foreignVersion = $this->version($foreign, 'draft');

        $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $foreign, $foreignVersion).'/topics',
            ['name' => 'Foreign topic'],
        )->assertNotFound();

        $ownerless = $this->createHistoricalOwnerlessCurriculumFixture('Ownerless H2 taxonomy');
        $ownerlessVersion = $this->version($ownerless, 'draft');

        $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $ownerless, $ownerlessVersion).'/topics',
            ['name' => 'Ownerless topic'],
        )->assertNotFound();
    }

    public function test_skill_placement_and_home_topic_are_exactly_version_bound(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();
        $draft = $this->version($curriculum, 'draft');
        $other = $this->version($curriculum, 'draft', 2);
        $skill = $this->skill();
        $topic = Topic::query()->create([
            'curriculum_version_id' => $draft->id,
            'name' => 'Home Topic',
            'display_order' => 0,
        ]);

        $response = $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $curriculum, $draft).'/skill-placements',
            ['skill_id' => $skill->id],
        )->assertCreated();

        $placementId = $response->json('data.id');

        $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $curriculum, $draft)
                .'/skill-placements/'.$placementId.'/home-topics',
            ['topic_id' => $topic->id],
        )->assertCreated();

        $this->actingAs($teacher)->deleteJson(
            $this->versionUri($assignment, $curriculum, $other)
                .'/skill-placements/'.$placementId,
        )->assertNotFound();

        $otherTopic = Topic::query()->create([
            'curriculum_version_id' => $other->id,
            'name' => 'Cross Topic',
            'display_order' => 0,
        ]);

        $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $curriculum, $draft)
                .'/skill-placements/'.$placementId.'/home-topics',
            ['topic_id' => $otherTopic->id],
        )->assertNotFound();
    }

    public function test_home_topic_hides_nonexistent_and_foreign_topics_while_allowing_same_version_topic(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();
        $draft = $this->version($curriculum, 'draft');
        $other = $this->version($curriculum, 'draft', 2);
        $base = $this->versionUri($assignment, $curriculum, $draft);

        $placement = $this->actingAs($teacher)->postJson(
            $base.'/skill-placements',
            ['skill_id' => $this->skill()->id],
        )->assertCreated();

        $homeTopicsUri = $base.'/skill-placements/'.$placement->json('data.id').'/home-topics';

        $this->actingAs($teacher)->postJson(
            $homeTopicsUri,
            ['topic_id' => (string) Str::uuid()],
        )->assertNotFound();

        $foreignTopic = Topic::query()->create([
            'curriculum_version_id' => $other->id,
            'name' => 'Foreign home topic',
            'display_order' => 0,
        ]);

        $this->actingAs($teacher)->postJson(
            $homeTopicsUri,
            ['topic_id' => $foreignTopic->id],
        )->assertNotFound();

        $sameVersionTopic = Topic::query()->create([
            'curriculum_version_id' => $draft->id,
            'name' => 'Same-version home topic',
            'display_order' => 0,
        ]);

        $this->actingAs($teacher)->postJson(
            $homeTopicsUri,
            ['topic_id' => $sameVersionTopic->id],
        )
            ->assertCreated()
            ->assertJsonPath('data.topic_id', $sameVersionTopic->id);
    }

    public function test_placement_delete_locks_and_removes_multiple_home_topics(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();
        $draft = $this->version($curriculum, 'draft');
        $base = $this->versionUri($assignment, $curriculum, $draft);

        $placement = $this->actingAs($teacher)->postJson(
            $base.'/skill-placements',
            ['skill_id' => $this->skill()->id],
        )->assertCreated();

        $placementId = $placement->json('data.id');

        foreach (['Second', 'First'] as $name) {
            $topic = Topic::query()->create([
                'curriculum_version_id' => $draft->id,
                'name' => $name,
                'display_order' => 0,
            ]);

            $this->actingAs($teacher)->postJson(
                $base.'/skill-placements/'.$placementId.'/home-topics',
                ['topic_id' => $topic->id],
            )->assertCreated();
        }

        $this->actingAs($teacher)->deleteJson(
            $base.'/skill-placements/'.$placementId,
        )->assertOk();

        $this->assertDatabaseMissing('skill_version_placements', ['id' => $placementId]);
        $this->assertDatabaseMissing('skill_home_topics', ['placement_id' => $placementId]);
    }

    public function test_home_topic_is_draft_only_and_missing_skill_is_rejected(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();

        foreach (['published', 'retired'] as $status) {
            $number = $status === 'published' ? 1 : 2;
            $version = $this->version($curriculum, 'draft', $number);
            $topic = Topic::query()->create([
                'curriculum_version_id' => $version->id,
                'name' => $status.' topic',
                'display_order' => 0,
            ]);
            $placement = SkillVersionPlacement::query()->create([
                'skill_id' => $this->skill()->id,
                'curriculum_version_id' => $version->id,
            ]);

            DB::table('curriculum_versions')
                ->where('id', $version->id)
                ->update(['status' => 'published']);

            if ($status === 'retired') {
                DB::table('curriculum_versions')
                    ->where('id', $version->id)
                    ->update(['status' => 'retired']);
            }

            $this->actingAs($teacher)->postJson(
                $this->versionUri($assignment, $curriculum, $version)
                    .'/skill-placements/'.$placement->id.'/home-topics',
                ['topic_id' => $topic->id],
            )->assertStatus(409);
        }

        $draft = $this->version($curriculum, 'draft', 3);

        $this->actingAs($teacher)->postJson(
            $this->versionUri($assignment, $curriculum, $draft).'/skill-placements',
            ['skill_id' => (string) Str::uuid()],
        )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_teacher_reads_only_current_authorized_curricula_versions_and_taxonomy(): void
    {
        [$teacher, $assignment, $curriculum] = $this->authorityFixture();
        $version = $this->version($curriculum, 'draft');

        $this->actingAs($teacher)->getJson($this->assignmentUri($assignment).'/curricula')
            ->assertOk()
            ->assertJsonFragment(['id' => $curriculum->id]);

        $this->actingAs($teacher)->getJson($this->curriculumUri($assignment, $curriculum).'/versions')
            ->assertOk()
            ->assertJsonFragment(['id' => $version->id]);

        $this->actingAs($teacher)->getJson($this->versionUri($assignment, $curriculum, $version).'/topics')
            ->assertOk();
    }

    public function test_student_and_admin_cannot_use_teacher_authoring_routes(): void
    {
        $uri = '/api/teacher/subject-assignments/'.Str::uuid().'/curricula';

        foreach ([$this->user('student'), $this->user('admin')] as $actor) {
            $this->actingAs($actor)->postJson($uri, ['name' => 'Forbidden'])
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'teacher_forbidden');
        }
    }

    private function authorityFixture(string $subjectCode = 'mathematics'): array
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $subjectId = $this->canonicalSubjectId($subjectCode);

        $assignment = app(AssignTeacherSubject::class)->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $subjectId,
            operationId: (string) Str::uuid(),
            reason: 'H2 Teacher authoring fixture.',
        );

        $curriculum = app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'H2 Curriculum '.Str::random(8),
            educationStageId: null,
        );

        return [$teacher, $assignment, $curriculum, $admin];
    }

    private function version(
        Curriculum $curriculum,
        string $status,
        int $number = 1,
    ): CurriculumVersion {
        return CurriculumVersion::query()->create([
            'curriculum_id' => $curriculum->id,
            'version_number' => $number,
            'label' => 'H2 Version '.$number,
            'status' => $status,
        ]);
    }

    private function skill(): Skill
    {
        return Skill::query()->create([
            'name' => 'H2 Skill '.Str::random(8),
            'description' => null,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function assignmentUri(TeacherSubjectAssignment $assignment): string
    {
        return '/api/teacher/subject-assignments/'.$assignment->id;
    }

    private function curriculumUri(
        TeacherSubjectAssignment $assignment,
        Curriculum|string $curriculum,
    ): string {
        $id = $curriculum instanceof Curriculum ? $curriculum->id : $curriculum;

        return $this->assignmentUri($assignment).'/curricula/'.$id;
    }

    private function versionUri(
        TeacherSubjectAssignment $assignment,
        Curriculum $curriculum,
        CurriculumVersion|string $version,
    ): string {
        $id = $version instanceof CurriculumVersion ? $version->id : $version;

        return $this->curriculumUri($assignment, $curriculum).'/versions/'.$id;
    }
}

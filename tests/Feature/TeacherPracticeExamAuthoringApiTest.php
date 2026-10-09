<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\Skill;
use App\Models\SkillVersionPlacement;
use App\Models\TeacherSubjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\TestCase;

class TeacherPracticeExamAuthoringApiTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use RefreshDatabase;

    public function test_teacher_authors_practice_with_released_exact_version_revision(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $revision = $this->revision($version, true);
        $practice = $this->actingAs($teacher)->postJson($base.'/practice-activities', ['name' => 'Practice'])->assertCreated();
        $practiceId = $practice->json('data.id');
        $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/items', ['assessment_item_revision_ids' => [$revision->id], 'display_order' => 0])->assertCreated();
        $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
        DB::table('curriculum_versions')->where('id', $version->id)->update(['status' => 'published']);
        $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/items', ['assessment_item_revision_ids' => [$revision->id], 'display_order' => 1])->assertStatus(409);
    }

    public function test_teacher_removes_one_member_from_an_active_practice_with_multiple_members(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $first = $this->revision($version, true);
        $second = $this->revision($version, true);
        $practiceId = $this->createPractice($teacher, $base);
        $firstMembership = $this->addPracticeItem($teacher, $base, $practiceId, $first->id, 0);
        $secondMembership = $this->addPracticeItem($teacher, $base, $practiceId, $second->id, 1);

        $this->actingAs($teacher)
            ->postJson($base.'/practice-activities/'.$practiceId.'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($teacher)
            ->deleteJson($base.'/practice-activities/'.$practiceId.'/items/'.$firstMembership)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('practice_activity_items', ['id' => $firstMembership]);
        $this->assertDatabaseHas('practice_activity_items', ['id' => $secondMembership]);
        $this->assertSame(1, DB::table('practice_activity_items')
            ->where('practice_activity_id', $practiceId)
            ->count());
        $this->assertSame('active', DB::table('practice_activities')
            ->where('id', $practiceId)
            ->value('status'));
    }

    public function test_teacher_last_member_removal_returns_conflict(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $revision = $this->revision($version, true);
        $practiceId = $this->createPractice($teacher, $base);
        $membershipId = $this->addPracticeItem($teacher, $base, $practiceId, $revision->id, 0);

        $this->actingAs($teacher)
            ->postJson($base.'/practice-activities/'.$practiceId.'/activate')
            ->assertOk();

        $this->actingAs($teacher)
            ->deleteJson($base.'/practice-activities/'.$practiceId.'/items/'.$membershipId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'practice_activity_requires_item');
    }

    public function test_teacher_last_member_rejection_leaves_practice_and_membership_unchanged(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $revision = $this->revision($version, true);
        $practiceId = $this->createPractice($teacher, $base);
        $membershipId = $this->addPracticeItem($teacher, $base, $practiceId, $revision->id, 0);

        $this->actingAs($teacher)
            ->postJson($base.'/practice-activities/'.$practiceId.'/activate')
            ->assertOk();

        $this->actingAs($teacher)
            ->deleteJson($base.'/practice-activities/'.$practiceId.'/items/'.$membershipId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'practice_activity_requires_item');

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $membershipId,
            'practice_activity_id' => $practiceId,
            'curriculum_version_id' => $version->id,
        ]);
        $this->assertSame(1, DB::table('practice_activity_items')
            ->where('practice_activity_id', $practiceId)
            ->count());
        $this->assertSame('active', DB::table('practice_activities')
            ->where('id', $practiceId)
            ->value('status'));
    }

    public function test_other_teacher_cannot_remove_owned_practice_membership(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $revision = $this->revision($version, true);
        $practiceId = $this->createPractice($teacher, $base);
        $membershipId = $this->addPracticeItem($teacher, $base, $practiceId, $revision->id, 0);
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);

        $this->actingAs($otherTeacher)
            ->deleteJson($base.'/practice-activities/'.$practiceId.'/items/'.$membershipId)
            ->assertNotFound();

        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $membershipId,
            'practice_activity_id' => $practiceId,
            'curriculum_version_id' => $version->id,
        ]);
    }

    public function test_teacher_template_lifecycle_and_generation_time_revision_selection(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $revisionA = $this->revision($version, true);
        $revisionB = $this->revision($version, true);
        $template = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'Exam'])->assertCreated();
        $templateId = $template->json('data.id');
        $templateVersion = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', ['version_number' => 1, 'label' => 'v1', 'rules_payload' => [], 'rules_schema_version' => 1])->assertCreated();
        $templateVersionId = $templateVersion->json('data.id');
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/publish')->assertOk();
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/generations', ['generator_version' => 'h4', 'seed' => 'draft', 'assessment_item_revision_ids' => [$revisionA->id]])->assertCreated();
        DB::table('curriculum_versions')->where('id', $version->id)->update(['status' => 'published']);
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/generations', ['generator_version' => 'h4', 'seed' => 'published', 'assessment_item_revision_ids' => [$revisionB->id]])->assertCreated();
        DB::table('curriculum_versions')->where('id', $version->id)->update(['status' => 'retired']);
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/generations', ['generator_version' => 'h4', 'seed' => 'retired', 'assessment_item_revision_ids' => [$revisionB->id]])->assertStatus(409);
    }

    public function test_student_and_admin_cannot_use_teacher_h4_mutation_routes(): void
    {
        $uri = '/api/teacher/subject-assignments/'.Str::uuid().'/curricula/'.Str::uuid().'/versions/'.Str::uuid().'/practice-activities';
        foreach (['student', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'active']))->postJson($uri, ['name' => 'x'])->assertStatus(403);
        }
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $assignment = app(AssignTeacherSubject::class)->execute(actorUserId: $admin->id, teacherUserId: $teacher->id, subjectId: $this->canonicalSubjectId('mathematics'), operationId: (string) Str::uuid(), reason: 'H4 fixture');
        $curriculum = app(CreateOwnedCurriculum::class)->execute(actorUserId: $teacher->id, teacherSubjectAssignmentId: $assignment->id, name: 'H4 '.Str::random(6), educationStageId: null);
        $version = CurriculumVersion::query()->create(['curriculum_id' => $curriculum->id, 'version_number' => 1, 'label' => 'H4', 'status' => 'draft']);

        return [$teacher, $assignment, $curriculum, $version];
    }

    private function revision(CurriculumVersion $version, bool $released): AssessmentItemRevision
    {
        $item = AssessmentItem::query()->create(['curriculum_version_id' => $version->id, 'item_type' => 'multiple_choice', 'internal_label' => null, 'status' => 'draft', 'published_revision_id' => null]);
        $revision = AssessmentItemRevision::query()->create(['assessment_item_id' => $item->id, 'curriculum_version_id' => $version->id, 'revision_number' => 1, 'primary_topic_id' => null, 'difficulty' => 'easy', 'content_payload' => ['q' => 'x'], 'content_schema_version' => 1, 'scoring_payload' => ['a' => 'x'], 'scoring_schema_version' => 1, 'released_at' => null]);
        $skill = Skill::query()->create(['name' => 'H4 '.Str::random(6), 'description' => null]);
        $placement = SkillVersionPlacement::query()->create(['skill_id' => $skill->id, 'curriculum_version_id' => $version->id]);
        AssessmentItemRevisionSkill::query()->create(['assessment_item_revision_id' => $revision->id, 'skill_version_placement_id' => $placement->id, 'curriculum_version_id' => $version->id, 'role' => 'primary']);
        if ($released) {
            DB::table('assessment_item_revisions')->where('id', $revision->id)->update(['released_at' => CarbonImmutable::now('UTC')]);
        }

        return $revision->refresh();
    }

    private function createPractice(User $teacher, string $base): string
    {
        return $this->actingAs($teacher)
            ->postJson($base.'/practice-activities', ['name' => 'Practice'])
            ->assertCreated()
            ->json('data.id');
    }

    private function addPracticeItem(User $teacher, string $base, string $practiceId, string $revisionId, int $displayOrder): string
    {
        return $this->actingAs($teacher)
            ->postJson($base.'/practice-activities/'.$practiceId.'/items', [
                'assessment_item_revision_ids' => [$revisionId],
                'display_order' => $displayOrder,
            ])
            ->assertCreated()
            ->json('data.0.id');
    }

    private function base(TeacherSubjectAssignment $a, Curriculum $c, CurriculumVersion $v): string
    {
        return '/api/teacher/subject-assignments/'.$a->id.'/curricula/'.$c->id.'/versions/'.$v->id;
    }
}

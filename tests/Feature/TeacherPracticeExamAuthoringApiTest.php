<?php

namespace Tests\Feature;

use App\Application\Attempt\BuildExamAttempt;
use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
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

    public function test_teacher_preserves_submitted_revision_order_for_practice_memberships(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $first = $this->revision($version, true);
        $second = $this->revision($version, true);
        $submitted = [$first->id, $second->id];
        sort($submitted, SORT_STRING);
        $submitted = array_reverse($submitted);
        $practiceId = $this->createPractice($teacher, $base);

        $response = $this->actingAs($teacher)
            ->postJson($base.'/practice-activities/'.$practiceId.'/items', [
                'assessment_item_revision_ids' => [
                    $submitted[0],
                    $submitted[1],
                    $submitted[0],
                ],
                'display_order' => 4,
            ])
            ->assertCreated();

        $created = $response->json('data');
        $this->assertCount(2, $created);
        $this->assertSame($submitted, array_column($created, 'assessment_item_revision_id'));
        $this->assertSame(
            $submitted,
            DB::table('practice_activity_items')
                ->where('practice_activity_id', $practiceId)
                ->orderBy('display_order')
                ->pluck('assessment_item_revision_id')
                ->all(),
        );
        $this->assertSame(
            [4, 5],
            DB::table('practice_activity_items')
                ->where('practice_activity_id', $practiceId)
                ->orderBy('display_order')
                ->pluck('display_order')
                ->all(),
        );
    }

    public function test_teacher_generation_and_exam_attempt_preserve_submitted_revision_order(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $first = $this->revision($version, true);
        $second = $this->revision($version, true);
        $submitted = [$first->id, $second->id];
        sort($submitted, SORT_STRING);
        $submitted = array_reverse($submitted);

        $templateId = $this->actingAs($teacher)
            ->postJson($base.'/exam-templates', ['name' => 'Ordered Exam'])
            ->assertCreated()
            ->json('data.id');
        $templateVersionId = $this->actingAs($teacher)
            ->postJson($base.'/exam-templates/'.$templateId.'/versions', [
                'version_number' => 1,
                'label' => 'v1',
                'rules_payload' => [],
                'rules_schema_version' => 1,
            ])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($teacher)
            ->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/publish')
            ->assertOk();

        $generationId = $this->actingAs($teacher)
            ->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/generations', [
                'generator_version' => 'h4',
                'seed' => 'teacher-order',
                'assessment_item_revision_ids' => $submitted,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            $submitted,
            DB::table('exam_generation_items')
                ->where('exam_generation_id', $generationId)
                ->orderBy('selection_position')
                ->pluck('assessment_item_revision_id')
                ->all(),
        );
        $this->assertSame(
            [0, 1],
            DB::table('exam_generation_items')
                ->where('exam_generation_id', $generationId)
                ->orderBy('selection_position')
                ->pluck('selection_position')
                ->all(),
        );

        $learner = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $learnerProfileId = (string) Str::uuid();
        DB::table('learner_profiles')->insert([
            'id' => $learnerProfileId,
            'user_id' => $learner->id,
            'created_at' => now(),
        ]);
        $enrollment = app(RequestStudentEnrollment::class)->execute(
            actorUserId: $learner->id,
            learnerProfileId: $learnerProfileId,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'FR-002 ordering fixture.',
        );
        app(AcceptStudentEnrollment::class)->execute(
            actorUserId: $teacher->id,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'FR-002 ordering fixture.',
        );
        DB::table('curriculum_versions')
            ->where('id', $version->id)
            ->update(['status' => 'published']);

        $attempt = app(BuildExamAttempt::class)->execute(
            $learner->id,
            $learnerProfileId,
            $generationId,
        );

        $this->assertSame(
            $submitted,
            DB::table('attempt_items')
                ->where('attempt_id', $attempt->id)
                ->orderBy('presentation_position')
                ->pluck('assessment_item_revision_id')
                ->all(),
        );
        $this->assertSame(
            [0, 1],
            DB::table('attempt_items')
                ->where('attempt_id', $attempt->id)
                ->orderBy('presentation_position')
                ->pluck('presentation_position')
                ->all(),
        );
    }

    public function test_teacher_reads_authorized_h4_resources_without_mutation(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $first = $this->revision($version, true);
        $second = $this->revision($version, true);
        $practiceId = $this->createPractice($teacher, $base);
        $this->addPracticeItem($teacher, $base, $practiceId, $first->id, 10);
        $this->addPracticeItem($teacher, $base, $practiceId, $second->id, 2);
        $templateId = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'Read Template'])->assertCreated()->json('data.id');
        $versionTwo = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', ['version_number' => 2, 'label' => 'v2', 'rules_payload' => ['two' => true], 'rules_schema_version' => 1])->assertCreated()->json('data.id');
        $versionOne = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', ['version_number' => 1, 'label' => 'v1', 'rules_payload' => ['nested' => ['value' => 1]], 'rules_schema_version' => 2])->assertCreated()->json('data.id');
        $before = [DB::table('practice_activities')->count(), DB::table('practice_activity_items')->count(), DB::table('exam_templates')->count(), DB::table('exam_template_versions')->count()];
        $this->app['auth']->forgetGuards();
        $this->actingAs($teacher)->getJson($base.'/practice-activities')->assertOk()->assertJsonPath('data.0.id', $practiceId);
        $this->actingAs($teacher)->getJson($base.'/practice-activities/'.$practiceId.'/items')->assertOk()->assertJsonPath('data.0.assessment_item_revision_id', $second->id)->assertJsonPath('data.0.display_order', 2)->assertJsonPath('data.1.assessment_item_revision_id', $first->id)->assertJsonPath('data.1.display_order', 10);
        $this->actingAs($teacher)->getJson($base.'/exam-templates')->assertOk()->assertJsonPath('data.0.id', $templateId);
        $this->actingAs($teacher)->getJson($base.'/exam-templates/'.$templateId.'/versions')->assertOk()->assertJsonPath('data.0.id', $versionOne)->assertJsonPath('data.1.id', $versionTwo);
        $this->actingAs($teacher)->getJson($base.'/exam-templates/'.$templateId.'/versions/'.$versionOne)->assertOk()->assertJsonPath('data.rules_payload.nested.value', 1)->assertJsonPath('data.rules_schema_version', 2);
        $this->assertSame($before, [DB::table('practice_activities')->count(), DB::table('practice_activity_items')->count(), DB::table('exam_templates')->count(), DB::table('exam_template_versions')->count()]);
    }

    public function test_teacher_h4_read_collections_are_empty_when_authorized(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $this->actingAs($teacher)->getJson($base.'/practice-activities')->assertOk()->assertJsonPath('data', []);
        $this->actingAs($teacher)->getJson($base.'/exam-templates')->assertOk()->assertJsonPath('data', []);
    }

    public function test_teacher_h4_reads_hide_cross_scope_resources_and_enforce_middleware(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $this->createPractice($teacher, $base);
        $templateId = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'Scoped'])->assertCreated()->json('data.id');
        $templateVersionId = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', ['version_number' => 1, 'label' => 'v1', 'rules_payload' => [], 'rules_schema_version' => 1])->assertCreated()->json('data.id');
        $otherTemplateId = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'Other'])->assertCreated()->json('data.id');
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->actingAs($otherTeacher)->getJson($base.'/practice-activities')->assertNotFound();
        $this->actingAs($teacher)->getJson('/api/teacher/subject-assignments/'.Str::uuid().'/curricula/'.$curriculum->id.'/versions/'.$version->id.'/practice-activities')->assertNotFound();
        $this->actingAs($teacher)->getJson($base.'/practice-activities/'.Str::uuid().'/items')->assertNotFound();
        $this->actingAs($teacher)->getJson($base.'/exam-templates/'.$otherTemplateId.'/versions/'.$templateVersionId)->assertNotFound();
        DB::table('teacher_subject_assignments')->where('id', $assignment->id)->update(['status' => 'inactive']);
        $this->actingAs($teacher)->getJson($base.'/practice-activities')->assertNotFound();
        DB::table('teacher_subject_assignments')->where('id', $assignment->id)->update(['status' => 'active']);
        DB::table('users')->where('id', $teacher->id)->update(['status' => 'disabled']);
        $this->actingAs($teacher)->getJson($base.'/practice-activities')->assertForbidden()->assertJsonPath('error.code', 'account_disabled');
        $this->actingAs(User::factory()->create(['role' => 'student', 'status' => 'active']))->getJson($base.'/practice-activities')->assertForbidden()->assertJsonPath('error.code', 'teacher_forbidden');
    }

    public function test_teacher_h4_reads_hide_cross_curriculum_and_cross_version_resources(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $revision = $this->revision($version, true);
        $practiceId = $this->createPractice($teacher, $base);
        $this->addPracticeItem($teacher, $base, $practiceId, $revision->id, 0);
        $templateId = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'Current'])->assertCreated()->json('data.id');
        $templateVersionId = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', ['version_number' => 1, 'label' => 'v1', 'rules_payload' => [], 'rules_schema_version' => 1])->assertCreated()->json('data.id');
        $otherCurriculum = app(CreateOwnedCurriculum::class)->execute(actorUserId: $teacher->id, teacherSubjectAssignmentId: $assignment->id, name: 'Other '.Str::random(6), educationStageId: null);
        $otherVersion = CurriculumVersion::query()->create(['curriculum_id' => $otherCurriculum->id, 'version_number' => 1, 'label' => 'Other', 'status' => 'draft']);
        $otherBase = $this->base($assignment, $otherCurriculum, $otherVersion);
        $otherTemplateId = $this->actingAs($teacher)->postJson($otherBase.'/exam-templates', ['name' => 'Other'])->assertCreated()->json('data.id');
        $otherTemplateVersionId = $this->actingAs($teacher)->postJson($otherBase.'/exam-templates/'.$otherTemplateId.'/versions', ['version_number' => 1, 'label' => 'other', 'rules_payload' => [], 'rules_schema_version' => 1])->assertCreated()->json('data.id');
        $alternateTemplateId = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'Alternate'])->assertCreated()->json('data.id');

        $this->actingAs($teacher)->getJson($this->base($assignment, $otherCurriculum, $version).'/practice-activities')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($teacher)->getJson($this->base($assignment, $curriculum, $otherVersion).'/exam-templates')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($teacher)->getJson($otherBase.'/practice-activities/'.$practiceId.'/items')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($teacher)->getJson($otherBase.'/exam-templates/'.$templateId.'/versions')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($teacher)->getJson($base.'/exam-templates/'.$alternateTemplateId.'/versions/'.$templateVersionId)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($teacher)->getJson($base.'/exam-templates/'.$templateId.'/versions/'.$otherTemplateVersionId)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_teacher_h4_reads_preserve_legal_archived_and_retired_resources(): void
    {
        [$teacher, $assignment, $curriculum, $version] = $this->fixture();
        $base = $this->base($assignment, $curriculum, $version);
        $revision = $this->revision($version, true);
        $practiceId = $this->createPractice($teacher, $base);
        $this->addPracticeItem($teacher, $base, $practiceId, $revision->id, 0);
        $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/activate')->assertOk();
        $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
        $templateId = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'Historical'])->assertCreated()->json('data.id');
        $firstVersionId = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', ['version_number' => 1, 'label' => 'v1', 'rules_payload' => ['historical' => true], 'rules_schema_version' => 1])->assertCreated()->json('data.id');
        $secondVersionId = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', ['version_number' => 2, 'label' => 'v2', 'rules_payload' => [], 'rules_schema_version' => 1])->assertCreated()->json('data.id');
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$firstVersionId.'/publish')->assertOk();
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$secondVersionId.'/publish')->assertOk();
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$firstVersionId.'/retire')->assertOk()->assertJsonPath('data.status', 'retired');
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/archive')->assertOk()->assertJsonPath('data.status', 'archived');

        $this->actingAs($teacher)->getJson($base.'/practice-activities')->assertOk()->assertJsonPath('data.0.id', $practiceId)->assertJsonPath('data.0.status', 'archived');
        $this->actingAs($teacher)->getJson($base.'/exam-templates')->assertOk()->assertJsonPath('data.0.id', $templateId)->assertJsonPath('data.0.status', 'archived');
        $this->actingAs($teacher)->getJson($base.'/exam-templates/'.$templateId.'/versions')->assertOk()->assertJsonPath('data.0.id', $firstVersionId)->assertJsonPath('data.0.status', 'retired')->assertJsonPath('data.1.id', $secondVersionId)->assertJsonPath('data.1.status', 'published');
        $this->actingAs($teacher)->getJson($base.'/exam-templates/'.$templateId.'/versions/'.$firstVersionId)->assertOk()->assertJsonPath('data.status', 'retired')->assertJsonPath('data.rules_payload.historical', true);
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

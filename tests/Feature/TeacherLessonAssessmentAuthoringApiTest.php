<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
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

class TeacherLessonAssessmentAuthoringApiTest extends TestCase
{
    use CreatesHistoricalOwnerlessCurriculumFixtures;
    use RefreshDatabase;

    public function test_teacher_authors_lesson_revision_skills_and_frozen_lifecycle(): void
    {
        [$teacher, $assignment, $curriculum] = $this->fixture();
        $version = $this->version($curriculum, 'draft');
        $topic = $this->topic($version);
        $base = $this->versionUri($assignment, $curriculum, $version);

        $lesson = $this->actingAs($teacher)->postJson($base.'/lessons', [
            'title' => 'H3 Lesson', 'description' => 'Draft', 'display_order' => 1,
        ])->assertCreated();
        $lessonId = $lesson->json('data.id');

        $this->actingAs($teacher)->putJson($base.'/lessons/'.$lessonId, [
            'title' => 'H3 Lesson Updated', 'description' => null, 'display_order' => 2,
        ])->assertOk()->assertJsonPath('data.title', 'H3 Lesson Updated');

        $revision = $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions', $this->lessonRevisionPayload($topic))
            ->assertCreated();
        $revisionId = $revision->json('data.id');
        $placement = $this->placement($version);

        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions/'.$revisionId.'/skills', [
            'skill_version_placement_id' => $placement->id,
        ])->assertCreated();

        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions/'.$revisionId.'/skills', [
            'skill_version_placement_id' => (string) Str::uuid(),
        ])->assertNotFound();

        $other = $this->version($curriculum, 'draft', 2);
        $foreignPlacement = $this->placement($other);
        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions/'.$revisionId.'/skills', [
            'skill_version_placement_id' => $foreignPlacement->id,
        ])->assertNotFound();

        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions/'.$revisionId.'/release')
            ->assertOk();
        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/publish', [
            'published_revision_id' => $revisionId,
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/unpublish')
            ->assertOk()->assertJsonPath('data.status', 'unpublished');
        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/publish', [
            'published_revision_id' => $revisionId,
        ])->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_teacher_authors_assessment_revision_skills_and_lifecycle(): void
    {
        [$teacher, $assignment, $curriculum] = $this->fixture();
        $version = $this->version($curriculum, 'draft');
        $topic = $this->topic($version);
        $base = $this->versionUri($assignment, $curriculum, $version);
        $item = $this->actingAs($teacher)->postJson($base.'/assessment-items', [
            'item_type' => 'multiple_choice', 'internal_label' => 'H3 item',
        ])->assertCreated();
        $itemId = $item->json('data.id');

        $this->actingAs($teacher)->putJson($base.'/assessment-items/'.$itemId, [
            'item_type' => 'multiple_choice', 'internal_label' => 'H3 item updated',
        ])->assertOk();

        $revision = $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/revisions', $this->assessmentRevisionPayload($topic))
            ->assertCreated();
        $revisionId = $revision->json('data.id');
        $placement = $this->placement($version);
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/revisions/'.$revisionId.'/skills', [
            'skill_version_placement_id' => $placement->id, 'role' => 'primary',
        ])->assertCreated();
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/revisions/'.$revisionId.'/skills', [
            'skill_version_placement_id' => (string) Str::uuid(), 'role' => 'supporting',
        ])->assertNotFound();

        $other = $this->version($curriculum, 'draft', 2);
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/revisions/'.$revisionId.'/skills', [
            'skill_version_placement_id' => $this->placement($other)->id, 'role' => 'supporting',
        ])->assertNotFound();

        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/revisions/'.$revisionId.'/release')->assertOk();
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/publish', ['published_revision_id' => $revisionId])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/retire')
            ->assertOk()->assertJsonPath('data.status', 'retired');
    }

    public function test_teacher_creates_revisions_only_for_draft_assessment_items(): void
    {
        [$teacher, $assignment, $curriculum] = $this->fixture();
        $version = $this->version($curriculum, 'draft');
        $topic = $this->topic($version);
        $base = $this->versionUri($assignment, $curriculum, $version);
        $item = $this->actingAs($teacher)->postJson($base.'/assessment-items', [
            'item_type' => 'multiple_choice', 'internal_label' => 'H3 revision boundary',
        ])->assertCreated();
        $itemId = $item->json('data.id');

        $revision = $this->actingAs($teacher)->postJson(
            $base.'/assessment-items/'.$itemId.'/revisions',
            $this->assessmentRevisionPayload($topic),
        )->assertCreated();
        $revisionId = $revision->json('data.id');
        $placement = $this->placement($version);
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/revisions/'.$revisionId.'/skills', [
            'skill_version_placement_id' => $placement->id, 'role' => 'primary',
        ])->assertCreated();
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/revisions/'.$revisionId.'/release')
            ->assertOk();
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/publish', [
            'published_revision_id' => $revisionId,
        ])->assertOk()->assertJsonPath('data.status', 'published');

        $publishedCount = DB::table('assessment_item_revisions')->where('assessment_item_id', $itemId)->count();
        $this->actingAs($teacher)->postJson(
            $base.'/assessment-items/'.$itemId.'/revisions',
            $this->assessmentRevisionPayload($topic),
        )->assertStatus(409)->assertJsonPath('error.code', 'assessment_item_not_draft');
        $this->assertSame($publishedCount, DB::table('assessment_item_revisions')->where('assessment_item_id', $itemId)->count());

        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$itemId.'/retire')
            ->assertOk()->assertJsonPath('data.status', 'retired');

        $retiredCount = DB::table('assessment_item_revisions')->where('assessment_item_id', $itemId)->count();
        $this->actingAs($teacher)->postJson(
            $base.'/assessment-items/'.$itemId.'/revisions',
            $this->assessmentRevisionPayload($topic),
        )->assertStatus(409)->assertJsonPath('error.code', 'assessment_item_not_draft');
        $this->assertSame($retiredCount, DB::table('assessment_item_revisions')->where('assessment_item_id', $itemId)->count());
    }

    public function test_teacher_h3_authority_and_draft_boundary_fail_closed(): void
    {
        [$teacher, $assignment, $curriculum] = $this->fixture();
        [, , $foreign] = $this->fixture('physics');
        $foreignVersion = $this->version($foreign, 'draft');
        $this->actingAs($teacher)->postJson($this->versionUri($assignment, $foreign, $foreignVersion).'/lessons', ['title' => 'foreign'])
            ->assertNotFound();

        $ownerless = $this->createHistoricalOwnerlessCurriculumFixture('H3 ownerless');
        $ownerlessVersion = $this->version($ownerless, 'draft');
        $this->actingAs($teacher)->postJson($this->versionUri($assignment, $ownerless, $ownerlessVersion).'/assessment-items', ['item_type' => 'x'])
            ->assertNotFound();

        $published = $this->version($curriculum, 'draft');
        DB::table('curriculum_versions')->where('id', $published->id)->update(['status' => 'published']);
        $this->actingAs($teacher)->postJson($this->versionUri($assignment, $curriculum, $published).'/lessons', ['title' => 'blocked'])
            ->assertStatus(409);

        $teacher->update(['status' => 'disabled']);
        $this->actingAs($teacher)->postJson($this->versionUri($assignment, $curriculum, $published).'/lessons', ['title' => 'disabled'])
            ->assertStatus(403);
    }

    public function test_student_and_admin_cannot_use_teacher_h3_routes(): void
    {
        $uri = '/api/teacher/subject-assignments/'.Str::uuid().'/curricula/'.Str::uuid().'/versions/'.Str::uuid().'/lessons';
        foreach ([$this->user('student'), $this->user('admin')] as $actor) {
            $this->actingAs($actor)->postJson($uri, ['title' => 'Forbidden'])
                ->assertStatus(403)->assertJsonPath('error.code', 'teacher_forbidden');
        }
    }

    private function fixture(string $subject = 'mathematics'): array
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $assignment = app(AssignTeacherSubject::class)->execute(actorUserId: $admin->id, teacherUserId: $teacher->id, subjectId: $this->canonicalSubjectId($subject), operationId: (string) Str::uuid(), reason: 'H3 fixture.');
        $curriculum = app(CreateOwnedCurriculum::class)->execute(actorUserId: $teacher->id, teacherSubjectAssignmentId: $assignment->id, name: 'H3 '.Str::random(8), educationStageId: null);

        return [$teacher, $assignment, $curriculum];
    }

    private function version(Curriculum $c, string $status, int $number = 1): CurriculumVersion
    {
        return CurriculumVersion::query()->create(['curriculum_id' => $c->id, 'version_number' => $number, 'label' => 'H3 '.$number, 'status' => $status]);
    }

    private function topic(CurriculumVersion $v): Topic
    {
        return Topic::query()->create(['curriculum_version_id' => $v->id, 'name' => 'H3 topic '.Str::random(5), 'display_order' => 0]);
    }

    private function placement(CurriculumVersion $v): SkillVersionPlacement
    {
        $skill = Skill::query()->create(['name' => 'H3 skill '.Str::random(5), 'description' => null]);

        return SkillVersionPlacement::query()->create(['skill_id' => $skill->id, 'curriculum_version_id' => $v->id]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function versionUri(TeacherSubjectAssignment $a, Curriculum $c, CurriculumVersion $v): string
    {
        return '/api/teacher/subject-assignments/'.$a->id.'/curricula/'.$c->id.'/versions/'.$v->id;
    }

    private function lessonRevisionPayload(Topic $t): array
    {
        return ['revision_number' => 1, 'primary_topic_id' => $t->id, 'content_payload' => ['body' => 'lesson'], 'content_schema_version' => 1];
    }

    private function assessmentRevisionPayload(Topic $t): array
    {
        return ['revision_number' => 1, 'primary_topic_id' => $t->id, 'difficulty' => 'easy', 'content_payload' => ['question' => 'q'], 'content_schema_version' => 1, 'scoring_payload' => ['answer' => 'a'], 'scoring_schema_version' => 1];
    }
}

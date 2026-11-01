<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class H6S7TeacherAuthoringIntegrationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_to_teacher_authoring_journey_preserves_exact_context_lifecycle_and_provenance(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $subject = Subject::query()->where('code', 'mathematics')->where('status', 'active')->firstOrFail();

        $provisioned = $this->actingAs($admin)->postJson('/api/admin/teachers', [
            'name' => 'H6 S7 Teacher',
            'email' => 'h6-s7-teacher@example.test',
        ])->assertCreated()->assertJsonPath('data.teacher.role', 'teacher')->assertJsonPath('data.teacher.status', 'disabled');
        $teacher = User::query()->findOrFail($provisioned->json('data.teacher.id'));
        $token = null;
        Notification::assertSentTo($teacher, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->assertIsString($token);
        $password = 'EduCore!H6S7Teacher2026';
        $this->postJson('/auth/reset-password', [
            'token' => $token,
            'email' => $teacher->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk()->assertJsonPath('data.reset', true);
        $teacher->refresh();
        $this->assertTrue($teacher->isActive());

        $assignment = $this->actingAs($admin)->postJson('/api/admin/teachers/'.$teacher->id.'/subject-assignments', [
            'subject_id' => $subject->id,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'H6-S7 composite authoring acceptance.',
        ])->assertOk()->assertJsonPath('data.teacher_user_id', $teacher->id)->assertJsonPath('data.subject.id', $subject->id)->assertJsonPath('data.status', 'active');
        $assignmentId = $assignment->json('data.id');

        $otherTeacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $otherSubject = Subject::query()->where('code', 'physics')->where('status', 'active')->firstOrFail();
        $foreignAssignmentId = $this->actingAs($admin)->postJson('/api/admin/teachers/'.$otherTeacher->id.'/subject-assignments', [
            'subject_id' => $otherSubject->id,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'H6-S7 foreign-context denial fixture.',
        ])->assertOk()->json('data.id');

        $this->actingAs($teacher)->getJson('/api/teacher/subject-assignments')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assignmentId)
            ->assertJsonPath('data.0.teacher_user_id', $teacher->id)->assertJsonPath('data.0.subject.id', $subject->id);
        $this->actingAs($teacher)->postJson('/api/teacher/subject-assignments/'.$foreignAssignmentId.'/curricula', [
            'name' => 'Cross-context authoring attempt',
        ])->assertNotFound();

        $curriculum = $this->actingAs($teacher)->postJson('/api/teacher/subject-assignments/'.$assignmentId.'/curricula', [
            'name' => 'H6-S7 Authoring Curriculum',
        ])->assertCreated()->assertJsonPath('data.teacher_subject_assignment_id', $assignmentId)->assertJsonPath('data.subject_id', $subject->id);
        $curriculumId = $curriculum->json('data.id');
        $version = $this->actingAs($teacher)->postJson('/api/teacher/subject-assignments/'.$assignmentId.'/curricula/'.$curriculumId.'/versions', [
            'version_number' => 1,
            'label' => 'H6-S7 v1',
        ])->assertCreated()->assertJsonPath('data.curriculum_id', $curriculumId)->assertJsonPath('data.status', 'draft');
        $versionId = $version->json('data.id');
        $base = '/api/teacher/subject-assignments/'.$assignmentId.'/curricula/'.$curriculumId.'/versions/'.$versionId;

        $topic = $this->actingAs($teacher)->postJson($base.'/topics', [
            'name' => 'H6-S7 Topic', 'display_order' => 0,
        ])->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $topicId = $topic->json('data.id');
        $skill = Skill::query()->create(['name' => 'H6-S7 Skill '.Str::random(8), 'description' => null]);
        $placement = $this->actingAs($teacher)->postJson($base.'/skill-placements', ['skill_id' => $skill->id])
            ->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $placementId = $placement->json('data.id');
        $this->actingAs($teacher)->postJson($base.'/skill-placements/'.$placementId.'/home-topics', ['topic_id' => $topicId])
            ->assertCreated()->assertJsonPath('data.topic_id', $topicId);

        $lesson = $this->actingAs($teacher)->postJson($base.'/lessons', [
            'title' => 'H6-S7 Lesson', 'description' => 'Authoring acceptance lesson.', 'display_order' => 0,
        ])->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $lessonId = $lesson->json('data.id');
        $lessonRevision = $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions', [
            'revision_number' => 1, 'primary_topic_id' => $topicId,
            'content_payload' => ['body' => 'H6-S7 released lesson'], 'content_schema_version' => 1,
        ])->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $lessonRevisionId = $lessonRevision->json('data.id');
        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions/'.$lessonRevisionId.'/skills', ['skill_version_placement_id' => $placementId])
            ->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/revisions/'.$lessonRevisionId.'/release')->assertOk();
        $this->actingAs($teacher)->postJson($base.'/lessons/'.$lessonId.'/publish', ['published_revision_id' => $lessonRevisionId])
            ->assertOk()->assertJsonPath('data.published_revision_id', $lessonRevisionId);
        $this->actingAs($teacher)->putJson($base.'/lessons/'.$lessonId.'/revisions/'.$lessonRevisionId, [
            'revision_number' => 1, 'primary_topic_id' => $topicId,
            'content_payload' => ['body' => 'Illegal rewrite'], 'content_schema_version' => 1,
        ])->assertStatus(409);
        $this->assertSame(
            ['body' => 'H6-S7 released lesson'],
            json_decode(
                (string) DB::table('lesson_revisions')->where('id', $lessonRevisionId)->value('content_payload'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        $assessment = $this->actingAs($teacher)->postJson($base.'/assessment-items', [
            'item_type' => 'multiple_choice', 'internal_label' => 'H6-S7 assessment',
        ])->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $assessmentId = $assessment->json('data.id');
        $assessmentRevision = $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$assessmentId.'/revisions', [
            'revision_number' => 1, 'primary_topic_id' => $topicId, 'difficulty' => 'easy',
            'content_payload' => ['question' => 'H6-S7 question'], 'content_schema_version' => 1,
            'scoring_payload' => ['answer' => 'H6-S7 answer'], 'scoring_schema_version' => 1,
        ])->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $assessmentRevisionId = $assessmentRevision->json('data.id');
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$assessmentId.'/revisions/'.$assessmentRevisionId.'/skills', [
            'skill_version_placement_id' => $placementId, 'role' => 'primary',
        ])->assertCreated()->assertJsonPath('data.assessment_item_revision_id', $assessmentRevisionId)->assertJsonPath('data.curriculum_version_id', $versionId);
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$assessmentId.'/revisions/'.$assessmentRevisionId.'/release')->assertOk();
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$assessmentId.'/publish', ['published_revision_id' => $assessmentRevisionId])
            ->assertOk()->assertJsonPath('data.published_revision_id', $assessmentRevisionId);
        $this->actingAs($teacher)->putJson($base.'/assessment-items/'.$assessmentId.'/revisions/'.$assessmentRevisionId, [
            'revision_number' => 1, 'primary_topic_id' => $topicId, 'difficulty' => 'easy',
            'content_payload' => ['question' => 'Illegal rewrite'], 'content_schema_version' => 1,
            'scoring_payload' => ['answer' => 'H6-S7 answer'], 'scoring_schema_version' => 1,
        ])->assertStatus(409);
        $this->assertSame(
            ['question' => 'H6-S7 question'],
            json_decode(
                (string) DB::table('assessment_item_revisions')->where('id', $assessmentRevisionId)->value('content_payload'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        $secondAssessment = $this->actingAs($teacher)->postJson($base.'/assessment-items', [
            'item_type' => 'multiple_choice', 'internal_label' => 'H6-S7 second assessment',
        ])->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $secondAssessmentId = $secondAssessment->json('data.id');
        $secondAssessmentRevision = $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$secondAssessmentId.'/revisions', [
            'revision_number' => 1, 'primary_topic_id' => $topicId, 'difficulty' => 'medium',
            'content_payload' => ['question' => 'H6-S7 second question'], 'content_schema_version' => 1,
            'scoring_payload' => ['answer' => 'H6-S7 second answer'], 'scoring_schema_version' => 1,
        ])->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $secondAssessmentRevisionId = $secondAssessmentRevision->json('data.id');
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$secondAssessmentId.'/revisions/'.$secondAssessmentRevisionId.'/skills', [
            'skill_version_placement_id' => $placementId, 'role' => 'primary',
        ])->assertCreated()->assertJsonPath('data.assessment_item_revision_id', $secondAssessmentRevisionId);
        $this->actingAs($teacher)->postJson($base.'/assessment-items/'.$secondAssessmentId.'/revisions/'.$secondAssessmentRevisionId.'/release')
            ->assertOk();

        $practice = $this->actingAs($teacher)->postJson($base.'/practice-activities', ['name' => 'H6-S7 Practice'])
            ->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $practiceId = $practice->json('data.id');
        $firstMembership = $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/items', [
            'assessment_item_revision_ids' => [$assessmentRevisionId], 'display_order' => 9,
        ])->assertCreated()->assertJsonPath('data.0.assessment_item_revision_id', $assessmentRevisionId)
            ->assertJsonPath('data.0.curriculum_version_id', $versionId)->assertJsonPath('data.0.display_order', 9);
        $firstMembershipId = $firstMembership->json('data.0.id');
        $secondMembership = $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/items', [
            'assessment_item_revision_ids' => [$secondAssessmentRevisionId], 'display_order' => 3,
        ])->assertCreated()->assertJsonPath('data.0.assessment_item_revision_id', $secondAssessmentRevisionId)
            ->assertJsonPath('data.0.curriculum_version_id', $versionId)->assertJsonPath('data.0.display_order', 3);
        $secondMembershipId = $secondMembership->json('data.0.id');
        $this->actingAs($teacher)->getJson($base.'/practice-activities/'.$practiceId.'/items')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondMembershipId)
            ->assertJsonPath('data.0.assessment_item_revision_id', $secondAssessmentRevisionId)
            ->assertJsonPath('data.0.display_order', 3)
            ->assertJsonPath('data.1.id', $firstMembershipId)
            ->assertJsonPath('data.1.assessment_item_revision_id', $assessmentRevisionId)
            ->assertJsonPath('data.1.display_order', 9);
        $this->actingAs($teacher)->postJson($base.'/practice-activities/'.$practiceId.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
        $this->actingAs($teacher)->deleteJson($base.'/practice-activities/'.$practiceId.'/items/'.$firstMembershipId)
            ->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('practice_activity_items', ['id' => $firstMembershipId]);
        $this->actingAs($teacher)->getJson($base.'/practice-activities/'.$practiceId.'/items')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondMembershipId)
            ->assertJsonPath('data.0.assessment_item_revision_id', $secondAssessmentRevisionId)
            ->assertJsonPath('data.0.display_order', 3);
        $this->actingAs($teacher)->deleteJson($base.'/practice-activities/'.$practiceId.'/items/'.$secondMembershipId)
            ->assertStatus(409)->assertJsonPath('error.code', 'practice_activity_requires_item');
        $this->assertDatabaseHas('practice_activity_items', [
            'id' => $secondMembershipId, 'assessment_item_revision_id' => $secondAssessmentRevisionId,
            'curriculum_version_id' => $versionId, 'display_order' => 3,
        ]);

        $template = $this->actingAs($teacher)->postJson($base.'/exam-templates', ['name' => 'H6-S7 Exam'])
            ->assertCreated()->assertJsonPath('data.curriculum_version_id', $versionId);
        $templateId = $template->json('data.id');
        $templateVersion = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions', [
            'version_number' => 1, 'label' => 'H6-S7 Exam v1', 'rules_payload' => [], 'rules_schema_version' => 1,
        ])->assertCreated();
        $templateVersionId = $templateVersion->json('data.id');
        $templateVersionBody = json_decode($templateVersion->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertIsObject($templateVersionBody->data->rules_payload);
        $this->assertSame([], get_object_vars($templateVersionBody->data->rules_payload));
        $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/publish')
            ->assertOk()->assertJsonPath('data.status', 'published');
        $generation = $this->actingAs($teacher)->postJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId.'/generations', [
            'generator_version' => 'h6-s7', 'seed' => 'h6-s7-authoring-journey',
            'assessment_item_revision_ids' => [$assessmentRevisionId],
        ])->assertCreated()->assertJsonPath('data.exam_template_version_id', $templateVersionId)
            ->assertJsonPath('data.curriculum_version_id', $versionId)
            ->assertJsonPath('data.items.0.assessment_item_revision_id', $assessmentRevisionId);
        $generationId = $generation->json('data.id');
        $snapshot = DB::selectOne('SELECT exam_template_version_id, rules_snapshot::text AS rules_json FROM exam_generations WHERE id = ?', [$generationId]);
        $this->assertNotNull($snapshot);
        $this->assertSame($templateVersionId, $snapshot->exam_template_version_id);
        $this->assertSame('{}', $snapshot->rules_json);
        $this->assertDatabaseHas('exam_generation_items', [
            'exam_generation_id' => $generationId, 'assessment_item_revision_id' => $assessmentRevisionId,
            'assessment_item_id' => $assessmentId, 'selection_position' => 0,
        ]);
        $this->actingAs($teacher)->putJson($base.'/exam-templates/'.$templateId.'/versions/'.$templateVersionId, [
            'label' => 'Illegal historical rewrite', 'rules_payload' => (object) [], 'rules_schema_version' => 1,
        ])->assertStatus(409)->assertJsonPath('error.code', 'exam_template_version_not_draft');

        $this->actingAs($admin)->postJson($base.'/practice-activities', [
            'name' => 'Admin must not author teacher content',
        ])->assertForbidden()->assertJsonPath('error.code', 'teacher_forbidden');
    }
}

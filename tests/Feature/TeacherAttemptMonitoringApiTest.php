<?php

namespace Tests\Feature;

use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Attempt\AddRegradeCorrection;
use App\Application\Attempt\BuildExamAttempt;
use App\Application\Attempt\BuildPracticeAttempt;
use App\Application\Attempt\FinalizeAttempt;
use App\Application\Attempt\SaveAttemptResponse;
use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\Attempt;
use App\Models\CurriculumVersion;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TeacherAttemptMonitoringApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_teacher_lists_finalized_attempts_and_reads_effective_result(): void
    {
        $fixture = $this->fixture();

        $list = $this->actingAs($fixture['teacher'])
            ->getJson($this->listUri($fixture))
            ->assertOk()
            ->assertJsonPath('data.enrollment.id', $fixture['enrollment']->id)
            ->assertJsonPath('data.enrollment.student.user_id', $fixture['student']->id)
            ->assertJsonPath('data.curriculum_version.id', $fixture['version']->id)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.current_page', 1);

        $submitted = collect($list->json('data.items'))
            ->firstWhere('id', $fixture['submitted']->id);
        $this->assertSame('practice', $submitted['kind']);
        $this->assertSame('available', data_get($submitted, 'result.availability'));
        $this->assertSame(2, data_get($submitted, 'result.summary.answered'));
        $this->assertSame(2, data_get($submitted, 'result.summary.correct'));
        $this->assertSame(0, data_get($submitted, 'result.summary.incorrect'));
        $this->assertSame(1, data_get($submitted, 'result.summary.unanswered'));
        $this->assertSame(3, data_get($submitted, 'result.summary.total'));

        $result = $this->getJson($this->resultUri($fixture, $fixture['submitted']))
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $fixture['submitted']->id)
            ->assertJsonPath('data.attempt.result.summary.correct', 2)
            ->assertJsonPath('data.attempt.result.summary.unanswered', 1);
        $encoded = json_encode($result->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($fixture['student']->email, $encoded);
        foreach (['learner_profile_id', 'response_payload', 'correct_option', 'presented_payload', 'scoring_snapshot', 'time_spent_ms', 'answers', 'grade', 'mastery'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $encoded);
        }
    }

    public function test_effective_summary_counts_correct_incorrect_and_unanswered_results(): void
    {
        $fixture = $this->fixture();
        $secondResponse = $fixture['submitted']
            ->items()
            ->orderBy('presentation_position')
            ->get()[1]
            ->response()
            ->firstOrFail();

        app(AddRegradeCorrection::class)->execute(
            $secondResponse->id,
            false,
            'S5 latest correction establishes an effective incorrect result.',
        );

        $this->actingAs($fixture['teacher'])
            ->getJson($this->resultUri($fixture, $fixture['submitted']))
            ->assertOk()
            ->assertJsonPath('data.attempt.result.summary.answered', 2)
            ->assertJsonPath('data.attempt.result.summary.correct', 1)
            ->assertJsonPath('data.attempt.result.summary.incorrect', 1)
            ->assertJsonPath('data.attempt.result.summary.unanswered', 1)
            ->assertJsonPath('data.attempt.result.summary.total', 3);
    }

    public function test_canonical_exam_snapshot_is_aggregated_by_teacher_result_api(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(1, $fixture['exam']->items()->count());
        $this->assertDatabaseHas('attempt_items', [
            'attempt_id' => $fixture['exam']->id,
            'exam_generation_id' => $fixture['exam']->exam_generation_id,
            'curriculum_version_id' => $fixture['version']->id,
        ]);

        $this->actingAs($fixture['teacher'])
            ->getJson($this->listUri($fixture, ['kind' => 'exam']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $fixture['exam']->id)
            ->assertJsonPath('data.items.0.kind', 'exam')
            ->assertJsonPath(
                'data.items.0.source.exam_generation_id',
                $fixture['exam']->exam_generation_id,
            )
            ->assertJsonPath('data.items.0.result.summary.answered', 1)
            ->assertJsonPath('data.items.0.result.summary.correct', 0)
            ->assertJsonPath('data.items.0.result.summary.incorrect', 1)
            ->assertJsonPath('data.items.0.result.summary.unanswered', 0)
            ->assertJsonPath('data.items.0.result.summary.total', 1);

        $this->getJson($this->resultUri($fixture, $fixture['exam']))
            ->assertOk()
            ->assertJsonPath('data.attempt.id', $fixture['exam']->id)
            ->assertJsonPath('data.attempt.result.summary.incorrect', 1);
    }

    public function test_practice_exam_status_filters_pagination_and_order_are_deterministic(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['teacher'])
            ->getJson($this->listUri($fixture, ['kind' => 'practice', 'status' => 'submitted']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $fixture['submitted']->id);

        $this->getJson($this->listUri($fixture, ['kind' => 'exam']))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $fixture['exam']->id)
            ->assertJsonPath('data.items.0.kind', 'exam');

        $page = $this->getJson($this->listUri($fixture, ['per_page' => 1, 'page' => 1]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 3);
        $this->assertCount(1, $page->json('data.items'));
        $this->assertNotContains(
            $fixture['inProgress']->id,
            collect($page->json('data.items'))->pluck('id')->all(),
        );

        $ordered = $this->getJson($this->listUri($fixture))->json('data.items');
        $keys = collect($ordered)->map(
            fn (array $item): string => $item['finalized_at'].'|'.$item['id'],
        )->all();
        $expected = $keys;
        rsort($expected, SORT_STRING);
        $this->assertSame($expected, $keys);
    }

    public function test_abandoned_attempt_has_explicitly_unavailable_result(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['teacher'])
            ->getJson($this->resultUri($fixture, $fixture['abandoned']))
            ->assertOk()
            ->assertJsonPath('data.attempt.status', 'abandoned')
            ->assertJsonPath('data.attempt.result.availability', 'not_applicable')
            ->assertJsonPath('data.attempt.result.reason', 'attempt_abandoned')
            ->assertJsonPath('data.attempt.result.summary', null);
    }

    public function test_result_derives_exact_version_from_authorized_attempt(): void
    {
        $fixture = $this->fixture();
        $foreign = $this->fixture('published', 'physics', false);

        $this->actingAs($fixture['teacher'])
            ->getJson(
                $this->resultUri($fixture, $fixture['submitted'])
                .'?curriculum_version_id='.$foreign['version']->id,
            )
            ->assertOk()
            ->assertJsonPath(
                'data.curriculum_version.id',
                $fixture['version']->id,
            );
    }

    public function test_foreign_teacher_enrollment_version_and_attempt_guessing_are_hidden(): void
    {
        $fixture = $this->fixture();
        $foreign = $this->fixture('published', 'physics');

        $this->actingAs($foreign['teacher'])
            ->getJson(
                $this->listUri($fixture)
                .'&teacher_user_id='.$fixture['teacher']->id,
            )
            ->assertNotFound();
        $this->actingAs($fixture['teacher'])
            ->getJson($this->listUri($foreign, [], $fixture['version']->id))
            ->assertNotFound();
        $this->getJson(
            $this->listUri($fixture, [], $foreign['version']->id),
        )->assertNotFound();
        $this->getJson($this->resultUri($fixture, $foreign['submitted']))
            ->assertNotFound();
    }

    public function test_current_assignment_enrollment_and_student_authority_is_reasserted(): void
    {
        $assignment = $this->fixture();
        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $assignment['admin']->id,
            assignmentId: $assignment['assignment']->id,
            operationId: (string) Str::uuid(),
            reason: 'S3 inactive assignment.',
        );
        $this->actingAs($assignment['teacher'])
            ->getJson($this->listUri($assignment))
            ->assertNotFound();

        $enrollment = $this->fixture('published', 'physics');
        app(DeactivateStudentEnrollment::class)->execute(
            actorUserId: $enrollment['teacher']->id,
            enrollmentId: $enrollment['enrollment']->id,
            operationId: (string) Str::uuid(),
            reason: 'S3 inactive enrollment.',
        );
        $this->actingAs($enrollment['teacher'])
            ->getJson($this->resultUri($enrollment, $enrollment['submitted']))
            ->assertNotFound();

        $student = $this->fixture('published');
        DB::table('users')->where('id', $student['student']->id)->update([
            'status' => 'disabled',
            'updated_at' => now(),
        ]);
        $this->actingAs($student['teacher'])
            ->getJson($this->listUri($student))
            ->assertNotFound();
    }

    public function test_published_and_retired_versions_are_visible_but_draft_is_denied(): void
    {
        $published = $this->fixture();
        $this->actingAs($published['teacher'])
            ->getJson($this->listUri($published))
            ->assertOk();

        $retired = $this->fixture('retired', 'physics');
        $this->actingAs($retired['teacher'])
            ->getJson($this->listUri($retired))
            ->assertOk()
            ->assertJsonPath('data.curriculum_version.status', 'retired');

        $draft = $this->fixture('draft', 'mathematics', false);
        $this->actingAs($draft['teacher'])
            ->getJson($this->listUri($draft))
            ->assertNotFound();
    }

    public function test_authentication_roles_disabled_teacher_validation_and_methods_are_enforced(): void
    {
        $fixture = $this->fixture();
        $this->getJson($this->listUri($fixture))->assertUnauthorized();

        foreach (['student', 'admin'] as $role) {
            $this->actingAs($this->user($role))
                ->getJson($this->listUri($fixture))
                ->assertForbidden();
        }
        $this->actingAs($this->user('teacher', 'disabled'))
            ->getJson($this->listUri($fixture))
            ->assertForbidden();

        $this->actingAs($fixture['teacher'])
            ->getJson("/api/teacher/monitoring/enrollments/{$fixture['enrollment']->id}/attempts")
            ->assertUnprocessable();
        $this->getJson($this->listUri($fixture, ['kind' => 'quiz']))
            ->assertUnprocessable();

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}($this->listUri($fixture), [])->assertStatus(405);
            $this->{$method}(
                $this->resultUri($fixture, $fixture['submitted']),
                [],
            )->assertStatus(405);
        }
    }

    public function test_read_paths_do_not_write_or_trigger_analytics(): void
    {
        $fixture = $this->fixture();
        $tables = [
            'attempts', 'attempt_items', 'attempt_responses',
            'regrade_corrections', 'materialized_skill_performances',
            'student_enrollments', 'student_enrollment_transitions',
        ];
        $before = collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        );

        $this->actingAs($fixture['teacher'])
            ->getJson($this->listUri($fixture))
            ->assertOk();
        $this->getJson($this->resultUri($fixture, $fixture['submitted']))
            ->assertOk();

        $after = collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        );
        $this->assertSame($before->all(), $after->all());
    }

    /** @return array<string, mixed> */
    private function fixture(
        string $versionStatus = 'published',
        string $subjectCode = 'mathematics',
        bool $withAttempts = true,
    ): array {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $student = $this->user('student', name: 'طالب النتائج');
        $learner = LearnerProfile::query()->create(['user_id' => $student->id]);
        $subjectId = DB::table('subjects')
            ->where('code', $subjectCode)
            ->where('status', 'active')
            ->value('id');
        $this->assertIsString($subjectId);
        $assignment = app(AssignTeacherSubject::class)->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $subjectId,
            operationId: (string) Str::uuid(),
            reason: 'Teacher attempt API assignment fixture.',
        );
        $curriculum = app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'Attempt monitoring '.Str::uuid(),
            educationStageId: null,
        );
        $version = CurriculumVersion::query()->create([
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'Attempt monitoring v1',
            'status' => 'draft',
        ]);
        $practiceSource = $this->practiceSource($version->id);
        $activityId = $practiceSource['activity_id'];
        $examGenerationId = $this->examSource(
            $version,
            $practiceSource['assessment_item_id'],
            $practiceSource['assessment_item_revision_id'],
        );
        $enrollment = app(RequestStudentEnrollment::class)->execute(
            actorUserId: $student->id,
            learnerProfileId: $learner->id,
            assignmentId: $assignment->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher attempt API request fixture.',
        );
        $enrollment = app(AcceptStudentEnrollment::class)->execute(
            actorUserId: $teacher->id,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher attempt API acceptance fixture.',
        );

        if ($versionStatus !== 'draft') {
            $version->status = 'published';
            $version->save();
        }

        $attempts = [];
        if ($withAttempts) {
            $submitted = app(BuildPracticeAttempt::class)->execute(
                $student->id,
                $learner->id,
                $activityId,
            );
            $items = $submitted->items()->orderBy('presentation_position')->get();
            app(SaveAttemptResponse::class)->execute(
                $student->id,
                $learner->id,
                $items[0]->id,
                ['selected_option' => 1],
                100,
            );
            app(SaveAttemptResponse::class)->execute(
                $student->id,
                $learner->id,
                $items[1]->id,
                ['selected_option' => 2],
                100,
            );
            $submitted = app(FinalizeAttempt::class)->execute(
                $student->id,
                $learner->id,
                $submitted->id,
                'submitted',
            );
            $secondResponse = $items[1]->response()->firstOrFail();
            app(AddRegradeCorrection::class)->execute(
                $secondResponse->id,
                false,
                'Historical correction.',
            );
            app(AddRegradeCorrection::class)->execute(
                $secondResponse->id,
                true,
                'Latest correction wins.',
            );

            $abandoned = app(BuildPracticeAttempt::class)->execute(
                $student->id,
                $learner->id,
                $activityId,
            );
            $abandoned = app(FinalizeAttempt::class)->execute(
                $student->id,
                $learner->id,
                $abandoned->id,
                'abandoned',
            );
            $inProgress = app(BuildPracticeAttempt::class)->execute(
                $student->id,
                $learner->id,
                $activityId,
            );
            $exam = $this->examAttempt(
                $learner,
                $version,
                $examGenerationId,
            );
            $attempts = compact('submitted', 'abandoned', 'inProgress', 'exam');
        }

        if ($versionStatus === 'retired') {
            $version->status = 'retired';
            $version->save();
        }

        return [
            'admin' => $admin,
            'teacher' => $teacher,
            'student' => $student,
            'learner' => $learner,
            'assignment' => $assignment,
            'curriculum' => $curriculum,
            'version' => $version->refresh(),
            'enrollment' => $enrollment,
            ...$attempts,
        ];
    }

    /**
     * @return array{activity_id: string, assessment_item_id: string, assessment_item_revision_id: string}
     */
    private function practiceSource(string $versionId): array
    {
        $topicId = (string) Str::uuid();
        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => 'Attempt topic '.Str::uuid(),
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $skillId = (string) Str::uuid();
        $placementId = (string) Str::uuid();
        DB::table('skills')->insert([
            'id' => $skillId,
            'name' => 'Attempt skill '.Str::uuid(),
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('skill_version_placements')->insert([
            'id' => $placementId,
            'skill_id' => $skillId,
            'curriculum_version_id' => $versionId,
            'created_at' => now(),
        ]);
        $activityId = (string) Str::uuid();
        DB::table('practice_activities')->insert([
            'id' => $activityId,
            'curriculum_version_id' => $versionId,
            'lesson_id' => null,
            'name' => 'Attempt practice '.Str::uuid(),
            'description' => null,
            'status' => 'archived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstItemId = '';
        $firstRevisionId = '';
        foreach (range(0, 2) as $position) {
            $itemId = (string) Str::uuid();
            $revisionId = (string) Str::uuid();
            if ($position === 0) {
                $firstItemId = $itemId;
                $firstRevisionId = $revisionId;
            }
            DB::table('assessment_items')->insert([
                'id' => $itemId,
                'curriculum_version_id' => $versionId,
                'item_type' => 'multiple_choice',
                'internal_label' => 'Attempt item '.Str::uuid(),
                'status' => 'draft',
                'published_revision_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('assessment_item_revisions')->insert([
                'id' => $revisionId,
                'assessment_item_id' => $itemId,
                'curriculum_version_id' => $versionId,
                'revision_number' => 1,
                'primary_topic_id' => $topicId,
                'difficulty' => 'easy',
                'content_payload' => json_encode(['stem' => '1 + 1?', 'options' => [2, 3]], JSON_THROW_ON_ERROR),
                'content_schema_version' => 1,
                'scoring_payload' => json_encode(['correct_option' => 1], JSON_THROW_ON_ERROR),
                'scoring_schema_version' => 1,
                'released_at' => null,
                'created_at' => now(),
            ]);
            DB::table('assessment_item_revision_skills')->insert([
                'id' => (string) Str::uuid(),
                'assessment_item_revision_id' => $revisionId,
                'skill_version_placement_id' => $placementId,
                'curriculum_version_id' => $versionId,
                'role' => 'primary',
                'created_at' => now(),
            ]);
            app(ReleaseAssessmentItemRevision::class)->execute($revisionId);
            DB::table('practice_activity_items')->insert([
                'id' => (string) Str::uuid(),
                'practice_activity_id' => $activityId,
                'assessment_item_revision_id' => $revisionId,
                'assessment_item_id' => $itemId,
                'curriculum_version_id' => $versionId,
                'display_order' => $position,
                'created_at' => now(),
            ]);
        }
        DB::table('practice_activities')->where('id', $activityId)->update([
            'status' => 'active',
            'updated_at' => now(),
        ]);

        return [
            'activity_id' => $activityId,
            'assessment_item_id' => $firstItemId,
            'assessment_item_revision_id' => $firstRevisionId,
        ];
    }

    private function examSource(
        CurriculumVersion $version,
        string $assessmentItemId,
        string $assessmentItemRevisionId,
    ): string {
        $templateId = (string) Str::uuid();
        $templateVersionId = (string) Str::uuid();
        $generationId = (string) Str::uuid();
        DB::table('exam_templates')->insert([
            'id' => $templateId,
            'curriculum_version_id' => $version->id,
            'name' => 'Monitoring exam '.Str::uuid(),
            'description' => null,
            'status' => 'active',
            'published_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('exam_template_versions')->insert([
            'id' => $templateVersionId,
            'exam_template_id' => $templateId,
            'curriculum_version_id' => $version->id,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'published',
            'rules_payload' => json_encode([], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR),
            'rules_schema_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('exam_templates')->where('id', $templateId)->update([
            'published_version_id' => $templateVersionId,
            'updated_at' => now(),
        ]);
        DB::table('exam_generations')->insert([
            'id' => $generationId,
            'exam_template_version_id' => $templateVersionId,
            'curriculum_version_id' => $version->id,
            'rules_snapshot' => json_encode([], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR),
            'rules_schema_version' => 1,
            'generator_version' => 'test',
            'seed' => (string) Str::uuid(),
            'generated_at' => null,
            'created_at' => now(),
        ]);
        DB::table('exam_generation_items')->insert([
            'id' => (string) Str::uuid(),
            'exam_generation_id' => $generationId,
            'assessment_item_revision_id' => $assessmentItemRevisionId,
            'assessment_item_id' => $assessmentItemId,
            'curriculum_version_id' => $version->id,
            'selection_position' => 0,
            'created_at' => now(),
        ]);
        DB::table('exam_generations')->where('id', $generationId)->update([
            'generated_at' => now(),
        ]);

        return $generationId;
    }

    private function examAttempt(
        LearnerProfile $learner,
        CurriculumVersion $version,
        string $generationId,
    ): Attempt {
        $attempt = app(BuildExamAttempt::class)->execute(
            authenticatedUserId: $learner->user_id,
            learnerProfileId: $learner->id,
            examGenerationId: $generationId,
        );
        $attemptItem = $attempt->items()
            ->orderBy('presentation_position')
            ->firstOrFail();

        app(SaveAttemptResponse::class)->execute(
            authenticatedUserId: $learner->user_id,
            learnerProfileId: $learner->id,
            attemptItemId: $attemptItem->id,
            responsePayload: ['selected_option' => 2],
            timeSpentMs: 100,
        );

        return app(FinalizeAttempt::class)->execute(
            authenticatedUserId: $learner->user_id,
            learnerProfileId: $learner->id,
            attemptId: $attempt->id,
            finalStatus: 'submitted',
        );
    }

    private function user(string $role, string $status = 'active', ?string $name = null): User
    {
        return User::factory()->create([
            'name' => $name ?? ucfirst($role).' '.Str::uuid(),
            'role' => $role,
            'status' => $status,
        ]);
    }

    /** @param array<string, scalar> $filters */
    private function listUri(array $fixture, array $filters = [], ?string $versionId = null): string
    {
        return "/api/teacher/monitoring/enrollments/{$fixture['enrollment']->id}/attempts?"
            .http_build_query([
                'curriculum_version_id' => $versionId ?? $fixture['version']->id,
                ...$filters,
            ]);
    }

    private function resultUri(array $fixture, Attempt $attempt): string
    {
        return "/api/teacher/monitoring/enrollments/{$fixture['enrollment']->id}/attempts/{$attempt->id}/result";
    }
}

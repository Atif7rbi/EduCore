<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Learning\PublishLesson;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonRevision;
use App\Models\TeacherSubjectAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TeacherLessonProgressApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authorized_teacher_reads_exact_historical_progress_and_catalog_gaps(): void
    {
        $fixture = $this->fixture();
        $response = $this->actingAs($fixture['teacher'])
            ->getJson($this->uri($fixture))
            ->assertOk()
            ->assertJsonPath('data.enrollment.id', $fixture['enrollment']->id)
            ->assertJsonPath('data.enrollment.status', 'active')
            ->assertJsonPath('data.enrollment.student.user_id', $fixture['student']->id)
            ->assertJsonPath('data.enrollment.student.name', $fixture['student']->name)
            ->assertJsonPath('data.teacher_subject_assignment.id', $fixture['assignment']->id)
            ->assertJsonPath('data.teacher_subject_assignment.subject.code', 'mathematics')
            ->assertJsonPath('data.curriculum.id', $fixture['curriculum']->id)
            ->assertJsonPath('data.curriculum_version.id', $fixture['version']->id)
            ->assertJsonPath('data.curriculum_version.status', 'published')
            ->assertJsonPath('data.recorded_started_count', 2)
            ->assertJsonPath('data.recorded_completed_count', 1)
            ->assertJsonCount(3, 'data.items');

        $items = collect($response->json('data.items'));
        $historical = $items->firstWhere(
            'lesson.id',
            $fixture['historicalLesson']->id,
        );

        $this->assertSame(
            $fixture['historicalRevision']->id,
            data_get($historical, 'lesson_revision.id'),
        );
        $this->assertNotSame(
            $fixture['currentHistoricalRevision']->id,
            data_get($historical, 'lesson_revision.id'),
        );
        $this->assertSame(
            'in_progress',
            data_get($historical, 'progress.status'),
        );
        $this->assertNotNull(data_get($historical, 'progress.started_at'));
        $this->assertNull(data_get($historical, 'progress.completed_at'));

        $completed = $items->firstWhere(
            'lesson.id',
            $fixture['completedLesson']->id,
        );
        $this->assertSame('completed', data_get($completed, 'progress.status'));
        $this->assertNotNull(data_get($completed, 'progress.completed_at'));

        $notRecorded = $items->firstWhere(
            'lesson.id',
            $fixture['notRecordedLesson']->id,
        );
        $this->assertSame(
            $fixture['notRecordedRevision']->id,
            data_get($notRecorded, 'lesson_revision.id'),
        );
        $this->assertSame(
            'not_recorded',
            data_get($notRecorded, 'progress.status'),
        );
        $this->assertNull(data_get($notRecorded, 'progress.started_at'));
        $this->assertNull(data_get($notRecorded, 'progress.completed_at'));
        $this->assertNotContains(
            $fixture['unreleasedLesson']->id,
            $items->pluck('lesson.id')->all(),
        );
    }

    public function test_response_preserves_student_privacy(): void
    {
        $fixture = $this->fixture();
        $response = $this->actingAs($fixture['teacher'])
            ->getJson($this->uri($fixture))
            ->assertOk();
        $encoded = json_encode(
            $response->json(),
            JSON_THROW_ON_ERROR,
        );

        $this->assertStringNotContainsString($fixture['student']->email, $encoded);
        foreach ([
            'learner_profile_id',
            'email',
            'password',
            'grades',
            'attempts',
            'answers',
            'score',
        ] as $privateKey) {
            $this->assertStringNotContainsString('"'.$privateKey.'"', $encoded);
        }
    }

    public function test_foreign_teacher_and_foreign_enrollment_are_not_found(): void
    {
        $fixture = $this->fixture();
        $foreign = $this->fixture('published', 'physics');

        $this->actingAs($foreign['teacher'])
            ->getJson(
                $this->uri($fixture)
                .'&teacher_user_id='.$fixture['teacher']->id,
            )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->actingAs($fixture['teacher'])
            ->getJson(
                $this->uri($foreign, $fixture['version']->id),
            )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_inactive_assignment_invalidates_progress_access(): void
    {
        $fixture = $this->fixture();

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $fixture['admin']->id,
            assignmentId: $fixture['assignment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher progress API inactive assignment fixture.',
        );

        $this->actingAs($fixture['teacher'])
            ->getJson($this->uri($fixture))
            ->assertNotFound();
    }

    public function test_inactive_enrollment_invalidates_progress_access(): void
    {
        $fixture = $this->fixture();

        app(DeactivateStudentEnrollment::class)->execute(
            actorUserId: $fixture['teacher']->id,
            enrollmentId: $fixture['enrollment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher progress API inactive enrollment fixture.',
        );

        $this->actingAs($fixture['teacher'])
            ->getJson($this->uri($fixture))
            ->assertNotFound();
    }

    public function test_disabled_student_invalidates_progress_access(): void
    {
        $fixture = $this->fixture();
        DB::table('users')
            ->where('id', $fixture['student']->id)
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);

        $this->actingAs($fixture['teacher'])
            ->getJson($this->uri($fixture))
            ->assertNotFound();
    }

    public function test_retired_version_is_readable_and_draft_version_is_denied(): void
    {
        $retired = $this->fixture('retired');
        $this->actingAs($retired['teacher'])
            ->getJson($this->uri($retired))
            ->assertOk()
            ->assertJsonPath('data.curriculum_version.status', 'retired');

        $draft = $this->fixture('draft', 'physics');
        $this->actingAs($draft['teacher'])
            ->getJson($this->uri($draft))
            ->assertNotFound();
    }

    public function test_cross_version_progress_and_unreleased_catalog_are_excluded(): void
    {
        $fixture = $this->fixture();
        $foreign = $this->ownedVersion(
            $fixture['admin'],
            $fixture['teacher'],
            'physics',
        );
        $foreignLesson = $this->publishedLesson(
            $foreign['version'],
            $foreign['topic'],
            'Cross-version progress',
            0,
        );
        $this->progress(
            $fixture['learner'],
            $foreignLesson['revision'],
        );
        $foreign['version']->status = 'published';
        $foreign['version']->save();

        $response = $this->actingAs($fixture['teacher'])
            ->getJson($this->uri($fixture))
            ->assertOk();
        $lessonIds = collect($response->json('data.items'))
            ->pluck('lesson.id')
            ->all();

        $this->assertNotContains($foreignLesson['lesson']->id, $lessonIds);
        $this->assertNotContains($fixture['unreleasedLesson']->id, $lessonIds);
        $this->assertSame(2, $response->json('data.recorded_started_count'));
    }

    public function test_authentication_role_active_status_and_validation_are_enforced(): void
    {
        $fixture = $this->fixture();

        $this->getJson($this->uri($fixture))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        foreach (['student', 'admin'] as $role) {
            $this->actingAs(User::factory()->create([
                'role' => $role,
                'status' => 'active',
            ]))
                ->getJson($this->uri($fixture))
                ->assertForbidden()
                ->assertJsonPath('error.code', 'teacher_forbidden');
        }

        $this->actingAs(User::factory()->create([
            'role' => 'teacher',
            'status' => 'disabled',
        ]))
            ->getJson($this->uri($fixture))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_disabled');

        $this->actingAs($fixture['teacher'])
            ->getJson(
                "/api/teacher/monitoring/enrollments/{$fixture['enrollment']->id}/progress",
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_progress_endpoint_rejects_mutating_methods(): void
    {
        $fixture = $this->fixture();

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->actingAs($fixture['teacher'])
                ->{$method}($this->uri($fixture), [])
                ->assertStatus(405);
        }
    }

    public function test_progress_read_does_not_mutate_learning_or_enrollment_data(): void
    {
        $fixture = $this->fixture();
        $tables = [
            'student_enrollments',
            'student_enrollment_transitions',
            'lesson_progresses',
            'lessons',
            'lesson_revisions',
            'attempts',
            'attempt_responses',
            'regrade_corrections',
            'materialized_skill_performances',
        ];
        $before = collect($tables)->mapWithKeys(
            fn (string $table): array => [
                $table => DB::table($table)->count(),
            ],
        );

        $this->actingAs($fixture['teacher'])
            ->getJson($this->uri($fixture))
            ->assertOk();

        $after = collect($tables)->mapWithKeys(
            fn (string $table): array => [
                $table => DB::table($table)->count(),
            ],
        );
        $this->assertSame($before->all(), $after->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(
        string $versionStatus = 'published',
        string $subjectCode = 'mathematics',
    ): array {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $student = $this->user('student', name: 'طالب المتابعة');
        $learner = LearnerProfile::query()->create([
            'user_id' => $student->id,
        ]);
        $owned = $this->ownedVersion(
            $admin,
            $teacher,
            $subjectCode,
        );
        $historical = $this->publishedLesson(
            $owned['version'],
            $owned['topic'],
            'Historical lesson',
            0,
        );
        $completed = $this->publishedLesson(
            $owned['version'],
            $owned['topic'],
            'Completed lesson',
            1,
        );
        $notRecorded = $this->publishedLesson(
            $owned['version'],
            $owned['topic'],
            'Not recorded lesson',
            2,
        );
        $unreleasedLesson = Lesson::query()->create([
            'curriculum_version_id' => $owned['version']->id,
            'title' => 'Unreleased lesson',
            'description' => null,
            'status' => 'draft',
            'display_order' => 3,
            'published_revision_id' => null,
        ]);
        LessonRevision::query()->create([
            'lesson_id' => $unreleasedLesson->id,
            'curriculum_version_id' => $owned['version']->id,
            'revision_number' => 1,
            'primary_topic_id' => $owned['topic']->id,
            'content_payload' => ['blocks' => []],
            'content_schema_version' => 1,
            'released_at' => null,
        ]);
        $historicalProgress = $this->progress(
            $learner,
            $historical['revision'],
        );
        $completedProgress = $this->progress(
            $learner,
            $completed['revision'],
            true,
        );
        $currentHistoricalRevision = $this->revision(
            $historical['lesson'],
            $owned['topic'],
            2,
            true,
        );
        $historical['lesson']->published_revision_id = $currentHistoricalRevision->id;
        $historical['lesson']->save();

        if ($versionStatus !== 'draft') {
            $owned['version']->status = 'published';
            $owned['version']->save();

            if ($versionStatus === 'retired') {
                $owned['version']->status = 'retired';
                $owned['version']->save();
            }
        }

        $enrollment = app(RequestStudentEnrollment::class)->execute(
            actorUserId: $student->id,
            learnerProfileId: $learner->id,
            assignmentId: $owned['assignment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher progress API request fixture.',
        );
        $enrollment = app(AcceptStudentEnrollment::class)->execute(
            actorUserId: $teacher->id,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher progress API acceptance fixture.',
        );

        return [
            'admin' => $admin,
            'teacher' => $teacher,
            'student' => $student,
            'learner' => $learner,
            'assignment' => $owned['assignment'],
            'curriculum' => $owned['curriculum'],
            'version' => $owned['version']->refresh(),
            'enrollment' => $enrollment,
            'historicalLesson' => $historical['lesson'],
            'historicalRevision' => $historical['revision'],
            'currentHistoricalRevision' => $currentHistoricalRevision,
            'completedLesson' => $completed['lesson'],
            'completedRevision' => $completed['revision'],
            'notRecordedLesson' => $notRecorded['lesson'],
            'notRecordedRevision' => $notRecorded['revision'],
            'unreleasedLesson' => $unreleasedLesson,
            'historicalProgress' => $historicalProgress,
            'completedProgress' => $completedProgress,
        ];
    }

    /**
     * @return array{assignment: TeacherSubjectAssignment, curriculum: Curriculum, version: CurriculumVersion, topic: Topic}
     */
    private function ownedVersion(
        User $admin,
        User $teacher,
        string $subjectCode,
    ): array {
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
            reason: 'Teacher progress API assignment fixture.',
        );
        $curriculum = app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'Teacher progress curriculum '.Str::uuid(),
            educationStageId: null,
        );
        $version = CurriculumVersion::query()->create([
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'Monitoring v1',
            'status' => 'draft',
        ]);
        $topic = Topic::query()->create([
            'curriculum_version_id' => $version->id,
            'name' => 'Monitoring topic '.Str::uuid(),
            'description' => null,
            'display_order' => 0,
        ]);

        return compact('assignment', 'curriculum', 'version', 'topic');
    }

    /**
     * @return array{lesson: Lesson, revision: LessonRevision}
     */
    private function publishedLesson(
        CurriculumVersion $version,
        Topic $topic,
        string $title,
        int $displayOrder,
    ): array {
        $lesson = Lesson::query()->create([
            'curriculum_version_id' => $version->id,
            'title' => $title,
            'description' => null,
            'status' => 'draft',
            'display_order' => $displayOrder,
            'published_revision_id' => null,
        ]);
        $revision = $this->revision($lesson, $topic, 1, true);
        $lesson = app(PublishLesson::class)->execute(
            $lesson->id,
            $revision->id,
        );

        return compact('lesson', 'revision');
    }

    private function revision(
        Lesson $lesson,
        Topic $topic,
        int $number,
        bool $release,
    ): LessonRevision {
        $revision = LessonRevision::query()->create([
            'lesson_id' => $lesson->id,
            'curriculum_version_id' => $lesson->curriculum_version_id,
            'revision_number' => $number,
            'primary_topic_id' => $topic->id,
            'content_payload' => ['blocks' => []],
            'content_schema_version' => 1,
            'released_at' => null,
        ]);

        return $release
            ? app(ReleaseLessonRevision::class)->execute($revision->id)
            : $revision;
    }

    private function progress(
        LearnerProfile $learner,
        LessonRevision $revision,
        bool $complete = false,
    ): LessonProgress {
        $progress = LessonProgress::query()->create([
            'learner_profile_id' => $learner->id,
            'lesson_revision_id' => $revision->id,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
        ]);

        if ($complete) {
            DB::table('lesson_progresses')
                ->where('id', $progress->id)
                ->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            $progress->refresh();
        }

        return $progress;
    }

    private function user(
        string $role,
        string $status = 'active',
        ?string $name = null,
    ): User {
        return User::factory()->create([
            'name' => $name ?? ucfirst($role).' '.Str::uuid(),
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function uri(
        array $fixture,
        ?string $curriculumVersionId = null,
    ): string {
        return "/api/teacher/monitoring/enrollments/{$fixture['enrollment']->id}/progress"
            .'?curriculum_version_id='
            .($curriculumVersionId ?? $fixture['version']->id);
    }
}

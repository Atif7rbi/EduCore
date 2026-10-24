<?php

namespace Tests\Feature;

use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Application\TeacherMonitoring\TeacherMonitoringContext;
use App\Application\TeacherMonitoring\TeacherMonitoringScope;
use App\Models\Attempt;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonRevision;
use App\Models\PracticeActivity;
use App\Models\TeacherSubjectAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TeacherMonitoringScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_teacher_resolves_own_active_student_and_published_version(): void
    {
        $fixture = $this->monitoringFixture();

        $scope = $this->resolve($fixture);

        $this->assertInstanceOf(TeacherMonitoringContext::class, $scope);
        $this->assertSame($fixture['teacher']->id, $scope->teacherUserId);
        $this->assertSame($fixture['student']->id, $scope->studentUserId);
        $this->assertSame($fixture['learner']->id, $scope->learnerProfileId);
        $this->assertSame($fixture['enrollment']->id, $scope->studentEnrollmentId);
        $this->assertSame($fixture['assignment']->id, $scope->teacherSubjectAssignmentId);
        $this->assertSame($fixture['assignment']->subject_id, $scope->subjectId);
        $this->assertSame($fixture['curriculum']->id, $scope->curriculumId);
        $this->assertSame($fixture['version']->id, $scope->curriculumVersionId);
    }

    public function test_retired_version_is_authorized_while_current_relationships_remain_active(): void
    {
        $fixture = $this->monitoringFixture('retired');

        $this->assertSame(
            $fixture['version']->id,
            $this->resolve($fixture)->curriculumVersionId,
        );
    }

    public function test_draft_version_is_rejected(): void
    {
        $this->expectScopeNotFound(
            $this->monitoringFixture('draft'),
        );
    }

    public function test_foreign_teacher_is_rejected_without_disclosing_the_scope(): void
    {
        $fixture = $this->monitoringFixture();
        $fixture['teacher'] = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->expectScopeNotFound($fixture);
    }

    public function test_non_teacher_and_disabled_teacher_are_forbidden(): void
    {
        foreach ([
            ['role' => 'student', 'status' => 'active'],
            ['role' => 'admin', 'status' => 'active'],
            ['role' => 'teacher', 'status' => 'disabled'],
        ] as $identity) {
            $fixture = $this->monitoringFixture();
            $fixture['teacher'] = User::factory()->create($identity);

            try {
                $this->resolve($fixture);
                $this->fail('An ineligible principal resolved a monitoring scope.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_disabled_student_is_rejected(): void
    {
        $fixture = $this->monitoringFixture();

        DB::table('users')
            ->where('id', $fixture['student']->id)
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);

        $this->expectScopeNotFound($fixture);
    }

    public function test_inactive_assignment_is_rejected(): void
    {
        $fixture = $this->monitoringFixture();

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $fixture['admin']->id,
            assignmentId: $fixture['assignment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher monitoring inactive assignment fixture.',
        );

        $this->expectScopeNotFound($fixture);
    }

    public function test_inactive_enrollment_is_rejected(): void
    {
        $fixture = $this->monitoringFixture();

        app(DeactivateStudentEnrollment::class)->execute(
            actorUserId: $fixture['teacher']->id,
            enrollmentId: $fixture['enrollment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher monitoring inactive enrollment fixture.',
        );

        $this->expectScopeNotFound($fixture);
    }

    public function test_foreign_enrollment_is_rejected(): void
    {
        $fixture = $this->monitoringFixture();
        $foreign = $this->monitoringFixture(
            subjectCode: 'physics',
        );

        $fixture['enrollment'] = $foreign['enrollment'];
        $fixture['student'] = $foreign['student'];

        $this->expectScopeNotFound($fixture);
    }

    public function test_foreign_curriculum_version_is_rejected(): void
    {
        $fixture = $this->monitoringFixture();
        $foreign = $this->monitoringFixture(
            subjectCode: 'physics',
        );

        $fixture['version'] = $foreign['version'];

        $this->expectScopeNotFound($fixture);
    }

    public function test_finalized_attempt_scope_rejects_other_students_and_in_progress_attempts(): void
    {
        $fixture = $this->monitoringFixture();
        $scope = $this->resolve($fixture);
        $authorized = $this->attempt(
            $fixture['learner'],
            $fixture['practiceActivity'],
            'submitted',
        );
        $otherStudent = $this->student();
        $foreign = $this->attempt(
            $otherStudent['learner'],
            $fixture['practiceActivity'],
            'submitted',
        );
        $inProgress = $this->attempt(
            $fixture['learner'],
            $fixture['practiceActivity'],
            'in_progress',
        );

        $ids = app(TeacherMonitoringScope::class)
            ->finalizedAttempts(Attempt::query(), $fixture['teacher']->id, $scope)
            ->pluck('id')
            ->all();

        $this->assertContains($authorized->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertNotContains($inProgress->id, $ids);
    }

    public function test_finalized_attempt_scope_rejects_cross_assignment_version(): void
    {
        $fixture = $this->monitoringFixture();
        $scope = $this->resolve($fixture);
        $other = $this->ownedVersionForTeacher(
            $fixture['admin'],
            $fixture['teacher'],
            'physics',
        );
        $foreign = $this->attempt(
            $fixture['learner'],
            $other['practiceActivity'],
            'abandoned',
        );

        $ids = app(TeacherMonitoringScope::class)
            ->finalizedAttempts(Attempt::query(), $fixture['teacher']->id, $scope)
            ->pluck('id')
            ->all();

        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_lesson_progress_scope_requires_exact_student_and_version_provenance(): void
    {
        $fixture = $this->monitoringFixture();
        $scope = $this->resolve($fixture);
        $authorized = $this->lessonProgress(
            $fixture['learner'],
            $fixture['lessonRevision'],
        );
        $otherStudent = $this->student();
        $foreignStudent = $this->lessonProgress(
            $otherStudent['learner'],
            $fixture['lessonRevision'],
        );
        $other = $this->ownedVersionForTeacher(
            $fixture['admin'],
            $fixture['teacher'],
            'physics',
        );
        $foreignVersion = $this->lessonProgress(
            $fixture['learner'],
            $other['lessonRevision'],
        );

        $ids = app(TeacherMonitoringScope::class)
            ->lessonProgresses(LessonProgress::query(), $fixture['teacher']->id, $scope)
            ->pluck('id')
            ->all();

        $this->assertContains($authorized->id, $ids);
        $this->assertNotContains($foreignStudent->id, $ids);
        $this->assertNotContains($foreignVersion->id, $ids);
    }

    public function test_assignment_deactivation_invalidates_a_previously_resolved_context(): void
    {
        $fixture = $this->monitoringFixture();
        $scope = $this->resolve($fixture);
        $attempt = $this->attempt(
            $fixture['learner'],
            $fixture['practiceActivity'],
            'submitted',
        );
        $progress = $this->lessonProgress(
            $fixture['learner'],
            $fixture['lessonRevision'],
        );

        app(DeactivateTeacherSubjectAssignment::class)->execute(
            actorUserId: $fixture['admin']->id,
            assignmentId: $fixture['assignment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher monitoring stale assignment context fixture.',
        );

        $this->assertResourceQueriesEmpty(
            $fixture['teacher']->id,
            $scope,
            $attempt,
            $progress,
        );
    }

    public function test_enrollment_deactivation_invalidates_a_previously_resolved_context(): void
    {
        $fixture = $this->monitoringFixture();
        $scope = $this->resolve($fixture);
        $attempt = $this->attempt(
            $fixture['learner'],
            $fixture['practiceActivity'],
            'submitted',
        );
        $progress = $this->lessonProgress(
            $fixture['learner'],
            $fixture['lessonRevision'],
        );

        app(DeactivateStudentEnrollment::class)->execute(
            actorUserId: $fixture['teacher']->id,
            enrollmentId: $fixture['enrollment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher monitoring stale enrollment context fixture.',
        );

        $this->assertResourceQueriesEmpty(
            $fixture['teacher']->id,
            $scope,
            $attempt,
            $progress,
        );
    }

    public function test_forged_context_identifiers_cannot_authorize_resources(): void
    {
        $fixture = $this->monitoringFixture();
        $foreign = $this->monitoringFixture(subjectCode: 'physics');
        $scope = $this->resolve($fixture);
        $attempt = $this->attempt(
            $fixture['learner'],
            $fixture['practiceActivity'],
            'submitted',
        );
        $progress = $this->lessonProgress(
            $fixture['learner'],
            $fixture['lessonRevision'],
        );
        $forgeries = [
            $this->contextWith($scope, teacherUserId: $foreign['teacher']->id),
            $this->contextWith($scope, teacherSubjectAssignmentId: $foreign['assignment']->id),
            $this->contextWith($scope, studentEnrollmentId: $foreign['enrollment']->id),
            $this->contextWith(
                $scope,
                studentUserId: $foreign['student']->id,
                learnerProfileId: $foreign['learner']->id,
            ),
            $this->contextWith($scope, curriculumVersionId: $foreign['version']->id),
        ];

        foreach ($forgeries as $forged) {
            $this->assertResourceQueriesEmpty(
                $fixture['teacher']->id,
                $forged,
                $attempt,
                $progress,
            );
        }
    }

    public function test_resource_queries_reject_a_mismatched_authenticated_teacher(): void
    {
        $fixture = $this->monitoringFixture();
        $scope = $this->resolve($fixture);
        $foreignTeacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $attempt = $this->attempt(
            $fixture['learner'],
            $fixture['practiceActivity'],
            'submitted',
        );
        $progress = $this->lessonProgress(
            $fixture['learner'],
            $fixture['lessonRevision'],
        );

        $this->assertResourceQueriesEmpty(
            $foreignTeacher->id,
            $scope,
            $attempt,
            $progress,
        );
    }

    public function test_unreleased_lesson_revision_is_excluded(): void
    {
        $fixture = $this->monitoringFixture();
        $scope = $this->resolve($fixture);
        $syntheticProgress = DB::query()->selectRaw(
            'CAST(? AS uuid) AS learner_profile_id, '
            .'CAST(? AS uuid) AS lesson_revision_id',
            [
                $fixture['learner']->id,
                $fixture['unreleasedLessonRevision']->id,
            ],
        );

        $this->assertFalse(
            app(TeacherMonitoringScope::class)
                ->lessonProgresses(
                    LessonProgress::query()->fromSub(
                        $syntheticProgress,
                        'lesson_progresses',
                    ),
                    $fixture['teacher']->id,
                    $scope,
                )
                ->exists(),
        );
    }

    public function test_scope_evaluation_has_no_persistent_side_effects(): void
    {
        $fixture = $this->monitoringFixture();
        $attempt = $this->attempt(
            $fixture['learner'],
            $fixture['practiceActivity'],
            'submitted',
        );
        $progress = $this->lessonProgress(
            $fixture['learner'],
            $fixture['lessonRevision'],
        );
        $tables = [
            'student_enrollments',
            'student_enrollment_transitions',
            'lesson_progresses',
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

        $scope = $this->resolve($fixture);

        app(TeacherMonitoringScope::class)
            ->finalizedAttempts(
                Attempt::query()->whereKey($attempt->id),
                $fixture['teacher']->id,
                $scope,
            )
            ->firstOrFail();

        app(TeacherMonitoringScope::class)
            ->lessonProgresses(
                LessonProgress::query()->whereKey($progress->id),
                $fixture['teacher']->id,
                $scope,
            )
            ->firstOrFail();

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
    private function monitoringFixture(
        string $versionStatus = 'published',
        string $subjectCode = 'mathematics',
    ): array {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $teacher = User::factory()->create([
            'role' => 'teacher',
            'status' => 'active',
        ]);
        $studentIdentity = $this->student();
        $owned = $this->ownedVersionForTeacher(
            $admin,
            $teacher,
            $subjectCode,
            $versionStatus,
        );
        $enrollment = app(RequestStudentEnrollment::class)->execute(
            actorUserId: $studentIdentity['student']->id,
            learnerProfileId: $studentIdentity['learner']->id,
            assignmentId: $owned['assignment']->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher monitoring request fixture.',
        );
        $enrollment = app(AcceptStudentEnrollment::class)->execute(
            actorUserId: $teacher->id,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Teacher monitoring acceptance fixture.',
        );

        return [
            'admin' => $admin,
            'teacher' => $teacher,
            'student' => $studentIdentity['student'],
            'learner' => $studentIdentity['learner'],
            'assignment' => $owned['assignment'],
            'curriculum' => $owned['curriculum'],
            'version' => $owned['version'],
            'practiceActivity' => $owned['practiceActivity'],
            'lessonRevision' => $owned['lessonRevision'],
            'unreleasedLessonRevision' => $owned['unreleasedLessonRevision'],
            'enrollment' => $enrollment,
        ];
    }

    /**
     * @return array{student: User, learner: LearnerProfile}
     */
    private function student(): array
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        return [
            'student' => $student,
            'learner' => LearnerProfile::query()->create([
                'user_id' => $student->id,
            ]),
        ];
    }

    /**
     * @return array{
     *     assignment: TeacherSubjectAssignment,
     *     curriculum: Curriculum,
     *     version: CurriculumVersion,
     *     practiceActivity: PracticeActivity,
     *     lessonRevision: LessonRevision,
     *     unreleasedLessonRevision: LessonRevision
     * }
     */
    private function ownedVersionForTeacher(
        User $admin,
        User $teacher,
        string $subjectCode,
        string $versionStatus = 'published',
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
            reason: 'Teacher monitoring assignment fixture.',
        );
        $curriculum = app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignment->id,
            name: 'Teacher monitoring curriculum '.Str::uuid(),
            educationStageId: null,
        );
        $version = CurriculumVersion::query()->create([
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'label' => 'Monitoring v1',
            'status' => 'draft',
        ]);

        $activity = PracticeActivity::query()->create([
            'curriculum_version_id' => $version->id,
            'lesson_id' => null,
            'name' => 'Monitoring practice '.Str::uuid(),
            'description' => null,
            'status' => 'active',
        ]);
        $topic = Topic::query()->create([
            'curriculum_version_id' => $version->id,
            'name' => 'Monitoring topic '.Str::uuid(),
            'description' => null,
            'display_order' => 0,
        ]);
        $lesson = Lesson::query()->create([
            'curriculum_version_id' => $version->id,
            'title' => 'Monitoring lesson '.Str::uuid(),
            'description' => null,
            'status' => 'draft',
            'display_order' => 0,
            'published_revision_id' => null,
        ]);
        $revision = LessonRevision::query()->create([
            'lesson_id' => $lesson->id,
            'curriculum_version_id' => $version->id,
            'revision_number' => 1,
            'primary_topic_id' => $topic->id,
            'content_payload' => ['blocks' => []],
            'content_schema_version' => 1,
            'released_at' => null,
        ]);
        $revision->released_at = now();
        $revision->save();

        $unreleasedLesson = Lesson::query()->create([
            'curriculum_version_id' => $version->id,
            'title' => 'Unreleased monitoring lesson '.Str::uuid(),
            'description' => null,
            'status' => 'draft',
            'display_order' => 1,
            'published_revision_id' => null,
        ]);
        $unreleasedRevision = LessonRevision::query()->create([
            'lesson_id' => $unreleasedLesson->id,
            'curriculum_version_id' => $version->id,
            'revision_number' => 1,
            'primary_topic_id' => $topic->id,
            'content_payload' => ['blocks' => []],
            'content_schema_version' => 1,
            'released_at' => null,
        ]);

        if ($versionStatus !== 'draft') {
            $version->status = 'published';
            $version->save();

            if ($versionStatus === 'retired') {
                $version->status = 'retired';
                $version->save();
            }
        }

        return [
            'assignment' => $assignment,
            'curriculum' => $curriculum,
            'version' => $version->refresh(),
            'practiceActivity' => $activity,
            'lessonRevision' => $revision->refresh(),
            'unreleasedLessonRevision' => $unreleasedRevision,
        ];
    }

    private function resolve(array $fixture): TeacherMonitoringContext
    {
        return app(TeacherMonitoringScope::class)->resolve(
            authenticatedTeacherUserId: $fixture['teacher']->id,
            teacherSubjectAssignmentId: $fixture['assignment']->id,
            studentEnrollmentId: $fixture['enrollment']->id,
            studentUserId: $fixture['student']->id,
            curriculumVersionId: $fixture['version']->id,
        );
    }

    private function expectScopeNotFound(array $fixture): void
    {
        try {
            $this->resolve($fixture);
            $this->fail('An out-of-scope monitoring relationship resolved.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function contextWith(
        TeacherMonitoringContext $scope,
        ?string $teacherUserId = null,
        ?string $studentUserId = null,
        ?string $learnerProfileId = null,
        ?string $studentEnrollmentId = null,
        ?string $teacherSubjectAssignmentId = null,
        ?string $subjectId = null,
        ?string $curriculumId = null,
        ?string $curriculumVersionId = null,
    ): TeacherMonitoringContext {
        return new TeacherMonitoringContext(
            teacherUserId: $teacherUserId ?? $scope->teacherUserId,
            studentUserId: $studentUserId ?? $scope->studentUserId,
            learnerProfileId: $learnerProfileId ?? $scope->learnerProfileId,
            studentEnrollmentId: $studentEnrollmentId ?? $scope->studentEnrollmentId,
            teacherSubjectAssignmentId: $teacherSubjectAssignmentId
                ?? $scope->teacherSubjectAssignmentId,
            subjectId: $subjectId ?? $scope->subjectId,
            curriculumId: $curriculumId ?? $scope->curriculumId,
            curriculumVersionId: $curriculumVersionId
                ?? $scope->curriculumVersionId,
        );
    }

    private function assertResourceQueriesEmpty(
        string $authenticatedTeacherUserId,
        TeacherMonitoringContext $scope,
        Attempt $attempt,
        LessonProgress $progress,
    ): void {
        $this->assertFalse(
            app(TeacherMonitoringScope::class)
                ->finalizedAttempts(
                    Attempt::query()->whereKey($attempt->id),
                    $authenticatedTeacherUserId,
                    $scope,
                )
                ->exists(),
        );
        $this->assertFalse(
            app(TeacherMonitoringScope::class)
                ->lessonProgresses(
                    LessonProgress::query()->whereKey($progress->id),
                    $authenticatedTeacherUserId,
                    $scope,
                )
                ->exists(),
        );
    }

    private function attempt(
        LearnerProfile $learner,
        PracticeActivity $activity,
        string $status,
    ): Attempt {
        $attempt = Attempt::query()->create([
            'learner_profile_id' => $learner->id,
            'exam_generation_id' => null,
            'practice_activity_id' => $activity->id,
            'curriculum_version_id' => $activity->curriculum_version_id,
            'status' => 'in_progress',
            'started_at' => null,
            'finalized_at' => null,
        ]);
        $attempt->started_at = now();
        $attempt->save();

        if ($status !== 'in_progress') {
            $attempt->status = $status;
            $attempt->finalized_at = now();
            $attempt->save();
        }

        return $attempt->refresh();
    }

    private function lessonProgress(
        LearnerProfile $learner,
        LessonRevision $revision,
    ): LessonProgress {
        return LessonProgress::query()->create([
            'learner_profile_id' => $learner->id,
            'lesson_revision_id' => $revision->id,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }
}

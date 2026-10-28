<?php

namespace Tests\Feature;

use App\Application\AdminDashboard\ReadAdminDashboardSummary;
use App\Application\Assessment\PublishAssessmentItem;
use App\Application\Assessment\ReleaseAssessmentItemRevision;
use App\Application\Curriculum\CreateOwnedCurriculum;
use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeclineStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Learning\PublishLesson;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Taxonomy\CreateSkill;
use App\Application\TeacherAssignment\AssignTeacherSubject;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Application\TeacherAuthoring\ManageTeacherCurriculumAuthoring;
use App\Application\TeacherAuthoring\ManageTeacherLessonAssessmentAuthoring;
use App\Application\TeacherAuthoring\ManageTeacherPracticeExamAuthoring;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'array',
        ]);

        $this->connection = DB::connection();
        $this->assertSafeTestDatabase($this->connection);
        $this->assertSame(0, $this->connection->transactionLevel());

        $this->connection->beginTransaction();
        $this->connection->statement(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
        );
    }

    protected function tearDown(): void
    {
        while (
            isset($this->connection)
            && $this->connection->transactionLevel() > 0
        ) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function test_dashboard_enforces_admin_authorization(): void
    {
        $teacher = $this->user('teacher');
        $student = $this->user('student');
        $disabledAdmin = $this->user('admin', 'disabled');
        $admin = $this->user('admin');

        $this->freeze();

        $this->getJson('/api/admin/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        foreach ([$teacher, $student] as $wrongRole) {
            $this->actingAs($wrongRole)
                ->getJson('/api/admin/dashboard')
                ->assertStatus(403)
                ->assertJsonPath(
                    'error.code',
                    'management_forbidden'
                );
        }

        $this->actingAs($disabledAdmin)
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'account_disabled');

        $this->actingAs($admin)
            ->getJson('/api/admin/dashboard')
            ->assertOk();
    }

    public function test_dashboard_returns_zero_operational_populations(): void
    {
        $this->assertSame(
            0,
            $this->connection->table('users')
                ->whereIn('role', ['teacher', 'student'])
                ->count()
        );
        $this->assertSame(
            0,
            $this->connection
                ->table('teacher_subject_assignments')
                ->count()
        );
        $this->assertSame(
            0,
            $this->connection
                ->table('student_enrollments')
                ->count()
        );

        $admin = $this->user('admin');
        $this->freeze();

        $this->actingAs($admin)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath(
                'data.operations.teacher_accounts',
                ['active' => 0, 'disabled' => 0, 'total' => 0]
            )
            ->assertJsonPath(
                'data.operations.student_accounts',
                ['active' => 0, 'disabled' => 0, 'total' => 0]
            )
            ->assertJsonPath(
                'data.operations.teacher_subject_assignments',
                ['active' => 0, 'inactive' => 0, 'total' => 0]
            )
            ->assertJsonPath(
                'data.operations.student_enrollments',
                [
                    'pending' => 0,
                    'active' => 0,
                    'inactive' => 0,
                    'total' => 0,
                ]
            );
    }

    public function test_dashboard_returns_compatible_counts_and_raw_operational_buckets(): void
    {
        $admin = $this->user('admin');
        $this->user('admin');
        $activeTeacher = $this->user('teacher');
        $inactiveAssignmentTeacher = $this->user('teacher');
        $this->user('teacher', 'disabled');

        $pendingStudent = $this->student();
        $activeStudent = $this->student();
        $inactiveStudent = $this->student();
        $studentWithoutProfile = $this->student(false);
        $this->student(false, 'disabled');

        $subjectId = $this->canonicalSubjectId();
        $activeAssignment = app(AssignTeacherSubject::class)
            ->execute(
                actorUserId: $admin->id,
                teacherUserId: $activeTeacher->id,
                subjectId: $subjectId,
                operationId: (string) Str::uuid(),
                reason: 'Dashboard active assignment fixture',
            );
        $inactiveAssignment = app(AssignTeacherSubject::class)
            ->execute(
                actorUserId: $admin->id,
                teacherUserId: $inactiveAssignmentTeacher->id,
                subjectId: $subjectId,
                operationId: (string) Str::uuid(),
                reason: 'Dashboard inactive assignment fixture',
            );

        app(DeactivateTeacherSubjectAssignment::class)
            ->execute(
                actorUserId: $admin->id,
                assignmentId: $inactiveAssignment->id,
                operationId: (string) Str::uuid(),
                reason: 'Dashboard inactive assignment state',
            );

        $pendingEnrollment = $this->requestEnrollment(
            $pendingStudent['user'],
            $pendingStudent['profile'],
            $activeAssignment->id
        );
        $activeEnrollment = $this->requestEnrollment(
            $activeStudent['user'],
            $activeStudent['profile'],
            $activeAssignment->id
        );
        $inactiveEnrollment = $this->requestEnrollment(
            $inactiveStudent['user'],
            $inactiveStudent['profile'],
            $activeAssignment->id
        );

        app(AcceptStudentEnrollment::class)->execute(
            actorUserId: $activeTeacher->id,
            enrollmentId: $activeEnrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Dashboard active enrollment state',
        );
        app(DeclineStudentEnrollment::class)->execute(
            actorUserId: $activeTeacher->id,
            enrollmentId: $inactiveEnrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Dashboard inactive enrollment state',
        );

        app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $activeTeacher->id,
            teacherSubjectAssignmentId: $activeAssignment->id,
            name: 'Dashboard Snapshot '.Str::uuid(),
            educationStageId: null,
        );

        $this->assertSame('pending', $pendingEnrollment->status);
        $this->assertNull($studentWithoutProfile['profile']);

        $expectedCounts = $this->expectedCounts();
        $expectedReadiness = $this->expectedReadiness();
        $before = $this->tableCounts();

        $this->freeze();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'counts',
                    'readiness',
                    'snapshot_at',
                    'operations' => [
                        'teacher_accounts' => [
                            'active',
                            'disabled',
                            'total',
                        ],
                        'student_accounts' => [
                            'active',
                            'disabled',
                            'total',
                        ],
                        'teacher_subject_assignments' => [
                            'active',
                            'inactive',
                            'total',
                        ],
                        'student_enrollments' => [
                            'pending',
                            'active',
                            'inactive',
                            'total',
                        ],
                    ],
                ],
            ]);

        foreach ($expectedCounts as $key => $value) {
            $response->assertJsonPath("data.counts.{$key}", $value);
        }

        foreach ($expectedReadiness as $key => $value) {
            $response->assertJsonPath("data.readiness.{$key}", $value);
        }

        $response
            ->assertJsonPath(
                'data.operations.teacher_accounts',
                ['active' => 2, 'disabled' => 1, 'total' => 3]
            )
            ->assertJsonPath(
                'data.operations.student_accounts',
                ['active' => 4, 'disabled' => 1, 'total' => 5]
            )
            ->assertJsonPath(
                'data.operations.teacher_subject_assignments',
                ['active' => 1, 'inactive' => 1, 'total' => 2]
            )
            ->assertJsonPath(
                'data.operations.student_enrollments',
                [
                    'pending' => 1,
                    'active' => 1,
                    'inactive' => 1,
                    'total' => 3,
                ]
            )
            ->assertJsonPath('data.counts.learners', 3)
            ->assertJsonPath('data.counts.teacher_owned_curricula', 1)
            ->assertJsonPath('data.counts.legacy_ownerless_curricula', 0);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/',
            (string) $response->json('data.snapshot_at')
        );
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_dashboard_reports_nonzero_teacher_owned_readiness_and_excludes_draft_versions(): void
    {
        $this->assertSame(0, $this->connection->table('curricula')->count());
        $this->assertSame(
            0,
            $this->connection->table('curriculum_versions')->count()
        );

        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $assignment = app(AssignTeacherSubject::class)->execute(
            actorUserId: $admin->id,
            teacherUserId: $teacher->id,
            subjectId: $this->canonicalSubjectId(),
            operationId: (string) Str::uuid(),
            reason: 'Dashboard readiness fixture',
        );

        $fixture = $this->publishingReadyTeacherCurriculum(
            $admin,
            $teacher,
            $assignment->id,
        );

        $this->assertSame('published', $fixture['published_status']);
        $this->assertSame('draft', $fixture['draft_status']);
        $this->assertSame(
            2,
            $this->connection->table('curriculum_versions')->count()
        );

        $this->freeze();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.counts.curricula', 1)
            ->assertJsonPath('data.counts.teacher_owned_curricula', 1)
            ->assertJsonPath('data.counts.legacy_ownerless_curricula', 0)
            ->assertJsonPath('data.counts.curriculum_versions', 2)
            ->assertJsonPath(
                'data.readiness.published_curriculum_versions',
                1
            )
            ->assertJsonPath(
                'data.readiness.published_teacher_owned_curriculum_versions',
                1
            )
            ->assertJsonPath(
                'data.readiness.published_legacy_ownerless_curriculum_versions',
                0
            )
            ->assertJsonPath('data.readiness.published_lessons', 1)
            ->assertJsonPath(
                'data.readiness.active_practice_activities',
                1
            )
            ->assertJsonPath('data.readiness.active_exam_templates', 1);

        $published = (int) $response->json(
            'data.readiness.published_curriculum_versions'
        );
        $teacherOwned = (int) $response->json(
            'data.readiness.published_teacher_owned_curriculum_versions'
        );
        $legacy = (int) $response->json(
            'data.readiness.published_legacy_ownerless_curriculum_versions'
        );

        $this->assertSame($published, $teacherOwned + $legacy);
    }

    public function test_dashboard_query_failure_returns_no_partial_summary(): void
    {
        $admin = $this->user('admin');
        $this->app->instance(
            ReadAdminDashboardSummary::class,
            new class extends ReadAdminDashboardSummary
            {
                public function read(Connection $connection): array
                {
                    throw new RuntimeException(
                        'Forced dashboard summary failure.'
                    );
                }
            }
        );
        $this->freeze();

        $this->actingAs($admin)
            ->getJson('/api/admin/dashboard')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error')
            ->assertJsonMissingPath('data');
    }

    private function publishingReadyTeacherCurriculum(
        User $admin,
        User $teacher,
        string $assignmentId,
    ): array {
        $curriculum = app(CreateOwnedCurriculum::class)->execute(
            actorUserId: $teacher->id,
            teacherSubjectAssignmentId: $assignmentId,
            name: 'Dashboard Ready '.Str::uuid(),
            educationStageId: null,
        );

        $curriculumAuthoring = app(
            ManageTeacherCurriculumAuthoring::class
        );
        $publishedVersion = $curriculumAuthoring->createVersion(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            1,
            'Published v1',
        );
        $draftVersion = $curriculumAuthoring->createVersion(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            2,
            'Draft v2',
        );
        $topic = $curriculumAuthoring->createTopic(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            'Readiness topic',
            0,
        );
        $skill = app(CreateSkill::class)->execute(
            $admin->id,
            'Readiness skill '.Str::uuid(),
            null,
        );
        $placement = $curriculumAuthoring->createPlacement(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $skill->id,
        );

        $lessonAuthoring = app(
            ManageTeacherLessonAssessmentAuthoring::class
        );
        $lesson = $lessonAuthoring->createLesson(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            'Readiness lesson',
            null,
            0,
        );
        $lessonRevision = $lessonAuthoring->createLessonRevision(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $lesson->id,
            1,
            $topic->id,
            ['blocks' => [['type' => 'text', 'text' => 'Ready']]],
            1,
        );
        $lessonAuthoring->createLessonRevisionSkill(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $lesson->id,
            $lessonRevision->id,
            $placement->id,
        );
        $lessonAuthority = fn (): string => $lessonAuthoring
            ->lockLessonLifecycle(
                $teacher->id,
                $assignmentId,
                $curriculum->id,
                $publishedVersion->id,
                $lesson->id,
                $lessonRevision->id,
            );
        app(ReleaseLessonRevision::class)->execute(
            $lessonRevision->id,
            $lessonAuthority,
        );
        app(PublishLesson::class)->execute(
            $lesson->id,
            $lessonRevision->id,
            $lessonAuthority,
        );

        $assessment = $lessonAuthoring->createAssessment(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            'multiple_choice',
            'Readiness assessment',
        );
        $assessmentRevision = $lessonAuthoring->createAssessmentRevision(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $assessment->id,
            1,
            $topic->id,
            'easy',
            [
                'stem' => 'Ready?',
                'options' => ['Yes', 'No'],
            ],
            1,
            ['correct_option' => 0],
            1,
        );
        $lessonAuthoring->createAssessmentRevisionSkill(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $assessment->id,
            $assessmentRevision->id,
            $placement->id,
            'primary',
        );
        $assessmentAuthority = fn (): string => $lessonAuthoring
            ->lockAssessmentLifecycle(
                $teacher->id,
                $assignmentId,
                $curriculum->id,
                $publishedVersion->id,
                $assessment->id,
                $assessmentRevision->id,
            );
        app(ReleaseAssessmentItemRevision::class)->execute(
            $assessmentRevision->id,
            $assessmentAuthority,
        );
        app(PublishAssessmentItem::class)->execute(
            $assessment->id,
            $assessmentRevision->id,
            $assessmentAuthority,
        );

        $practiceExam = app(
            ManageTeacherPracticeExamAuthoring::class
        );
        $practice = $practiceExam->createPractice(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $lesson->id,
            'Readiness practice',
            null,
        );
        $practiceExam->addPracticeItems(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $practice->id,
            [$assessmentRevision->id],
            0,
        );
        $practiceExam->setPracticeStatus(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $practice->id,
            'active',
        );
        $template = $practiceExam->createTemplate(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            'Readiness exam',
            null,
        );
        $templateVersion = $practiceExam->createTemplateVersion(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $template->id,
            1,
            'Readiness exam v1',
            ['question_count' => 1],
            1,
        );
        $practiceExam->publishTemplateVersion(
            $teacher->id,
            $assignmentId,
            $curriculum->id,
            $publishedVersion->id,
            $template->id,
            $templateVersion->id,
        );

        $publishedVersion = app(PublishCurriculumVersion::class)->execute(
            $publishedVersion->id,
            fn (): string => $curriculumAuthoring
                ->lockLifecycleAuthority(
                    $teacher->id,
                    $assignmentId,
                    $curriculum->id,
                ),
        );

        return [
            'published_status' => $publishedVersion->status,
            'draft_status' => $draftVersion->refresh()->status,
        ];
    }

    private function freeze(): void
    {
        $this->connection->statement('SET TRANSACTION READ ONLY');
    }

    private function user(
        string $role,
        string $status = 'active',
    ): User {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
            'email' => Str::uuid().'@dashboard.example.test',
        ]);
    }

    private function student(
        bool $withProfile = true,
        string $status = 'active',
    ): array {
        $user = $this->user('student', $status);
        $profile = $withProfile
            ? LearnerProfile::query()->create([
                'user_id' => $user->id,
            ])
            : null;

        return [
            'user' => $user,
            'profile' => $profile,
        ];
    }

    private function requestEnrollment(
        User $student,
        LearnerProfile $profile,
        string $assignmentId,
    ) {
        return app(RequestStudentEnrollment::class)->execute(
            actorUserId: $student->id,
            learnerProfileId: $profile->id,
            assignmentId: $assignmentId,
            operationId: (string) Str::uuid(),
            reason: 'Dashboard enrollment fixture',
        );
    }

    private function canonicalSubjectId(): string
    {
        $subjectId = $this->connection->table('subjects')
            ->where('code', 'mathematics')
            ->where('status', 'active')
            ->value('id');

        $this->assertIsString($subjectId);

        return $subjectId;
    }

    private function expectedCounts(): array
    {
        return [
            'subjects' => $this->connection->table('subjects')->count(),
            'curricula' => $this->connection->table('curricula')->count(),
            'teacher_owned_curricula' => $this->connection
                ->table('curricula')
                ->whereNotNull('teacher_subject_assignment_id')
                ->count(),
            'legacy_ownerless_curricula' => $this->connection
                ->table('curricula')
                ->whereNull('teacher_subject_assignment_id')
                ->count(),
            'curriculum_versions' => $this->connection
                ->table('curriculum_versions')
                ->count(),
            'topics' => $this->connection->table('topics')->count(),
            'lessons' => $this->connection->table('lessons')->count(),
            'skills' => $this->connection->table('skills')->count(),
            'assessment_items' => $this->connection
                ->table('assessment_items')->count(),
            'practice_activities' => $this->connection
                ->table('practice_activities')->count(),
            'exam_templates' => $this->connection
                ->table('exam_templates')->count(),
            'learners' => $this->connection
                ->table('learner_profiles')->count(),
        ];
    }

    private function expectedReadiness(): array
    {
        return [
            'published_curriculum_versions' => $this->connection
                ->table('curriculum_versions')
                ->where('status', 'published')->count(),
            'published_teacher_owned_curriculum_versions' => $this->connection
                ->table('curriculum_versions as version')
                ->join(
                    'curricula as curriculum',
                    'curriculum.id',
                    '=',
                    'version.curriculum_id'
                )
                ->where('version.status', 'published')
                ->whereNotNull('curriculum.teacher_subject_assignment_id')
                ->count(),
            'published_legacy_ownerless_curriculum_versions' => $this->connection
                ->table('curriculum_versions as version')
                ->join(
                    'curricula as curriculum',
                    'curriculum.id',
                    '=',
                    'version.curriculum_id'
                )
                ->where('version.status', 'published')
                ->whereNull('curriculum.teacher_subject_assignment_id')
                ->count(),
            'published_lessons' => $this->connection
                ->table('lessons')->where('status', 'published')->count(),
            'active_practice_activities' => $this->connection
                ->table('practice_activities')
                ->where('status', 'active')->count(),
            'active_exam_templates' => $this->connection
                ->table('exam_templates')
                ->where('status', 'active')->count(),
        ];
    }

    private function tableCounts(): array
    {
        $tables = [
            'users',
            'learner_profiles',
            'teacher_subject_assignments',
            'teacher_subject_assignment_transitions',
            'student_enrollments',
            'student_enrollment_transitions',
            'curricula',
            'curriculum_versions',
            'topics',
            'lessons',
            'skills',
            'assessment_items',
            'practice_activities',
            'exam_templates',
        ];

        return array_combine(
            $tables,
            array_map(
                fn (string $table): int => $this->connection->table($table)->count(),
                $tables
            )
        );
    }

    private function assertSafeTestDatabase(Connection $connection): void
    {
        $identity = $connection->selectOne(
            <<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user,
    current_setting('TimeZone') AS timezone
SQL
        );

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertSame(
            config('database.connections.pgsql.database'),
            $identity->database_name ?? null
        );
        $this->assertSame(
            config('database.connections.pgsql.username'),
            $identity->database_user ?? null
        );
        $this->assertMatchesRegularExpression(
            '/(?:^|[_-])(test|testing)(?:[_-]|$)/i',
            (string) ($identity->database_name ?? '')
        );
        $this->assertSame('UTC', $identity->timezone ?? null);
        $this->assertSame(
            32,
            $connection->table('migrations')->count()
        );
    }
}

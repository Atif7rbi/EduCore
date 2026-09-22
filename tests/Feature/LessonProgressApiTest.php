<?php

namespace Tests\Feature;

use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\DeactivateStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Learning\RecordLessonProgress;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Learning\UnpublishLesson;
use App\Application\Support\TransactionManager;
use App\Application\TeacherAssignment\DeactivateTeacherSubjectAssignment;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use App\Models\LearnerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\Support\PostgresProcessBarrier;
use Tests\TestCase;

class LessonProgressApiTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_authenticated_learner_can_read_current_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();
        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        DB::table('lesson_progresses')->insert([
            'id' => (string) Str::uuid(),
            'learner_profile_id' => $learner->id,
            'lesson_revision_id' => $revisionId,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'in_progress'
            );
    }

    public function test_lesson_progress_read_returns_null_when_current_revision_has_no_progress(): void
    {
        [$user, $learner] = $this->createLearner();
        [$lessonId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_lesson_progress_read_is_scoped_to_authenticated_learner(): void
    {
        [$user, $learner] = $this->createLearner();
        [, $otherLearner] = $this->createLearner();
        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        DB::table('lesson_progresses')->insert([
            'id' => (string) Str::uuid(),
            'learner_profile_id' => $otherLearner->id,
            'lesson_revision_id' => $revisionId,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_lesson_progress_read_ignores_progress_for_historical_revision(): void
    {
        [$user, $learner] = $this->createLearner();

        [
            $lessonId,
            $historicalRevisionId,
        ] = $this->createPublishedLessonWithHistoricalRevision(
            $learner
        );

        DB::table('lesson_progresses')->insert([
            'id' => (string) Str::uuid(),
            'learner_profile_id' => $learner->id,
            'lesson_revision_id' => $historicalRevisionId,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertOk()
            ->assertExactJson([
                'data' => null,
            ]);
    }

    public function test_missing_enrollment_hides_current_lesson_progress_read(): void
    {
        [$user] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson();

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_inactive_enrollment_hides_current_lesson_progress_read(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $enrollment =
            DB::table('student_enrollments')
                ->where(
                    'learner_profile_id',
                    $learner->id,
                )
                ->where('status', 'active')
                ->first();

        $this->assertNotNull($enrollment);

        $teacherId =
            DB::table(
                'teacher_subject_assignments'
            )
                ->where(
                    'id',
                    $enrollment
                        ->teacher_subject_assignment_id,
                )
                ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'PF-003 current progress read revocation.',
        );

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_inactive_assignment_hides_current_lesson_progress_read(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $versionId =
            DB::table('lessons')
                ->where('id', $lessonId)
                ->value('curriculum_version_id');

        $curriculumId =
            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->value('curriculum_id');

        $assignmentId =
            DB::table('curricula')
                ->where('id', $curriculumId)
                ->value(
                    'teacher_subject_assignment_id'
                );

        $this->assertIsString($assignmentId);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        app(
            DeactivateTeacherSubjectAssignment::class
        )->execute(
            actorUserId: $admin->id,
            assignmentId: $assignmentId,
            operationId: (string) Str::uuid(),
            reason: 'PF-003 current progress assignment revocation.',
        );

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_ownerless_curriculum_hides_current_lesson_progress_read(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $versionId =
            DB::table('lessons')
                ->where('id', $lessonId)
                ->value('curriculum_version_id');

        $this->assertIsString($versionId);

        $database = DB::selectOne(
            'SELECT current_database() AS database_name'
        );

        $this->assertSame(
            'sewaellf_educore_test',
            $database->database_name ?? null,
        );

        $curriculumId =
            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->value('curriculum_id');

        DB::statement(
            'ALTER TABLE curricula DISABLE TRIGGER trg_curricula_ownership_integrity'
        );

        try {
            DB::table('curricula')
                ->where('id', $curriculumId)
                ->update([
                    'teacher_subject_assignment_id' => null,
                    'updated_at' => now(),
                ]);
        } finally {
            DB::statement(
                'ALTER TABLE curricula ENABLE TRIGGER trg_curricula_ownership_integrity'
            );
        }

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );
    }

    public function test_authenticated_learner_can_start_published_lesson(): void
    {
        [$user, $learner] = $this->createLearner();
        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'in_progress'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'in_progress',
            ]
        );
    }

    public function test_start_does_not_accept_learner_identity_from_body(): void
    {
        [$user, $learner] = $this->createLearner();
        [, $otherLearner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress",
            [
                'learner_profile_id' => $otherLearner->id,
            ]
        )->assertOk();

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
            ]
        );

        $this->assertDatabaseMissing(
            'lesson_progresses',
            [
                'learner_profile_id' => $otherLearner->id,
                'lesson_revision_id' => $revisionId,
            ]
        );
    }

    public function test_authenticated_learner_can_complete_published_lesson(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertOk();

        $response = $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.lesson_revision_id',
                $revisionId
            )
            ->assertJsonPath(
                'data.status',
                'completed'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'completed',
            ]
        );

        $this->assertNotNull(
            $response->json('data.completed_at')
        );
    }

    public function test_complete_without_prior_start_creates_completed_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.status',
                'completed'
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'lesson_revision_id' => $revisionId,
                'status' => 'completed',
            ]
        );
    }

    public function test_inactive_enrollment_rejects_starting_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $enrollment = DB::table('student_enrollments')
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->first();

        $this->assertNotNull($enrollment);

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $enrollment->teacher_subject_assignment_id,
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Phase F Lesson Progress revocation test.',
        );

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );

        $this->assertDatabaseMissing(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
            ],
        );
    }

    public function test_inactive_enrollment_rejects_completing_lesson_progress(): void
    {
        [$user, $learner] = $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson($learner);

        $this->actingAs($user);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertOk();

        $enrollment = DB::table('student_enrollments')
            ->where(
                'learner_profile_id',
                $learner->id,
            )
            ->first();

        $this->assertNotNull($enrollment);

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $enrollment->teacher_subject_assignment_id,
            )
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            DeactivateStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Phase F Lesson completion revocation test.',
        );

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found',
            );

        $this->assertDatabaseHas(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'status' => 'in_progress',
            ],
        );

        $this->assertDatabaseMissing(
            'lesson_progresses',
            [
                'learner_profile_id' => $learner->id,
                'status' => 'completed',
            ],
        );
    }

    public function test_enrollment_deactivation_serializes_before_new_lesson_progress(): void
    {
        [$user, $learner] =
            $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson(
                $learner
            );

        $enrollment =
            DB::table('student_enrollments')
                ->where(
                    'learner_profile_id',
                    $learner->id,
                )
                ->first();

        $this->assertNotNull($enrollment);

        $teacherId =
            DB::table(
                'teacher_subject_assignments'
            )
                ->where(
                    'id',
                    $enrollment
                        ->teacher_subject_assignment_id,
                )
                ->value('teacher_id');

        $this->assertIsString($teacherId);

        $barrier = null;

        DB::beginTransaction();

        try {
            app(
                DeactivateStudentEnrollment::class
            )->execute(
                actorUserId: $teacherId,
                enrollmentId: $enrollment->id,
                operationId: (string) Str::uuid(),
                reason: 'Lesson Progress deactivation-wins race.',
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'record_lesson_progress',
                    'authenticated_user_id' => $user->id,
                    'learner_profile_id' => $learner->id,
                    'lesson_id' => $lessonId,
                    'complete' => false,
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $this->assertPostgresBlockedByParent(
                $barrier,
                $ready['pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                0,
                DB::table('lesson_progresses')
                    ->where(
                        'learner_profile_id',
                        $learner->id,
                    )
                    ->count(),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    public function test_new_lesson_progress_serializes_before_enrollment_deactivation(): void
    {
        [$user, $learner] =
            $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson(
                $learner
            );

        $enrollment =
            DB::table('student_enrollments')
                ->where(
                    'learner_profile_id',
                    $learner->id,
                )
                ->first();

        $this->assertNotNull($enrollment);

        $teacherId =
            DB::table(
                'teacher_subject_assignments'
            )
                ->where(
                    'id',
                    $enrollment
                        ->teacher_subject_assignment_id,
                )
                ->value('teacher_id');

        $this->assertIsString($teacherId);

        $barrier = null;

        DB::beginTransaction();

        try {
            $progress = app(
                RecordLessonProgress::class
            )->execute(
                $user->id,
                $learner->id,
                $lessonId,
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'deactivate_student_enrollment',
                    'actor_user_id' => $teacherId,
                    'enrollment_id' => $enrollment->id,
                    'operation_id' => (string) Str::uuid(),
                    'reason' => 'Lesson Progress wins enrollment race.',
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $this->assertPostgresBlockedByParent(
                $barrier,
                $ready['pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'success',
                $result['result'] ?? null,
            );

            $this->assertSame(
                'inactive',
                $result['data']['status']
                    ?? null,
            );

            $this->assertDatabaseHas(
                'lesson_progresses',
                [
                    'id' => $progress->id,
                    'learner_profile_id' => $learner->id,
                    'lesson_revision_id' => $revisionId,
                    'status' => 'in_progress',
                ],
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    public function test_lesson_unpublish_serializes_before_new_lesson_progress(): void
    {
        [$user, $learner] =
            $this->createLearner();

        [$lessonId] =
            $this->createPublishedLesson(
                $learner
            );

        $barrier = null;

        DB::beginTransaction();

        try {
            app(
                UnpublishLesson::class
            )->execute(
                $lessonId
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'record_lesson_progress',
                    'authenticated_user_id' => $user->id,
                    'learner_profile_id' => $learner->id,
                    'lesson_id' => $lessonId,
                    'complete' => false,
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $this->assertPostgresBlockedByParent(
                $barrier,
                $ready['pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'exception',
                $result['result'] ?? null,
            );

            $this->assertSame(
                ModelNotFoundException::class,
                $result['class'] ?? null,
            );

            $this->assertSame(
                0,
                DB::table('lesson_progresses')
                    ->where(
                        'learner_profile_id',
                        $learner->id,
                    )
                    ->count(),
            );

            $this->assertSame(
                'unpublished',
                DB::table('lessons')
                    ->where(
                        'id',
                        $lessonId,
                    )
                    ->value('status'),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    public function test_new_lesson_progress_serializes_before_lesson_unpublish(): void
    {
        [$user, $learner] =
            $this->createLearner();

        [$lessonId, $revisionId] =
            $this->createPublishedLesson(
                $learner
            );

        $barrier = null;

        DB::beginTransaction();

        try {
            $progress = app(
                RecordLessonProgress::class
            )->execute(
                $user->id,
                $learner->id,
                $lessonId,
            );

            $barrier =
                PostgresProcessBarrier::start([
                    'action' => 'unpublish_lesson',
                    'lesson_id' => $lessonId,
                ]);

            $ready =
                $barrier->awaitReady();

            $barrier->release();

            $this->assertPostgresBlockedByParent(
                $barrier,
                $ready['pid'],
            );

            DB::commit();

            $result =
                $barrier->finish();

            $this->assertSame(
                'success',
                $result['result'] ?? null,
            );

            $this->assertSame(
                'unpublished',
                $result['data']['status']
                    ?? null,
            );

            $this->assertDatabaseHas(
                'lesson_progresses',
                [
                    'id' => $progress->id,
                    'learner_profile_id' => $learner->id,
                    'lesson_revision_id' => $revisionId,
                    'status' => 'in_progress',
                ],
            );

            $this->assertSame(
                'unpublished',
                DB::table('lessons')
                    ->where(
                        'id',
                        $lessonId,
                    )
                    ->value('status'),
            );
        } finally {
            if (
                DB::transactionLevel() > 0
            ) {
                DB::rollBack();
            }

            $barrier?->cleanup();
        }
    }

    public function test_progress_routes_require_authentication(): void
    {
        $lessonId = (string) Str::uuid();

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertStatus(401);

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )->assertStatus(401);

        $this->postJson(
            "/api/lessons/{$lessonId}/complete"
        )->assertStatus(401);
    }

    public function test_progress_routes_require_learner_profile(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $lessonId = (string) Str::uuid();

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(403)
            ->assertJsonPath(
                'error.code',
                'learner_profile_required'
            );
    }

    public function test_draft_lesson_is_not_visible_to_progress_endpoint(): void
    {
        [$user] = $this->createLearner();

        $lessonId = $this->createDraftLesson();

        $this->actingAs($user);

        $this->getJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );

        $this->postJson(
            "/api/lessons/{$lessonId}/progress"
        )
            ->assertStatus(404)
            ->assertJsonPath(
                'error.code',
                'not_found'
            );
    }

    /**
     * @return array{User, LearnerProfile}
     */
    private function assertPostgresBlockedByParent(
        PostgresProcessBarrier $barrier,
        int $childPid,
    ): void {
        $wait =
            $barrier
                ->awaitBlockedByCurrentConnection(
                    $childPid
                );

        $this->assertSame(
            'Lock',
            $wait['wait_event_type'],
        );

        $this->assertTrue(
            $wait['blocked_by_parent'],
        );

        $this->assertSame(
            $childPid,
            $wait['child_pid'],
        );
    }

    private function createLearner(): array
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $learner = LearnerProfile::create([
            'user_id' => $user->id,
        ]);

        return [$user, $learner];
    }

    /**
     * @return array{string, string}
     */
    private function createPublishedLesson(
        ?LearnerProfile $learner = null,
    ): array {
        [
            $lessonId,
            $revisionId,
            $versionId,
        ] = $this->createLessonFixture();

        (new ReleaseLessonRevision(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        ))->execute($revisionId);

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'published',
                'published_revision_id' => $revisionId,
                'updated_at' => now(),
            ]);

        $this->markCurriculumPublishedForFixture(
            $versionId
        );

        if ($learner !== null) {
            $this->activateEnrollmentForVersion(
                $learner,
                $versionId,
            );
        }

        return [
            $lessonId,
            $revisionId,
        ];
    }

    /**
     * @return array{string, string, string}
     */
    private function createPublishedLessonWithHistoricalRevision(
        ?LearnerProfile $learner = null,
    ): array {
        [
            $lessonId,
            $historicalRevisionId,
            $versionId,
        ] = $this->createLessonFixture();

        $historicalRevision = DB::table(
            'lesson_revisions'
        )
            ->where(
                'id',
                $historicalRevisionId
            )
            ->first();

        $currentRevisionId =
            (string) Str::uuid();

        DB::table('lesson_revisions')->insert([
            'id' => $currentRevisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 2,
            'primary_topic_id' => $historicalRevision->primary_topic_id,
            'content_payload' => json_encode([
                'blocks' => [
                    [
                        'type' => 'text',
                        'value' => 'Current progress API content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        $transactions =
            new TransactionManager(
                new PostgresExceptionTranslator
            );

        (new ReleaseLessonRevision(
            $transactions
        ))->execute(
            $historicalRevisionId
        );

        (new ReleaseLessonRevision(
            $transactions
        ))->execute(
            $currentRevisionId
        );

        DB::table('lessons')
            ->where('id', $lessonId)
            ->update([
                'status' => 'published',
                'published_revision_id' => $currentRevisionId,
                'updated_at' => now(),
            ]);

        $this->markCurriculumPublishedForFixture(
            $versionId
        );

        if ($learner !== null) {
            $this->activateEnrollmentForVersion(
                $learner,
                $versionId,
            );
        }

        return [
            $lessonId,
            $historicalRevisionId,
            $currentRevisionId,
        ];
    }

    private function activateEnrollmentForVersion(
        LearnerProfile $learner,
        string $versionId,
    ): void {
        $curriculumId = DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->value('curriculum_id');

        $assignmentId = DB::table('curricula')
            ->where('id', $curriculumId)
            ->value('teacher_subject_assignment_id');

        $this->assertIsString($assignmentId);

        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $learner->user_id,
            learnerProfileId: $learner->id,
            assignmentId: $assignmentId,
            operationId: (string) Str::uuid(),
            reason: 'Lesson Progress authorization fixture.',
        );

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where('id', $assignmentId)
            ->value('teacher_id');

        $this->assertIsString($teacherId);

        app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'Lesson Progress authorization fixture acceptance.',
        );
    }

    private function markCurriculumPublishedForFixture(
        string $versionId,
    ): void {
        /*
         * Progress tests need a visible published Lesson, not a
         * second copy of the curriculum publishing specification.
         */
        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);
    }

    private function createDraftLesson(): string
    {
        [
            $lessonId,
        ] = $this->createLessonFixture();

        return $lessonId;
    }

    /**
     * @return array{string, string, string}
     */
    private function createLessonFixture(): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        $curriculum =
            $this->createOwnedCurriculumFixture(
                'Owned Curriculum '.Str::uuid()
            );

        $curriculumId = $curriculum->id;
        $subjectId = $curriculum->subject_id;

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'v1',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('topics')->insert([
            'id' => $topicId,
            'curriculum_version_id' => $versionId,
            'name' => "Progress API Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => 'Progress API Lesson',
            'description' => null,
            'status' => 'draft',
            'display_order' => 0,
            'published_revision_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lesson_revisions')->insert([
            'id' => $revisionId,
            'lesson_id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'revision_number' => 1,
            'primary_topic_id' => $topicId,
            'content_payload' => json_encode([
                'blocks' => [
                    [
                        'type' => 'text',
                        'value' => 'Progress API content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        return [
            $lessonId,
            $revisionId,
            $versionId,
        ];
    }
}

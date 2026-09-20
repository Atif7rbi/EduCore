<?php

namespace Tests\Feature;

use App\Application\Authorization\LockActiveLearnerCurriculumGrant;
use App\Application\Enrollment\AcceptStudentEnrollment;
use App\Application\Enrollment\RequestStudentEnrollment;
use App\Application\Learning\RecordLessonProgress;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class RecordLessonProgressTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_released_revision_can_start_progress(): void
    {
        [
            $learnerId,
            $lessonId,
            $revisionId,
        ] = $this->createFixture(released: true);

        $progress = $this->service()->execute(
            $learnerId,
            $lessonId,
        );

        $this->assertSame(
            $learnerId,
            $progress->learner_profile_id
        );

        $this->assertSame(
            $revisionId,
            $progress->lesson_revision_id
        );

        $this->assertSame(
            'in_progress',
            $progress->status
        );

        $this->assertNotNull(
            $progress->started_at
        );

        $this->assertNull(
            $progress->completed_at
        );
    }

    public function test_starting_same_revision_twice_is_idempotent(): void
    {
        [
            $learnerId,
            $lessonId,
            $revisionId,
        ] = $this->createFixture(released: true);

        $first = $this->service()->execute(
            $learnerId,
            $lessonId,
        );

        $second = $this->service()->execute(
            $learnerId,
            $lessonId,
        );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertSame(
            1,
            DB::table('lesson_progresses')
                ->where(
                    'learner_profile_id',
                    $learnerId
                )
                ->where(
                    'lesson_revision_id',
                    $revisionId
                )
                ->count()
        );
    }

    public function test_progress_can_be_completed(): void
    {
        [
            $learnerId,
            $lessonId,
            $revisionId,
        ] = $this->createFixture(released: true);

        $this->service()->execute(
            $learnerId,
            $lessonId,
        );

        $progress = $this->service()->execute(
            $learnerId,
            $lessonId,
            true,
        );

        $this->assertSame(
            'completed',
            $progress->status
        );

        $this->assertNotNull(
            $progress->completed_at
        );

        $this->assertTrue(
            $progress->completed_at
                ->greaterThanOrEqualTo(
                    $progress->started_at
                )
        );
    }

    public function test_completed_progress_is_idempotent(): void
    {
        [
            $learnerId,
            $lessonId,
            $revisionId,
        ] = $this->createFixture(released: true);

        $first = $this->service()->execute(
            $learnerId,
            $lessonId,
            true,
        );

        $completedAt = $first->completed_at;

        $second = $this->service()->execute(
            $learnerId,
            $lessonId,
            true,
        );

        $this->assertSame(
            'completed',
            $second->status
        );

        $this->assertTrue(
            $completedAt->equalTo(
                $second->completed_at
            )
        );
    }

    public function test_unreleased_revision_cannot_start_progress(): void
    {
        [
            $learnerId,
            $lessonId,
            $revisionId,
        ] = $this->createFixture(released: false);

        try {
            $this->service()->execute(
                $learnerId,
                $lessonId,
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(
            0,
            DB::table('lesson_progresses')
                ->where(
                    'lesson_revision_id',
                    $revisionId
                )
                ->count()
        );
    }

    private function activateEnrollment(
        string $learnerId,
        string $userId,
        string $assignmentId,
    ): void {
        $enrollment = app(
            RequestStudentEnrollment::class
        )->execute(
            actorUserId: $userId,
            learnerProfileId: $learnerId,
            assignmentId: $assignmentId,
            operationId: (string) Str::uuid(),
            reason: 'RecordLessonProgress authorization fixture.',
        );

        $teacherId = DB::table(
            'teacher_subject_assignments'
        )
            ->where(
                'id',
                $assignmentId,
            )
            ->value('teacher_id');

        $this->assertIsString(
            $teacherId
        );

        app(
            AcceptStudentEnrollment::class
        )->execute(
            actorUserId: $teacherId,
            enrollmentId: $enrollment->id,
            operationId: (string) Str::uuid(),
            reason: 'RecordLessonProgress fixture acceptance.',
        );
    }

    private function service(): RecordLessonProgress
    {
        return new RecordLessonProgress(
            new TransactionManager(
                new PostgresExceptionTranslator
            ),
            new LockActiveLearnerCurriculumGrant,
        );
    }

    /**
     * @return array{string, string, string}
     */
    private function createFixture(
        bool $released,
    ): array {
        $userId = (string) Str::uuid();
        $learnerId = (string) Str::uuid();
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => "Progress User {$userId}",
            'email' => "progress-{$userId}@example.test",
            'password' => 'not-used',
            'status' => 'active',
            'role' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('learner_profiles')->insert([
            'id' => $learnerId,
            'user_id' => $userId,
            'created_at' => now(),
        ]);

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
            'name' => "Progress Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => 'Progress Lesson',
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
                        'value' => 'Progress content',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        if ($released) {
            (new ReleaseLessonRevision(
                new TransactionManager(
                    new PostgresExceptionTranslator
                )
            ))->execute($revisionId);

            DB::table('lessons')
                ->where(
                    'id',
                    $lessonId,
                )
                ->update([
                    'status' => 'published',
                    'published_revision_id' => $revisionId,
                    'updated_at' => now(),
                ]);
        }

        DB::table('curriculum_versions')
            ->where(
                'id',
                $versionId,
            )
            ->update([
                'status' => 'published',
                'updated_at' => now(),
            ]);

        $this->activateEnrollment(
            $learnerId,
            $userId,
            $curriculum
                ->teacher_subject_assignment_id,
        );

        return [
            $learnerId,
            $lessonId,
            $revisionId,
        ];
    }
}

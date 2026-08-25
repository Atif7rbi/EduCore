<?php

namespace Tests\Feature;

use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Learning\RecordLessonProgress;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordLessonProgressTest extends TestCase
{
    public function test_released_revision_can_start_progress(): void
    {
        [
            $learnerId,
            $revisionId,
        ] = $this->createFixture(released: true);

        $progress = $this->service()->execute(
            $learnerId,
            $revisionId,
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
            $revisionId,
        ] = $this->createFixture(released: true);

        $first = $this->service()->execute(
            $learnerId,
            $revisionId,
        );

        $second = $this->service()->execute(
            $learnerId,
            $revisionId,
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
            $revisionId,
        ] = $this->createFixture(released: true);

        $this->service()->execute(
            $learnerId,
            $revisionId,
        );

        $progress = $this->service()->execute(
            $learnerId,
            $revisionId,
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
            $revisionId,
        ] = $this->createFixture(released: true);

        $first = $this->service()->execute(
            $learnerId,
            $revisionId,
            true,
        );

        $completedAt = $first->completed_at;

        $second = $this->service()->execute(
            $learnerId,
            $revisionId,
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
            $revisionId,
        ] = $this->createFixture(released: false);

        try {
            $this->service()->execute(
                $learnerId,
                $revisionId,
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame(
                'P0001',
                $exception->sqlState
            );
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

    private function service(): RecordLessonProgress
    {
        return new RecordLessonProgress(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );
    }

    /**
     * @return array{string, string}
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

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Progress Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Progress Curriculum {$curriculumId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
                    new PostgresExceptionTranslator()
                )
            ))->execute($revisionId);
        }

        return [
            $learnerId,
            $revisionId,
        ];
    }
}

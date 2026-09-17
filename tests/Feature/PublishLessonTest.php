<?php

namespace Tests\Feature;

use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Learning\PublishLesson;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class PublishLessonTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_lesson_can_be_published_with_released_same_lesson_revision(): void
    {
        [$lessonId, $revisionId] = $this->createLessonFixture();

        $this->releaseRevision($revisionId);

        $result = $this->service()->execute(
            $lessonId,
            $revisionId
        );

        $this->assertSame($lessonId, $result->id);
        $this->assertSame('published', $result->status);
        $this->assertSame($revisionId, $result->published_revision_id);

        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'status' => 'published',
            'published_revision_id' => $revisionId,
        ]);
    }

    public function test_unreleased_revision_cannot_publish_lesson(): void
    {
        [$lessonId, $revisionId] = $this->createLessonFixture();

        try {
            $this->service()->execute(
                $lessonId,
                $revisionId
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'status' => 'draft',
            'published_revision_id' => null,
        ]);
    }

    public function test_revision_from_different_lesson_cannot_publish_lesson(): void
    {
        [$lessonId] = $this->createLessonFixture();
        [, $otherRevisionId] = $this->createLessonFixture();

        $this->releaseRevision($otherRevisionId);

        try {
            $this->service()->execute(
                $lessonId,
                $otherRevisionId
            );

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertDatabaseHas('lessons', [
            'id' => $lessonId,
            'status' => 'draft',
            'published_revision_id' => null,
        ]);
    }

    private function service(): PublishLesson
    {
        return new PublishLesson(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        );
    }

    private function releaseRevision(string $revisionId): void
    {
        $service = new ReleaseLessonRevision(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        );

        $service->execute($revisionId);
    }

    /**
     * @return array{string, string}
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
            'name' => "Publish Lesson Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => "Publish Lesson {$lessonId}",
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
                'type' => 'lesson',
                'blocks' => [],
            ], JSON_THROW_ON_ERROR),
            'content_schema_version' => 1,
            'released_at' => null,
            'created_at' => now(),
        ]);

        return [$lessonId, $revisionId];
    }
}

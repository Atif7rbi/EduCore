<?php

namespace Tests\Feature;

use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Learning\ReleaseLessonRevision;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReleaseLessonRevisionTest extends TestCase
{
    public function test_unreleased_lesson_revision_can_be_released(): void
    {
        $revisionId = $this->createUnreleasedRevision();

        $service = new ReleaseLessonRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        $result = $service->execute($revisionId);

        $this->assertSame($revisionId, $result->id);
        $this->assertNotNull($result->released_at);

        $this->assertDatabaseHas('lesson_revisions', [
            'id' => $revisionId,
        ]);

        $this->assertNotNull(
            DB::table('lesson_revisions')
                ->where('id', $revisionId)
                ->value('released_at')
        );
    }

    public function test_released_lesson_revision_cannot_be_released_again(): void
    {
        $revisionId = $this->createUnreleasedRevision();

        $service = new ReleaseLessonRevision(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        $first = $service->execute($revisionId);
        $releasedAt = $first->released_at;

        try {
            $service->execute($revisionId);

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $persisted = DB::table('lesson_revisions')
            ->where('id', $revisionId)
            ->value('released_at');

        $this->assertSame(
            $releasedAt->format('Y-m-d H:i:sP'),
            \Carbon\CarbonImmutable::parse($persisted)
                ->format('Y-m-d H:i:sP')
        );
    }

    private function createUnreleasedRevision(): string
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $topicId = (string) Str::uuid();
        $lessonId = (string) Str::uuid();
        $revisionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Lesson Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Lesson Curriculum {$curriculumId}",
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
            'name' => "Lesson Topic {$topicId}",
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lessons')->insert([
            'id' => $lessonId,
            'curriculum_version_id' => $versionId,
            'title' => "Lesson {$lessonId}",
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

        return $revisionId;
    }
}

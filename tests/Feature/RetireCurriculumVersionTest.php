<?php

namespace Tests\Feature;

use App\Application\Curriculum\RetireCurriculumVersion;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetireCurriculumVersionTest extends TestCase
{
    public function test_published_curriculum_version_can_be_retired(): void
    {
        [$versionId] = $this->createCurriculumVersion('published');

        $service = new RetireCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        $result = $service->execute($versionId);

        $this->assertSame($versionId, $result->id);
        $this->assertSame('retired', $result->status);

        $this->assertDatabaseHas('curriculum_versions', [
            'id' => $versionId,
            'status' => 'retired',
        ]);
    }

    public function test_draft_curriculum_version_cannot_be_retired(): void
    {
        [$versionId] = $this->createCurriculumVersion('draft');

        $service = new RetireCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        try {
            $service->execute($versionId);

            $this->fail(
                'Expected IntegrityConstraintViolation was not thrown.'
            );
        } catch (IntegrityConstraintViolation $exception) {
            $this->assertSame('P0001', $exception->sqlState);
        }

        $this->assertDatabaseHas('curriculum_versions', [
            'id' => $versionId,
            'status' => 'draft',
        ]);
    }

    /**
     * @return array{string, string, string}
     */
    private function createCurriculumVersion(string $status): array
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Retire Subject {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Retire Curriculum {$curriculumId}",
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

        if ($status === 'published' || $status === 'retired') {
            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->update(['status' => 'published']);
        }

        if ($status === 'retired') {
            DB::table('curriculum_versions')
                ->where('id', $versionId)
                ->update(['status' => 'retired']);
        }

        return [$versionId, $curriculumId, $subjectId];
    }
}

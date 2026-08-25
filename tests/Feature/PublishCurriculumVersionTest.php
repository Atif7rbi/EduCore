<?php

namespace Tests\Feature;

use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishCurriculumVersionTest extends TestCase
{
    public function test_draft_curriculum_version_can_be_published(): void
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Quantitative {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Qudrat Quantitative {$curriculumId}",
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

        $service = new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator()
            )
        );

        $result = $service->execute($versionId);

        $this->assertSame($versionId, $result->id);
        $this->assertSame('published', $result->status);

        $this->assertDatabaseHas('curriculum_versions', [
            'id' => $versionId,
            'status' => 'published',
        ]);
    }

    public function test_invalid_lifecycle_transition_is_translated(): void
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $subjectId,
            'name' => "Quantitative {$subjectId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $subjectId,
            'name' => "Qudrat Quantitative {$curriculumId}",
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

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update(['status' => 'published']);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update(['status' => 'retired']);

        $service = new PublishCurriculumVersion(
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
            'status' => 'retired',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Application\Curriculum\PublishCurriculumVersion;
use App\Application\Exceptions\CurriculumVersionNotReady;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Support\TransactionManager;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOwnedCurriculumFixtures;
use Tests\Concerns\ResetsDedicatedTestDatabase;
use Tests\TestCase;

class PublishCurriculumVersionTest extends TestCase
{
    use CreatesOwnedCurriculumFixtures;
    use ResetsDedicatedTestDatabase;

    public function test_incomplete_draft_curriculum_version_cannot_be_published(): void
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        $curriculum =
            $this->createOwnedCurriculumFixture(
                'Qudrat Quantitative '.Str::uuid()
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

        $service = new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator
            )
        );

        try {
            $service->execute($versionId);

            $this->fail(
                'Expected CurriculumVersionNotReady was not thrown.'
            );
        } catch (
            CurriculumVersionNotReady $exception
        ) {
            $this->assertSame(
                [
                    'has_topic',
                    'has_skill_placement',
                    'has_published_lesson',
                    'has_published_assessment_item',
                    'has_learner_usable_practice',
                    'has_usable_exam_template',
                ],
                array_column(
                    $exception->blockers,
                    'code'
                )
            );
        }

        $this->assertDatabaseHas(
            'curriculum_versions',
            [
                'id' => $versionId,
                'status' => 'draft',
            ]
        );
    }

    public function test_invalid_lifecycle_transition_is_translated(): void
    {
        $subjectId = (string) Str::uuid();
        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();

        $curriculum =
            $this->createOwnedCurriculumFixture(
                'Qudrat Quantitative '.Str::uuid()
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

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update(['status' => 'published']);

        DB::table('curriculum_versions')
            ->where('id', $versionId)
            ->update(['status' => 'retired']);

        $service = new PublishCurriculumVersion(
            new TransactionManager(
                new PostgresExceptionTranslator
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

<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Cda008SubjectCatalogIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_reference_data_is_installed_by_migration(): void
    {
        $subjects = DB::table('subjects')
            ->whereNotNull('code')
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(6, $subjects);

        $this->assertSame(
            [
                'mathematics',
                'physics',
                'biology',
                'chemistry',
                'english_language',
                'arabic_language',
            ],
            $subjects
                ->pluck('code')
                ->all(),
        );

        foreach ($subjects as $subject) {
            $this->assertSame(
                'active',
                $subject->status,
            );

            $this->assertNotNull(
                $subject->icon_key,
            );

            $this->assertNotNull(
                $subject->thumbnail_key,
            );
        }

        $stages = DB::table(
            'education_stages'
        )
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(3, $stages);

        $this->assertSame(
            [
                'primary',
                'middle',
                'secondary',
            ],
            $stages
                ->pluck('code')
                ->all(),
        );
    }

    public function test_subject_code_is_db_immutable_for_canonical_and_legacy_rows(): void
    {
        $mathId = $this->subjectId(
            'mathematics'
        );

        $this->assertSqlState23514(
            function () use ($mathId): void {
                DB::table('subjects')
                    ->where('id', $mathId)
                    ->update([
                        'code' => 'mathematics_changed',
                    ]);
            }
        );

        $this->assertSqlState23514(
            function () use ($mathId): void {
                DB::table('subjects')
                    ->where('id', $mathId)
                    ->update([
                        'code' => null,
                    ]);
            }
        );

        $legacyId = $this->legacySubject();

        $this->assertSqlState23514(
            function () use ($legacyId): void {
                DB::table('subjects')
                    ->where('id', $legacyId)
                    ->update([
                        'code' => 'legacy_promoted',
                    ]);
            }
        );
    }

    public function test_canonical_subject_name_is_db_immutable_but_legacy_name_remains_editable(): void
    {
        $mathId = $this->subjectId(
            'mathematics'
        );

        $this->assertSqlState23514(
            function () use ($mathId): void {
                DB::table('subjects')
                    ->where('id', $mathId)
                    ->update([
                        'name' => 'Changed Mathematics',
                    ]);
            }
        );

        $legacyId = $this->legacySubject();

        DB::table('subjects')
            ->where('id', $legacyId)
            ->update([
                'name' => 'Legacy Rename Allowed',
            ]);

        $this->assertDatabaseHas(
            'subjects',
            [
                'id' => $legacyId,
                'name' => 'Legacy Rename Allowed',
                'code' => null,
            ],
        );
    }

    public function test_education_stage_code_is_db_immutable(): void
    {
        $primaryId = $this->stageId(
            'primary'
        );

        $this->assertSqlState23514(
            function () use ($primaryId): void {
                DB::table('education_stages')
                    ->where('id', $primaryId)
                    ->update([
                        'code' => 'primary_changed',
                    ]);
            }
        );
    }

    public function test_curriculum_stage_is_db_immutable_across_all_transitions(): void
    {
        $subjectId = $this->subjectId(
            'mathematics'
        );

        $primaryId = $this->stageId(
            'primary'
        );

        $middleId = $this->stageId(
            'middle'
        );

        $unclassifiedId = $this->curriculum(
            $subjectId,
            null,
            'Unclassified',
        );

        $this->assertSqlState23514(
            function () use (
                $unclassifiedId,
                $primaryId
            ): void {
                DB::table('curricula')
                    ->where(
                        'id',
                        $unclassifiedId,
                    )
                    ->update([
                        'education_stage_id' => $primaryId,
                    ]);
            }
        );

        $classifiedId = $this->curriculum(
            $subjectId,
            $primaryId,
            'Classified',
        );

        $this->assertSqlState23514(
            function () use (
                $classifiedId
            ): void {
                DB::table('curricula')
                    ->where(
                        'id',
                        $classifiedId,
                    )
                    ->update([
                        'education_stage_id' => null,
                    ]);
            }
        );

        $this->assertSqlState23514(
            function () use (
                $classifiedId,
                $middleId
            ): void {
                DB::table('curricula')
                    ->where(
                        'id',
                        $classifiedId,
                    )
                    ->update([
                        'education_stage_id' => $middleId,
                    ]);
            }
        );

        $updated = DB::table('curricula')
            ->where('id', $classifiedId)
            ->update([
                'education_stage_id' => $primaryId,
            ]);

        $this->assertSame(1, $updated);

        $this->assertDatabaseHas(
            'curricula',
            [
                'id' => $classifiedId,
                'education_stage_id' => $primaryId,
            ],
        );
    }

    public function test_curriculum_stage_foreign_key_is_restrictive(): void
    {
        $subjectId = $this->subjectId(
            'mathematics'
        );

        $primaryId = $this->stageId(
            'primary'
        );

        $this->curriculum(
            $subjectId,
            $primaryId,
            'FK Restrict',
        );

        $this->assertSqlState(
            '23503',
            function () use ($primaryId): void {
                DB::table('education_stages')
                    ->where('id', $primaryId)
                    ->delete();
            }
        );
    }

    public function test_catalog_migration_adopts_only_the_approved_legacy_physics_identity_and_preserves_curriculum_link(): void
    {
        $this->rollBackCatalogForMigrationScenario();

        $physicsId =
            '01a075f1-d4bb-72dc-ac69-7332950b8408';

        $curriculumId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $now = now();

        DB::table('subjects')->insert([
            'id' => $physicsId,
            'name' => 'الفيزياء',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('curricula')->insert([
            'id' => $curriculumId,
            'subject_id' => $physicsId,
            'name' => 'الثالث ثانوي',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('curriculum_versions')->insert([
            'id' => $versionId,
            'curriculum_id' => $curriculumId,
            'version_number' => 1,
            'label' => 'Production legacy draft',
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path(
            'migrations/'
            .'2026_09_11_000000_add_subject_catalog_and_education_stages.php'
        );

        $migration->up();

        $physics = DB::table('subjects')
            ->where('code', 'physics')
            ->first();

        $this->assertNotNull($physics);
        $this->assertSame(
            $physicsId,
            (string) $physics->id,
        );
        $this->assertSame(
            'الفيزياء',
            $physics->name,
        );
        $this->assertSame(
            'active',
            $physics->status,
        );
        $this->assertSame(
            'subjects/physics/icon',
            $physics->icon_key,
        );
        $this->assertSame(
            'subjects/physics/thumbnail',
            $physics->thumbnail_key,
        );

        $this->assertSame(
            1,
            DB::table('subjects')
                ->where('name', 'الفيزياء')
                ->count(),
        );

        $this->assertDatabaseHas(
            'curricula',
            [
                'id' => $curriculumId,
                'subject_id' => $physicsId,
                'name' => 'الثالث ثانوي',
            ],
        );

        $this->assertDatabaseHas(
            'curriculum_versions',
            [
                'id' => $versionId,
                'curriculum_id' => $curriculumId,
                'version_number' => 1,
                'status' => 'draft',
            ],
        );

        $this->assertSame(
            6,
            DB::table('subjects')
                ->whereNotNull('code')
                ->count(),
        );

        $this->assertSame(
            3,
            DB::table('education_stages')
                ->count(),
        );
    }

    public function test_catalog_migration_still_rejects_same_canonical_name_on_unapproved_identity(): void
    {
        $this->rollBackCatalogForMigrationScenario();

        DB::table('subjects')->insert([
            'id' => (string) Str::uuid(),
            'name' => 'الفيزياء',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/'
            .'2026_09_11_000000_add_subject_catalog_and_education_stages.php'
        );

        try {
            $migration->up();
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'CDA-008 canonical Subject name collision: الفيزياء',
                $exception->getMessage(),
            );

            $this->assertFalse(
                DB::getSchemaBuilder()
                    ->hasTable('education_stages'),
            );

            $this->assertFalse(
                DB::getSchemaBuilder()
                    ->hasColumn(
                        'subjects',
                        'code',
                    ),
            );

            return;
        }

        $this->fail(
            'Expected an unapproved canonical name collision.'
        );
    }

    public function test_catalog_migration_rejects_approved_physics_uuid_when_identity_name_does_not_match(): void
    {
        $this->rollBackCatalogForMigrationScenario();

        $physicsId =
            '01a075f1-d4bb-72dc-ac69-7332950b8408';

        DB::table('subjects')->insert([
            'id' => $physicsId,
            'name' => 'Legacy Subject With Wrong Identity',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/'
            .'2026_09_11_000000_add_subject_catalog_and_education_stages.php'
        );

        try {
            $migration->up();
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'CDA-008 approved Subject identity mismatch: physics',
                $exception->getMessage(),
            );

            $this->assertDatabaseHas(
                'subjects',
                [
                    'id' => $physicsId,
                    'name' => 'Legacy Subject With Wrong Identity',
                ],
            );

            $this->assertFalse(
                DB::getSchemaBuilder()
                    ->hasTable('education_stages'),
            );

            $this->assertFalse(
                DB::getSchemaBuilder()
                    ->hasColumn(
                        'subjects',
                        'code',
                    ),
            );

            return;
        }

        $this->fail(
            'Expected approved Physics UUID identity mismatch.'
        );
    }

    private function rollBackCatalogForMigrationScenario(): void
    {
        $integrityMigration = require database_path(
            'migrations/'
            .'2026_09_11_001000_add_subject_catalog_integrity_triggers.php'
        );

        $catalogMigration = require database_path(
            'migrations/'
            .'2026_09_11_000000_add_subject_catalog_and_education_stages.php'
        );

        $integrityMigration->down();
        $catalogMigration->down();
    }

    private function assertSqlState23514(
        callable $operation,
    ): void {
        $this->assertSqlState(
            '23514',
            $operation,
        );
    }

    private function assertSqlState(
        string $expected,
        callable $operation,
    ): void {
        try {
            DB::transaction(
                function () use (
                    $operation
                ): void {
                    $operation();
                }
            );
        } catch (QueryException $exception) {
            $sqlState =
                $exception->errorInfo[0]
                ?? null;

            $this->assertSame(
                $expected,
                $sqlState,
            );

            return;
        }

        $this->fail(
            "Expected SQLSTATE {$expected} was not raised."
        );
    }

    private function subjectId(
        string $code,
    ): string {
        $id = DB::table('subjects')
            ->where('code', $code)
            ->value('id');

        $this->assertNotNull($id);

        return (string) $id;
    }

    private function stageId(
        string $code,
    ): string {
        $id = DB::table('education_stages')
            ->where('code', $code)
            ->value('id');

        $this->assertNotNull($id);

        return (string) $id;
    }

    private function legacySubject(): string
    {
        $id = (string) Str::uuid();

        DB::table('subjects')->insert([
            'id' => $id,
            'name' => 'Legacy '.Str::random(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function curriculum(
        string $subjectId,
        ?string $stageId,
        string $name,
    ): string {
        $id = (string) Str::uuid();

        DB::table('curricula')->insert([
            'id' => $id,
            'subject_id' => $subjectId,
            'education_stage_id' => $stageId,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

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

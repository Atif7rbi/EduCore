<?php

namespace Tests\Concerns;

use App\Models\Curriculum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

trait CreatesHistoricalOwnerlessCurriculumFixtures
{
    protected function canonicalSubjectId(
        string $code = 'mathematics',
    ): string {
        $subjectId = DB::table('subjects')
            ->where('code', $code)
            ->where('status', 'active')
            ->value('id');

        if (! is_string($subjectId) || $subjectId === '') {
            throw new RuntimeException(
                "Expected active canonical Subject: {$code}."
            );
        }

        return $subjectId;
    }

    protected function createHistoricalOwnerlessCurriculumFixture(
        string $name,
        string $subjectCode = 'mathematics',
        ?string $educationStageId = null,
    ): Curriculum {
        $database = DB::selectOne(
            'SELECT current_database() AS database_name'
        );

        if (
            ! is_object($database)
            || ($database->database_name ?? null)
                !== 'sewaellf_educore_test'
        ) {
            throw new RuntimeException(
                'Historical ownerless Curriculum fixtures may only be created in sewaellf_educore_test.'
            );
        }

        $subjectId =
            $this->canonicalSubjectId($subjectCode);

        $curriculumId = (string) Str::uuid();

        /*
         * This fixture reconstructs a historical row that existed
         * before the Phase E forward-only ownership migration.
         *
         * Production runtime code cannot create this state.
         */
        DB::statement(
            'ALTER TABLE curricula DISABLE TRIGGER trg_curricula_ownership_integrity'
        );

        try {
            DB::table('curricula')->insert([
                'id' => $curriculumId,
                'subject_id' => $subjectId,
                'education_stage_id' => $educationStageId,
                'teacher_subject_assignment_id' => null,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::statement(
                'ALTER TABLE curricula ENABLE TRIGGER trg_curricula_ownership_integrity'
            );
        }

        $curriculum = Curriculum::query()
            ->find($curriculumId);

        if (! $curriculum instanceof Curriculum) {
            throw new RuntimeException(
                'Historical ownerless Curriculum fixture was not created.'
            );
        }

        return $curriculum;
    }
}

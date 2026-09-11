<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(
                'LOCK TABLE subjects IN ACCESS EXCLUSIVE MODE'
            );

            DB::statement(
                'LOCK TABLE curricula IN ACCESS EXCLUSIVE MODE'
            );

            $canonicalSubjects = [
                [
                    'code' => 'mathematics',
                    'name' => 'الرياضيات',
                    'icon_key' => 'subjects/mathematics/icon',
                    'thumbnail_key' => 'subjects/mathematics/thumbnail',
                    'sort_order' => 10,
                ],
                [
                    'code' => 'physics',
                    'name' => 'الفيزياء',
                    'icon_key' => 'subjects/physics/icon',
                    'thumbnail_key' => 'subjects/physics/thumbnail',
                    'sort_order' => 20,
                ],
                [
                    'code' => 'biology',
                    'name' => 'الأحياء',
                    'icon_key' => 'subjects/biology/icon',
                    'thumbnail_key' => 'subjects/biology/thumbnail',
                    'sort_order' => 30,
                ],
                [
                    'code' => 'chemistry',
                    'name' => 'الكيمياء',
                    'icon_key' => 'subjects/chemistry/icon',
                    'thumbnail_key' => 'subjects/chemistry/thumbnail',
                    'sort_order' => 40,
                ],
                [
                    'code' => 'english_language',
                    'name' => 'اللغة الإنجليزية',
                    'icon_key' => 'subjects/english_language/icon',
                    'thumbnail_key' => 'subjects/english_language/thumbnail',
                    'sort_order' => 50,
                ],
                [
                    'code' => 'arabic_language',
                    'name' => 'اللغة العربية',
                    'icon_key' => 'subjects/arabic_language/icon',
                    'thumbnail_key' => 'subjects/arabic_language/thumbnail',
                    'sort_order' => 60,
                ],
            ];

            $canonicalNames = array_column(
                $canonicalSubjects,
                'name',
            );

            $nameCollisions = DB::table('subjects')
                ->whereIn('name', $canonicalNames)
                ->orderBy('name')
                ->pluck('name')
                ->all();

            if ($nameCollisions !== []) {
                throw new RuntimeException(
                    'CDA-008 canonical Subject name collision: '
                    .implode(', ', $nameCollisions)
                );
            }

            DB::unprepared(<<<'SQL'
ALTER TABLE subjects
    ADD COLUMN code TEXT NULL,
    ADD COLUMN icon_key TEXT NULL,
    ADD COLUMN thumbnail_key TEXT NULL,
    ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN status TEXT NOT NULL DEFAULT 'active';

ALTER TABLE subjects
    ADD CONSTRAINT chk_subjects_code_format
        CHECK (
            code IS NULL
            OR code ~ '^[a-z][a-z0-9_]*$'
        ),
    ADD CONSTRAINT chk_subjects_sort_order
        CHECK (sort_order >= 0),
    ADD CONSTRAINT chk_subjects_status
        CHECK (
            status IN ('active', 'inactive')
        ),
    ADD CONSTRAINT uq_subjects_code
        UNIQUE (code);

CREATE TABLE education_stages (
    id UUID PRIMARY KEY,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_education_stages_code_format
        CHECK (
            code ~ '^[a-z][a-z0-9_]*$'
        ),

    CONSTRAINT chk_education_stages_sort_order
        CHECK (sort_order >= 0),

    CONSTRAINT chk_education_stages_status
        CHECK (
            status IN ('active', 'inactive')
        ),

    CONSTRAINT uq_education_stages_code
        UNIQUE (code)
);

ALTER TABLE curricula
    ADD COLUMN education_stage_id UUID NULL;

ALTER TABLE curricula
    ADD CONSTRAINT fk_curricula_education_stage
        FOREIGN KEY (education_stage_id)
        REFERENCES education_stages(id)
        ON DELETE RESTRICT;

CREATE INDEX idx_curricula_education_stage_id
    ON curricula (education_stage_id);
SQL);

            $now = now();

            foreach ($canonicalSubjects as $subject) {
                DB::table('subjects')->insert([
                    'id' => (string) Str::uuid(),
                    'name' => $subject['name'],
                    'code' => $subject['code'],
                    'icon_key' => $subject['icon_key'],
                    'thumbnail_key' => $subject['thumbnail_key'],
                    'sort_order' => $subject['sort_order'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => null,
                ]);
            }

            $educationStages = [
                [
                    'code' => 'primary',
                    'name' => 'المرحلة الابتدائية',
                    'sort_order' => 10,
                ],
                [
                    'code' => 'middle',
                    'name' => 'المرحلة المتوسطة',
                    'sort_order' => 20,
                ],
                [
                    'code' => 'secondary',
                    'name' => 'المرحلة الثانوية',
                    'sort_order' => 30,
                ],
            ];

            foreach ($educationStages as $stage) {
                DB::table('education_stages')->insert([
                    'id' => (string) Str::uuid(),
                    'code' => $stage['code'],
                    'name' => $stage['name'],
                    'sort_order' => $stage['sort_order'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => null,
                ]);
            }
        }, 1);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement(
                'LOCK TABLE subjects IN ACCESS EXCLUSIVE MODE'
            );

            DB::statement(
                'LOCK TABLE education_stages '
                .'IN ACCESS EXCLUSIVE MODE'
            );

            DB::statement(
                'LOCK TABLE curricula IN ACCESS EXCLUSIVE MODE'
            );

            $canonicalCodes = [
                'mathematics',
                'physics',
                'biology',
                'chemistry',
                'english_language',
                'arabic_language',
            ];

            $stageCodes = [
                'primary',
                'middle',
                'secondary',
            ];

            $canonicalSubjectIds = DB::table('subjects')
                ->whereIn('code', $canonicalCodes)
                ->pluck('id');

            if (
                $canonicalSubjectIds->isNotEmpty()
                && DB::table('curricula')
                    ->whereIn(
                        'subject_id',
                        $canonicalSubjectIds->all(),
                    )
                    ->exists()
            ) {
                throw new RuntimeException(
                    'Cannot roll back CDA-008: '
                    .'canonical Subject is in use.'
                );
            }

            if (
                DB::table('curricula')
                    ->whereNotNull('education_stage_id')
                    ->exists()
            ) {
                throw new RuntimeException(
                    'Cannot roll back CDA-008: '
                    .'EducationStage classification is in use.'
                );
            }

            if (
                DB::table('education_stages')
                    ->whereNotIn('code', $stageCodes)
                    ->exists()
            ) {
                throw new RuntimeException(
                    'Cannot roll back CDA-008: '
                    .'additional EducationStages exist.'
                );
            }

            if (
                DB::table('subjects')
                    ->whereNotNull('code')
                    ->whereNotIn('code', $canonicalCodes)
                    ->exists()
            ) {
                throw new RuntimeException(
                    'Cannot roll back CDA-008: '
                    .'additional canonical Subjects exist.'
                );
            }

            $legacyMetadataChanged = DB::table('subjects')
                ->whereNull('code')
                ->where(function ($query): void {
                    $query
                        ->whereNotNull('icon_key')
                        ->orWhereNotNull('thumbnail_key')
                        ->orWhere('sort_order', '<>', 0)
                        ->orWhere('status', '<>', 'active');
                })
                ->exists();

            if ($legacyMetadataChanged) {
                throw new RuntimeException(
                    'Cannot roll back CDA-008: '
                    .'legacy Subject catalog metadata exists.'
                );
            }

            DB::table('subjects')
                ->whereIn('code', $canonicalCodes)
                ->delete();

            DB::unprepared(<<<'SQL'
DROP INDEX idx_curricula_education_stage_id;

ALTER TABLE curricula
    DROP CONSTRAINT fk_curricula_education_stage;

ALTER TABLE curricula
    DROP COLUMN education_stage_id;

DROP TABLE education_stages;

ALTER TABLE subjects
    DROP CONSTRAINT uq_subjects_code,
    DROP CONSTRAINT chk_subjects_status,
    DROP CONSTRAINT chk_subjects_sort_order,
    DROP CONSTRAINT chk_subjects_code_format;

ALTER TABLE subjects
    DROP COLUMN status,
    DROP COLUMN sort_order,
    DROP COLUMN thumbnail_key,
    DROP COLUMN icon_key,
    DROP COLUMN code;
SQL);
        }, 1);
    }
};

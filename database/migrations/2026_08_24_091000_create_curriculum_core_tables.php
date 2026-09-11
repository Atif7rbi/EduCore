<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore curriculum migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE subjects (
    id UUID PRIMARY KEY,
    name TEXT NOT NULL UNIQUE,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL
);

CREATE TABLE curricula (
    id UUID PRIMARY KEY,
    subject_id UUID NOT NULL,
    name TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_curricula_subject
        FOREIGN KEY (subject_id)
        REFERENCES subjects(id)
        ON DELETE RESTRICT
);

CREATE TABLE curriculum_versions (
    id UUID PRIMARY KEY,
    curriculum_id UUID NOT NULL,
    version_number INTEGER NOT NULL,
    label TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_curriculum_versions_version_number
        CHECK (version_number >= 1),

    CONSTRAINT chk_curriculum_versions_status
        CHECK (status IN ('draft', 'published', 'retired')),

    CONSTRAINT uq_curriculum_versions_curriculum_version
        UNIQUE (curriculum_id, version_number),

    CONSTRAINT fk_curriculum_versions_curriculum
        FOREIGN KEY (curriculum_id)
        REFERENCES curricula(id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS curriculum_versions;
DROP TABLE IF EXISTS curricula;
DROP TABLE IF EXISTS subjects;
SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore practice migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE practice_activities (
    id UUID PRIMARY KEY,
    curriculum_version_id UUID NOT NULL,
    lesson_id UUID NULL,
    name TEXT NOT NULL,
    description TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_practice_activities_status
        CHECK (status IN ('active', 'archived')),

    CONSTRAINT uq_practice_activities_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_practice_activities_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_practice_activities_lesson_version
        FOREIGN KEY (lesson_id, curriculum_version_id)
        REFERENCES lessons(id, curriculum_version_id)
        ON DELETE RESTRICT
);

CREATE TABLE practice_activity_items (
    id UUID PRIMARY KEY,
    practice_activity_id UUID NOT NULL,
    assessment_item_revision_id UUID NOT NULL,
    assessment_item_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    display_order INTEGER NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_practice_activity_items_display_order
        CHECK (display_order >= 0),

    CONSTRAINT uq_practice_activity_items_activity_revision
        UNIQUE (practice_activity_id, assessment_item_revision_id),

    CONSTRAINT uq_practice_activity_items_activity_item
        UNIQUE (practice_activity_id, assessment_item_id),

    CONSTRAINT uq_practice_activity_items_activity_order
        UNIQUE (practice_activity_id, display_order),

    CONSTRAINT fk_practice_activity_items_activity_version
        FOREIGN KEY (practice_activity_id, curriculum_version_id)
        REFERENCES practice_activities(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_practice_activity_items_revision_version
        FOREIGN KEY (assessment_item_revision_id, curriculum_version_id)
        REFERENCES assessment_item_revisions(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_practice_activity_items_revision_item
        FOREIGN KEY (assessment_item_revision_id, assessment_item_id)
        REFERENCES assessment_item_revisions(id, assessment_item_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_practice_activity_items_item_version
        FOREIGN KEY (assessment_item_id, curriculum_version_id)
        REFERENCES assessment_items(id, curriculum_version_id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS practice_activity_items;
DROP TABLE IF EXISTS practice_activities;
SQL);
    }
};

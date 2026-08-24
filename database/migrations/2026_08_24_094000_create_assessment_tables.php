<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore assessment migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE assessment_items (
    id UUID PRIMARY KEY,
    curriculum_version_id UUID NOT NULL,
    item_type TEXT NOT NULL,
    internal_label TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    published_revision_id UUID NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_assessment_items_status
        CHECK (status IN ('draft', 'published', 'retired')),

    CONSTRAINT uq_assessment_items_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_assessment_items_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT
);

CREATE TABLE assessment_item_revisions (
    id UUID PRIMARY KEY,
    assessment_item_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    revision_number INTEGER NOT NULL,
    primary_topic_id UUID NULL,
    difficulty TEXT NOT NULL,
    content_payload JSONB NOT NULL,
    content_schema_version INTEGER NOT NULL,
    scoring_payload JSONB NOT NULL,
    scoring_schema_version INTEGER NOT NULL,
    released_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_assessment_item_revisions_revision_number
        CHECK (revision_number >= 1),

    CONSTRAINT chk_assessment_item_revisions_difficulty
        CHECK (difficulty IN ('easy', 'medium', 'hard')),

    CONSTRAINT chk_assessment_item_revisions_content_schema_version
        CHECK (content_schema_version >= 1),

    CONSTRAINT chk_assessment_item_revisions_scoring_schema_version
        CHECK (scoring_schema_version >= 1),

    CONSTRAINT chk_assessment_item_revisions_content_payload_object
        CHECK (jsonb_typeof(content_payload) = 'object'),

    CONSTRAINT chk_assessment_item_revisions_scoring_payload_object
        CHECK (jsonb_typeof(scoring_payload) = 'object'),

    CONSTRAINT uq_assessment_item_revisions_item_revision
        UNIQUE (assessment_item_id, revision_number),

    CONSTRAINT uq_assessment_item_revisions_id_item
        UNIQUE (id, assessment_item_id),

    CONSTRAINT uq_assessment_item_revisions_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_assessment_item_revisions_item_version
        FOREIGN KEY (assessment_item_id, curriculum_version_id)
        REFERENCES assessment_items(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_assessment_item_revisions_primary_topic_version
        FOREIGN KEY (primary_topic_id, curriculum_version_id)
        REFERENCES topics(id, curriculum_version_id)
        ON DELETE RESTRICT
);

ALTER TABLE assessment_items
    ADD CONSTRAINT fk_assessment_items_published_revision
    FOREIGN KEY (published_revision_id, id)
    REFERENCES assessment_item_revisions(id, assessment_item_id)
    ON DELETE RESTRICT;

CREATE TABLE assessment_item_revision_skills (
    id UUID PRIMARY KEY,
    assessment_item_revision_id UUID NOT NULL,
    skill_version_placement_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    role TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_assessment_item_revision_skills_role
        CHECK (role IN ('primary', 'supporting')),

    CONSTRAINT uq_assessment_item_revision_skills_revision_placement
        UNIQUE (assessment_item_revision_id, skill_version_placement_id),

    CONSTRAINT fk_assessment_item_revision_skills_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_assessment_item_revision_skills_revision_version
        FOREIGN KEY (assessment_item_revision_id, curriculum_version_id)
        REFERENCES assessment_item_revisions(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_assessment_item_revision_skills_placement_version
        FOREIGN KEY (skill_version_placement_id, curriculum_version_id)
        REFERENCES skill_version_placements(id, curriculum_version_id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS assessment_item_revision_skills;
ALTER TABLE IF EXISTS assessment_items
    DROP CONSTRAINT IF EXISTS fk_assessment_items_published_revision;
DROP TABLE IF EXISTS assessment_item_revisions;
DROP TABLE IF EXISTS assessment_items;
SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore exam-generation migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE exam_templates (
    id UUID PRIMARY KEY,
    curriculum_version_id UUID NOT NULL,
    name TEXT NOT NULL,
    description TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    published_version_id UUID NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_exam_templates_status
        CHECK (status IN ('active', 'archived')),

    CONSTRAINT uq_exam_templates_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_exam_templates_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT
);

CREATE TABLE exam_template_versions (
    id UUID PRIMARY KEY,
    exam_template_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    version_number INTEGER NOT NULL,
    label TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    rules_payload JSONB NOT NULL,
    rules_schema_version INTEGER NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_exam_template_versions_version_number
        CHECK (version_number >= 1),

    CONSTRAINT chk_exam_template_versions_rules_schema_version
        CHECK (rules_schema_version >= 1),

    CONSTRAINT chk_exam_template_versions_status
        CHECK (status IN ('draft', 'published', 'retired')),

    CONSTRAINT chk_exam_template_versions_rules_payload_object
        CHECK (jsonb_typeof(rules_payload) = 'object'),

    CONSTRAINT uq_exam_template_versions_template_version
        UNIQUE (exam_template_id, version_number),

    CONSTRAINT uq_exam_template_versions_id_template
        UNIQUE (id, exam_template_id),

    CONSTRAINT uq_exam_template_versions_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_exam_template_versions_template_version
        FOREIGN KEY (exam_template_id, curriculum_version_id)
        REFERENCES exam_templates(id, curriculum_version_id)
        ON DELETE RESTRICT
);

ALTER TABLE exam_templates
    ADD CONSTRAINT fk_exam_templates_published_version
    FOREIGN KEY (published_version_id, id)
    REFERENCES exam_template_versions(id, exam_template_id)
    ON DELETE RESTRICT;

CREATE TABLE exam_generations (
    id UUID PRIMARY KEY,
    exam_template_version_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    rules_snapshot JSONB NOT NULL,
    rules_schema_version INTEGER NOT NULL,
    generator_version TEXT NOT NULL,
    seed TEXT NOT NULL,
    generated_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_exam_generations_rules_schema_version
        CHECK (rules_schema_version >= 1),

    CONSTRAINT chk_exam_generations_rules_snapshot_object
        CHECK (jsonb_typeof(rules_snapshot) = 'object'),

    CONSTRAINT uq_exam_generations_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_exam_generations_template_version
        FOREIGN KEY (exam_template_version_id, curriculum_version_id)
        REFERENCES exam_template_versions(id, curriculum_version_id)
        ON DELETE RESTRICT
);

CREATE TABLE exam_generation_items (
    id UUID PRIMARY KEY,
    exam_generation_id UUID NOT NULL,
    assessment_item_revision_id UUID NOT NULL,
    assessment_item_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    selection_position INTEGER NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_exam_generation_items_selection_position
        CHECK (selection_position >= 0),

    CONSTRAINT uq_exam_generation_items_generation_position
        UNIQUE (exam_generation_id, selection_position),

    CONSTRAINT uq_exam_generation_items_generation_revision
        UNIQUE (exam_generation_id, assessment_item_revision_id),

    CONSTRAINT uq_exam_generation_items_generation_item
        UNIQUE (exam_generation_id, assessment_item_id),

    CONSTRAINT uq_exam_generation_items_exact_provenance
        UNIQUE (
            id,
            exam_generation_id,
            assessment_item_revision_id,
            assessment_item_id,
            curriculum_version_id
        ),

    CONSTRAINT fk_exam_generation_items_generation_version
        FOREIGN KEY (exam_generation_id, curriculum_version_id)
        REFERENCES exam_generations(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_exam_generation_items_revision_version
        FOREIGN KEY (assessment_item_revision_id, curriculum_version_id)
        REFERENCES assessment_item_revisions(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_exam_generation_items_revision_item
        FOREIGN KEY (assessment_item_revision_id, assessment_item_id)
        REFERENCES assessment_item_revisions(id, assessment_item_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_exam_generation_items_item_version
        FOREIGN KEY (assessment_item_id, curriculum_version_id)
        REFERENCES assessment_items(id, curriculum_version_id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS exam_generation_items;
DROP TABLE IF EXISTS exam_generations;
ALTER TABLE IF EXISTS exam_templates
    DROP CONSTRAINT IF EXISTS fk_exam_templates_published_version;
DROP TABLE IF EXISTS exam_template_versions;
DROP TABLE IF EXISTS exam_templates;
SQL);
    }
};

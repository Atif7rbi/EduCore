<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore taxonomy migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE skills (
    id UUID PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL
);

CREATE TABLE topics (
    id UUID PRIMARY KEY,
    curriculum_version_id UUID NOT NULL,
    name TEXT NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_topics_display_order
        CHECK (display_order >= 0),

    CONSTRAINT uq_topics_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_topics_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT
);

CREATE TABLE skill_lineages (
    id UUID PRIMARY KEY,
    source_skill_id UUID NOT NULL,
    target_skill_id UUID NOT NULL,
    lineage_type TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_skill_lineages_distinct_skills
        CHECK (source_skill_id <> target_skill_id),

    CONSTRAINT chk_skill_lineages_type
        CHECK (lineage_type IN ('replaced_by', 'split_into', 'merged_into')),

    CONSTRAINT uq_skill_lineages_relation
        UNIQUE (source_skill_id, target_skill_id, lineage_type),

    CONSTRAINT fk_skill_lineages_source
        FOREIGN KEY (source_skill_id)
        REFERENCES skills(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_skill_lineages_target
        FOREIGN KEY (target_skill_id)
        REFERENCES skills(id)
        ON DELETE RESTRICT
);

CREATE TABLE skill_version_placements (
    id UUID PRIMARY KEY,
    skill_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT uq_skill_version_placements_skill_version
        UNIQUE (skill_id, curriculum_version_id),

    CONSTRAINT uq_skill_version_placements_id_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_skill_version_placements_skill
        FOREIGN KEY (skill_id)
        REFERENCES skills(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_skill_version_placements_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT
);

CREATE TABLE skill_home_topics (
    id UUID PRIMARY KEY,
    placement_id UUID NOT NULL,
    topic_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT uq_skill_home_topics_placement_topic
        UNIQUE (placement_id, topic_id),

    CONSTRAINT fk_skill_home_topics_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_skill_home_topics_placement_version
        FOREIGN KEY (placement_id, curriculum_version_id)
        REFERENCES skill_version_placements(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_skill_home_topics_topic_version
        FOREIGN KEY (topic_id, curriculum_version_id)
        REFERENCES topics(id, curriculum_version_id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS skill_home_topics;
DROP TABLE IF EXISTS skill_version_placements;
DROP TABLE IF EXISTS skill_lineages;
DROP TABLE IF EXISTS topics;
DROP TABLE IF EXISTS skills;
SQL);
    }
};

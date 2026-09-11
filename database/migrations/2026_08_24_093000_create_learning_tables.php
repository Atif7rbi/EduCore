<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore learning migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE lessons (
    id UUID PRIMARY KEY,
    curriculum_version_id UUID NOT NULL,
    title TEXT NOT NULL,
    description TEXT NULL,
    status TEXT NOT NULL DEFAULT 'draft',
    display_order INTEGER NOT NULL DEFAULT 0,
    published_revision_id UUID NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_lessons_status
        CHECK (status IN ('draft', 'published', 'retired')),

    CONSTRAINT chk_lessons_display_order
        CHECK (display_order >= 0),

    CONSTRAINT uq_lessons_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_lessons_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT
);

CREATE TABLE lesson_revisions (
    id UUID PRIMARY KEY,
    lesson_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    revision_number INTEGER NOT NULL,
    primary_topic_id UUID NOT NULL,
    content_payload JSONB NOT NULL,
    content_schema_version INTEGER NOT NULL,
    released_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_lesson_revisions_revision_number
        CHECK (revision_number >= 1),

    CONSTRAINT chk_lesson_revisions_content_schema_version
        CHECK (content_schema_version >= 1),

    CONSTRAINT chk_lesson_revisions_content_payload_object
        CHECK (jsonb_typeof(content_payload) = 'object'),

    CONSTRAINT uq_lesson_revisions_lesson_revision
        UNIQUE (lesson_id, revision_number),

    CONSTRAINT uq_lesson_revisions_id_lesson
        UNIQUE (id, lesson_id),

    CONSTRAINT uq_lesson_revisions_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT fk_lesson_revisions_lesson_version
        FOREIGN KEY (lesson_id, curriculum_version_id)
        REFERENCES lessons(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_lesson_revisions_primary_topic_version
        FOREIGN KEY (primary_topic_id, curriculum_version_id)
        REFERENCES topics(id, curriculum_version_id)
        ON DELETE RESTRICT
);

ALTER TABLE lessons
    ADD CONSTRAINT fk_lessons_published_revision
    FOREIGN KEY (published_revision_id, id)
    REFERENCES lesson_revisions(id, lesson_id)
    ON DELETE RESTRICT;

CREATE TABLE lesson_revision_skills (
    id UUID PRIMARY KEY,
    lesson_revision_id UUID NOT NULL,
    skill_version_placement_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT uq_lesson_revision_skills_revision_placement
        UNIQUE (lesson_revision_id, skill_version_placement_id),

    CONSTRAINT fk_lesson_revision_skills_curriculum_version
        FOREIGN KEY (curriculum_version_id)
        REFERENCES curriculum_versions(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_lesson_revision_skills_revision_version
        FOREIGN KEY (lesson_revision_id, curriculum_version_id)
        REFERENCES lesson_revisions(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_lesson_revision_skills_placement_version
        FOREIGN KEY (skill_version_placement_id, curriculum_version_id)
        REFERENCES skill_version_placements(id, curriculum_version_id)
        ON DELETE RESTRICT
);

CREATE TABLE lesson_progresses (
    id UUID PRIMARY KEY,
    learner_profile_id UUID NOT NULL,
    lesson_revision_id UUID NOT NULL,
    status TEXT NOT NULL DEFAULT 'in_progress',
    started_at TIMESTAMPTZ NOT NULL,
    completed_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_lesson_progresses_status
        CHECK (status IN ('in_progress', 'completed')),

    CONSTRAINT chk_lesson_progresses_status_completed_at
        CHECK (
            (status = 'in_progress' AND completed_at IS NULL)
            OR
            (status = 'completed' AND completed_at IS NOT NULL)
        ),

    CONSTRAINT chk_lesson_progresses_completed_after_started
        CHECK (completed_at IS NULL OR completed_at >= started_at),

    CONSTRAINT uq_lesson_progresses_learner_revision
        UNIQUE (learner_profile_id, lesson_revision_id),

    CONSTRAINT fk_lesson_progresses_learner_profile
        FOREIGN KEY (learner_profile_id)
        REFERENCES learner_profiles(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_lesson_progresses_lesson_revision
        FOREIGN KEY (lesson_revision_id)
        REFERENCES lesson_revisions(id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS lesson_progresses;
DROP TABLE IF EXISTS lesson_revision_skills;
ALTER TABLE IF EXISTS lessons
    DROP CONSTRAINT IF EXISTS fk_lessons_published_revision;
DROP TABLE IF EXISTS lesson_revisions;
DROP TABLE IF EXISTS lessons;
SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore attempt migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE attempts (
    id UUID PRIMARY KEY,
    learner_profile_id UUID NOT NULL,
    exam_generation_id UUID NULL,
    practice_activity_id UUID NULL,
    curriculum_version_id UUID NOT NULL,
    status TEXT NOT NULL DEFAULT 'in_progress',
    started_at TIMESTAMPTZ NULL,
    finalized_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_attempts_status
        CHECK (status IN ('in_progress', 'submitted', 'abandoned')),

    CONSTRAINT chk_attempts_exactly_one_source
        CHECK (
            (exam_generation_id IS NOT NULL AND practice_activity_id IS NULL)
            OR
            (exam_generation_id IS NULL AND practice_activity_id IS NOT NULL)
        ),

    CONSTRAINT chk_attempts_status_finalized_at
        CHECK (
            (status = 'in_progress' AND finalized_at IS NULL)
            OR
            (status IN ('submitted', 'abandoned') AND finalized_at IS NOT NULL)
        ),

    CONSTRAINT chk_attempts_finalized_after_started
        CHECK (finalized_at IS NULL OR finalized_at >= started_at),

    CONSTRAINT uq_attempts_id_curriculum_version
        UNIQUE (id, curriculum_version_id),

    CONSTRAINT uq_attempts_exam_provenance
        UNIQUE (id, exam_generation_id, curriculum_version_id),

    CONSTRAINT fk_attempts_learner_profile
        FOREIGN KEY (learner_profile_id)
        REFERENCES learner_profiles(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempts_exam_generation_version
        FOREIGN KEY (exam_generation_id, curriculum_version_id)
        REFERENCES exam_generations(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempts_practice_activity_version
        FOREIGN KEY (practice_activity_id, curriculum_version_id)
        REFERENCES practice_activities(id, curriculum_version_id)
        ON DELETE RESTRICT
);

CREATE UNIQUE INDEX uq_attempts_exam_generation
    ON attempts (exam_generation_id)
    WHERE exam_generation_id IS NOT NULL;

CREATE TABLE attempt_items (
    id UUID PRIMARY KEY,
    attempt_id UUID NOT NULL,
    assessment_item_revision_id UUID NOT NULL,
    assessment_item_id UUID NOT NULL,
    curriculum_version_id UUID NOT NULL,
    exam_generation_id UUID NULL,
    exam_generation_item_id UUID NULL,
    presentation_position INTEGER NOT NULL,
    presented_payload JSONB NOT NULL,
    presented_schema_version INTEGER NOT NULL,
    scoring_snapshot JSONB NOT NULL,
    scoring_schema_version INTEGER NOT NULL,
    primary_topic_id UUID NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_attempt_items_presentation_position
        CHECK (presentation_position >= 0),

    CONSTRAINT chk_attempt_items_presented_schema_version
        CHECK (presented_schema_version >= 1),

    CONSTRAINT chk_attempt_items_scoring_schema_version
        CHECK (scoring_schema_version >= 1),

    CONSTRAINT chk_attempt_items_presented_payload_object
        CHECK (jsonb_typeof(presented_payload) = 'object'),

    CONSTRAINT chk_attempt_items_scoring_snapshot_object
        CHECK (jsonb_typeof(scoring_snapshot) = 'object'),

    CONSTRAINT chk_attempt_items_exam_provenance_pair
        CHECK (
            (exam_generation_id IS NULL AND exam_generation_item_id IS NULL)
            OR
            (exam_generation_id IS NOT NULL AND exam_generation_item_id IS NOT NULL)
        ),

    CONSTRAINT uq_attempt_items_attempt_position
        UNIQUE (attempt_id, presentation_position),

    CONSTRAINT uq_attempt_items_attempt_revision
        UNIQUE (attempt_id, assessment_item_revision_id),

    CONSTRAINT uq_attempt_items_attempt_item
        UNIQUE (attempt_id, assessment_item_id),

    CONSTRAINT fk_attempt_items_attempt_version
        FOREIGN KEY (attempt_id, curriculum_version_id)
        REFERENCES attempts(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempt_items_attempt_exam_provenance
        FOREIGN KEY (attempt_id, exam_generation_id, curriculum_version_id)
        REFERENCES attempts(id, exam_generation_id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempt_items_exact_generation_item
        FOREIGN KEY (
            exam_generation_item_id,
            exam_generation_id,
            assessment_item_revision_id,
            assessment_item_id,
            curriculum_version_id
        )
        REFERENCES exam_generation_items (
            id,
            exam_generation_id,
            assessment_item_revision_id,
            assessment_item_id,
            curriculum_version_id
        )
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempt_items_revision_version
        FOREIGN KEY (assessment_item_revision_id, curriculum_version_id)
        REFERENCES assessment_item_revisions(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempt_items_revision_item
        FOREIGN KEY (assessment_item_revision_id, assessment_item_id)
        REFERENCES assessment_item_revisions(id, assessment_item_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempt_items_item_version
        FOREIGN KEY (assessment_item_id, curriculum_version_id)
        REFERENCES assessment_items(id, curriculum_version_id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempt_items_primary_topic_version
        FOREIGN KEY (primary_topic_id, curriculum_version_id)
        REFERENCES topics(id, curriculum_version_id)
        ON DELETE RESTRICT
);

CREATE TABLE attempt_item_classification_skills (
    id UUID PRIMARY KEY,
    attempt_item_id UUID NOT NULL,
    skill_id UUID NOT NULL,
    role TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_attempt_item_classification_skills_role
        CHECK (role IN ('primary', 'supporting')),

    CONSTRAINT uq_attempt_item_classification_skills_item_skill
        UNIQUE (attempt_item_id, skill_id),

    CONSTRAINT fk_attempt_item_classification_skills_attempt_item
        FOREIGN KEY (attempt_item_id)
        REFERENCES attempt_items(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_attempt_item_classification_skills_skill
        FOREIGN KEY (skill_id)
        REFERENCES skills(id)
        ON DELETE RESTRICT
);

CREATE TABLE attempt_responses (
    id UUID PRIMARY KEY,
    attempt_item_id UUID NOT NULL UNIQUE,
    response_payload JSONB NULL,
    answer_change_count INTEGER NOT NULL DEFAULT 0,
    time_spent_ms BIGINT NOT NULL DEFAULT 0,
    original_is_correct BOOLEAN NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_attempt_responses_answer_change_count
        CHECK (answer_change_count >= 0),

    CONSTRAINT chk_attempt_responses_time_spent_ms
        CHECK (time_spent_ms >= 0),

    CONSTRAINT fk_attempt_responses_attempt_item
        FOREIGN KEY (attempt_item_id)
        REFERENCES attempt_items(id)
        ON DELETE RESTRICT
);

CREATE TABLE regrade_corrections (
    id UUID PRIMARY KEY,
    attempt_response_id UUID NOT NULL,
    correction_number INTEGER NOT NULL,
    corrected_is_correct BOOLEAN NOT NULL,
    reason TEXT NOT NULL,
    corrected_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT chk_regrade_corrections_number
        CHECK (correction_number >= 1),

    CONSTRAINT uq_regrade_corrections_response_number
        UNIQUE (attempt_response_id, correction_number),

    CONSTRAINT fk_regrade_corrections_attempt_response
        FOREIGN KEY (attempt_response_id)
        REFERENCES attempt_responses(id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS regrade_corrections;
DROP TABLE IF EXISTS attempt_responses;
DROP TABLE IF EXISTS attempt_item_classification_skills;
DROP TABLE IF EXISTS attempt_items;
DROP INDEX IF EXISTS uq_attempts_exam_generation;
DROP TABLE IF EXISTS attempts;
SQL);
    }
};

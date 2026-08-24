<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore analytics migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE evidence_scopes (
    id UUID PRIMARY KEY,
    label TEXT NULL,
    description TEXT NULL,
    definition_payload JSONB NOT NULL,
    definition_schema_version INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT chk_evidence_scopes_definition_schema_version
        CHECK (definition_schema_version >= 1),

    CONSTRAINT chk_evidence_scopes_definition_payload_object
        CHECK (jsonb_typeof(definition_payload) = 'object'),

    CONSTRAINT chk_evidence_scopes_status
        CHECK (status IN ('active', 'retired'))
);

CREATE TABLE materialized_skill_performances (
    id UUID PRIMARY KEY,
    learner_profile_id UUID NOT NULL,
    skill_id UUID NOT NULL,
    evidence_scope_id UUID NOT NULL,
    single_primary_correct_count BIGINT NOT NULL DEFAULT 0,
    single_primary_answered_count BIGINT NOT NULL DEFAULT 0,
    supporting_positive_count BIGINT NOT NULL DEFAULT 0,
    supporting_exposure_count BIGINT NOT NULL DEFAULT 0,
    last_rebuilt_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT uq_materialized_skill_performances_key
        UNIQUE (learner_profile_id, skill_id, evidence_scope_id),

    CONSTRAINT chk_materialized_skill_performances_nonnegative
        CHECK (
            single_primary_correct_count >= 0
            AND single_primary_answered_count >= 0
            AND supporting_positive_count >= 0
            AND supporting_exposure_count >= 0
        ),

    CONSTRAINT chk_materialized_skill_performances_single_primary_counts
        CHECK (
            single_primary_correct_count <= single_primary_answered_count
        ),

    CONSTRAINT chk_materialized_skill_performances_supporting_counts
        CHECK (
            supporting_positive_count <= supporting_exposure_count
        ),

    CONSTRAINT fk_materialized_skill_performances_learner
        FOREIGN KEY (learner_profile_id)
        REFERENCES learner_profiles(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_materialized_skill_performances_skill
        FOREIGN KEY (skill_id)
        REFERENCES skills(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_materialized_skill_performances_scope
        FOREIGN KEY (evidence_scope_id)
        REFERENCES evidence_scopes(id)
        ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS materialized_skill_performances;
DROP TABLE IF EXISTS evidence_scopes;
SQL);
    }
};

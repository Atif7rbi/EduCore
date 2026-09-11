<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore analytics integrity requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_guard_evidence_scope()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'active' THEN
            RAISE EXCEPTION
                'EvidenceScope must be created active';
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.definition_payload IS DISTINCT FROM NEW.definition_payload
       OR OLD.definition_schema_version IS DISTINCT FROM NEW.definition_schema_version THEN
        RAISE EXCEPTION
            'EvidenceScope definition is immutable';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        IF NOT (
            OLD.status = 'active'
            AND NEW.status = 'retired'
        ) THEN
            RAISE EXCEPTION
                'Invalid EvidenceScope lifecycle transition: % -> %',
                OLD.status,
                NEW.status;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_evidence_scopes_integrity
BEFORE INSERT OR UPDATE ON evidence_scopes
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_evidence_scope();


CREATE OR REPLACE FUNCTION educore_lock_materialized_skill_performance_key()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    lock_key_1 INTEGER;
    lock_key_2 INTEGER;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.learner_profile_id IS DISTINCT FROM NEW.learner_profile_id
           OR OLD.skill_id IS DISTINCT FROM NEW.skill_id
           OR OLD.evidence_scope_id IS DISTINCT FROM NEW.evidence_scope_id THEN
            RAISE EXCEPTION
                'MaterializedSkillPerformance key is immutable';
        END IF;
    END IF;

    /*
     * Serialize rebuild/upsert work for exactly one
     * learner × skill × evidence-scope key.
     *
     * pg_advisory_xact_lock is transaction-scoped and releases
     * automatically on COMMIT / ROLLBACK.
     */
    lock_key_1 := hashtext(
        NEW.learner_profile_id::text
    );

    lock_key_2 := hashtext(
        NEW.skill_id::text
        || ':'
        || NEW.evidence_scope_id::text
    );

    PERFORM pg_advisory_xact_lock(
        lock_key_1,
        lock_key_2
    );

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_materialized_skill_performances_integrity
BEFORE INSERT OR UPDATE
ON materialized_skill_performances
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_materialized_skill_performance_key();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_materialized_skill_performances_integrity
    ON materialized_skill_performances;
DROP FUNCTION IF EXISTS educore_lock_materialized_skill_performance_key();

DROP TRIGGER IF EXISTS trg_evidence_scopes_integrity
    ON evidence_scopes;
DROP FUNCTION IF EXISTS educore_guard_evidence_scope();
SQL);
    }
};

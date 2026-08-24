<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore attempt response integrity requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_guard_attempt_response()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_attempt_id UUID;
    attempt_status TEXT;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'AttemptResponse cannot be deleted';
    END IF;

    IF TG_OP = 'UPDATE'
       AND OLD.attempt_item_id IS DISTINCT FROM NEW.attempt_item_id THEN
        RAISE EXCEPTION
            'AttemptResponse.attempt_item_id is immutable';
    END IF;

    SELECT a.id, a.status
    INTO target_attempt_id, attempt_status
    FROM attempt_items ai
    JOIN attempts a
      ON a.id = ai.attempt_id
    WHERE ai.id = NEW.attempt_item_id
    FOR UPDATE OF a;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'AttemptResponse references an invalid AttemptItem';
    END IF;

    IF attempt_status <> 'in_progress' THEN
        RAISE EXCEPTION
            'AttemptResponse is immutable after Attempt finalization';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_attempt_responses_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON attempt_responses
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_attempt_response();


CREATE OR REPLACE FUNCTION educore_validate_attempt_response_state()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    attempt_status TEXT;
BEGIN
    SELECT a.status
    INTO attempt_status
    FROM attempt_items ai
    JOIN attempts a
      ON a.id = ai.attempt_id
    WHERE ai.id = NEW.attempt_item_id
    FOR UPDATE OF a;

    IF NOT FOUND THEN
        RETURN NEW;
    END IF;

    IF attempt_status = 'in_progress'
       AND NEW.original_is_correct IS NOT NULL THEN
        RAISE EXCEPTION
            'original_is_correct must remain NULL while Attempt is in_progress';
    END IF;

    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ctrg_attempt_response_state
AFTER INSERT OR UPDATE
ON attempt_responses
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_attempt_response_state();


CREATE OR REPLACE FUNCTION educore_guard_attempt_finalization()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.finalized_at IS NOT NULL
       AND OLD.finalized_at IS DISTINCT FROM NEW.finalized_at THEN
        RAISE EXCEPTION
            'Attempt.finalized_at is immutable after finalization';
    END IF;

    IF OLD.status = 'in_progress'
       AND NEW.status IN ('submitted', 'abandoned') THEN

        IF OLD.started_at IS NULL THEN
            RAISE EXCEPTION
                'Unsealed Attempt cannot be finalized';
        END IF;

        IF NEW.finalized_at IS NULL THEN
            RAISE EXCEPTION
                'Finalized Attempt requires finalized_at';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_attempt_finalization_integrity
BEFORE UPDATE ON attempts
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_attempt_finalization();


CREATE OR REPLACE FUNCTION educore_validate_final_attempt(
    target_attempt UUID
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    attempt_record RECORD;
BEGIN
    SELECT *
    INTO attempt_record
    FROM attempts
    WHERE id = target_attempt
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF attempt_record.status = 'in_progress' THEN
        RETURN;
    END IF;

    IF attempt_record.started_at IS NULL THEN
        RAISE EXCEPTION
            'Final Attempt % must be sealed',
            target_attempt;
    END IF;

    IF attempt_record.finalized_at IS NULL THEN
        RAISE EXCEPTION
            'Final Attempt % requires finalized_at',
            target_attempt;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM attempt_items ai
        LEFT JOIN attempt_responses ar
          ON ar.attempt_item_id = ai.id
        WHERE ai.attempt_id = target_attempt
          AND ar.id IS NULL
    ) THEN
        RAISE EXCEPTION
            'Final Attempt requires exactly one Response per AttemptItem';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM attempt_items ai
        JOIN attempt_responses ar
          ON ar.attempt_item_id = ai.id
        WHERE ai.attempt_id = target_attempt
          AND (
              (
                  ar.response_payload IS NULL
                  AND ar.original_is_correct IS NOT NULL
              )
              OR
              (
                  ar.response_payload IS NOT NULL
                  AND ar.original_is_correct IS NULL
              )
          )
    ) THEN
        RAISE EXCEPTION
            'Final Attempt response/correctness invariant violated';
    END IF;
END;
$$;


CREATE OR REPLACE FUNCTION educore_validate_final_attempt_from_attempt()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM educore_validate_final_attempt(NEW.id);
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ctrg_attempt_finalization
AFTER UPDATE ON attempts
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_final_attempt_from_attempt();


CREATE OR REPLACE FUNCTION educore_guard_regrade_correction()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    response_record RECORD;
    attempt_record RECORD;
    expected_number INTEGER;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION
            'RegradeCorrection is immutable';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'RegradeCorrection cannot be deleted';
    END IF;

    SELECT *
    INTO response_record
    FROM attempt_responses
    WHERE id = NEW.attempt_response_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'RegradeCorrection references missing AttemptResponse';
    END IF;

    SELECT a.*
    INTO attempt_record
    FROM attempt_items ai
    JOIN attempts a
      ON a.id = ai.attempt_id
    WHERE ai.id = response_record.attempt_item_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'RegradeCorrection cannot resolve owning Attempt';
    END IF;

    IF attempt_record.status NOT IN ('submitted', 'abandoned')
       OR attempt_record.finalized_at IS NULL THEN
        RAISE EXCEPTION
            'RegradeCorrection requires a finalized Attempt';
    END IF;

    IF response_record.response_payload IS NULL
       OR response_record.original_is_correct IS NULL THEN
        RAISE EXCEPTION
            'RegradeCorrection requires an answered finalized Response';
    END IF;

    SELECT COALESCE(MAX(correction_number), 0) + 1
    INTO expected_number
    FROM regrade_corrections
    WHERE attempt_response_id = NEW.attempt_response_id;

    IF NEW.correction_number <> expected_number THEN
        RAISE EXCEPTION
            'Invalid RegradeCorrection sequence: expected %, received %',
            expected_number,
            NEW.correction_number;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_regrade_corrections_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON regrade_corrections
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_regrade_correction();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_regrade_corrections_integrity
    ON regrade_corrections;
DROP FUNCTION IF EXISTS educore_guard_regrade_correction();

DROP TRIGGER IF EXISTS ctrg_attempt_finalization
    ON attempts;
DROP FUNCTION IF EXISTS educore_validate_final_attempt_from_attempt();
DROP FUNCTION IF EXISTS educore_validate_final_attempt(UUID);

DROP TRIGGER IF EXISTS trg_attempt_finalization_integrity
    ON attempts;
DROP FUNCTION IF EXISTS educore_guard_attempt_finalization();

DROP TRIGGER IF EXISTS ctrg_attempt_response_state
    ON attempt_responses;
DROP FUNCTION IF EXISTS educore_validate_attempt_response_state();

DROP TRIGGER IF EXISTS trg_attempt_responses_integrity
    ON attempt_responses;
DROP FUNCTION IF EXISTS educore_guard_attempt_response();
SQL);
    }
};

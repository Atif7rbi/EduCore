<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore integrity migrations require PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_guard_attempt()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_generated_at TIMESTAMPTZ;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'in_progress' THEN
            RAISE EXCEPTION
                'Attempt must be created in in_progress status';
        END IF;

        IF NEW.started_at IS NOT NULL THEN
            RAISE EXCEPTION
                'Attempt must be created unsealed with started_at NULL';
        END IF;

        IF NEW.exam_generation_id IS NOT NULL THEN
            SELECT generated_at
            INTO source_generated_at
            FROM exam_generations
            WHERE id = NEW.exam_generation_id
              AND curriculum_version_id = NEW.curriculum_version_id
            FOR UPDATE;

            IF NOT FOUND OR source_generated_at IS NULL THEN
                RAISE EXCEPTION
                    'Exam Attempt requires a sealed ExamGeneration';
            END IF;
        END IF;

        IF NEW.practice_activity_id IS NOT NULL THEN
            PERFORM 1
            FROM practice_activities
            WHERE id = NEW.practice_activity_id
              AND curriculum_version_id = NEW.curriculum_version_id
            FOR UPDATE;

            IF NOT FOUND THEN
                RAISE EXCEPTION
                    'Practice Attempt requires a valid PracticeActivity';
            END IF;
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.learner_profile_id IS DISTINCT FROM NEW.learner_profile_id
       OR OLD.exam_generation_id IS DISTINCT FROM NEW.exam_generation_id
       OR OLD.practice_activity_id IS DISTINCT FROM NEW.practice_activity_id
       OR OLD.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id THEN
        RAISE EXCEPTION
            'Attempt learner/source/version fields are immutable';
    END IF;

    IF OLD.started_at IS NOT NULL
       AND OLD.started_at IS DISTINCT FROM NEW.started_at THEN
        RAISE EXCEPTION
            'Attempt.started_at is immutable after sealing';
    END IF;

    IF OLD.started_at IS NULL
       AND NEW.started_at IS NOT NULL
       AND NEW.status <> 'in_progress' THEN
        RAISE EXCEPTION
            'Attempt must seal while still in_progress';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        IF NOT (
            OLD.status = 'in_progress'
            AND NEW.status IN ('submitted', 'abandoned')
        ) THEN
            RAISE EXCEPTION
                'Invalid Attempt lifecycle transition: % -> %',
                OLD.status,
                NEW.status;
        END IF;

        IF OLD.started_at IS NULL THEN
            RAISE EXCEPTION
                'Unsealed Attempt cannot be finalized';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_attempts_integrity
BEFORE INSERT OR UPDATE ON attempts
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_attempt();


CREATE OR REPLACE FUNCTION educore_guard_attempt_item()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_attempt UUID;
    attempt_started_at TIMESTAMPTZ;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION
            'AttemptItem is immutable immediately after insertion';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'AttemptItem cannot be deleted';
    END IF;

    target_attempt := NEW.attempt_id;

    SELECT started_at
    INTO attempt_started_at
    FROM attempts
    WHERE id = target_attempt
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'AttemptItem references missing Attempt %',
            target_attempt;
    END IF;

    IF attempt_started_at IS NOT NULL THEN
        RAISE EXCEPTION
            'Attempt % is sealed; AttemptItem insertion rejected',
            target_attempt;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_attempt_items_integrity
BEFORE INSERT OR UPDATE OR DELETE ON attempt_items
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_attempt_item();


CREATE OR REPLACE FUNCTION educore_guard_attempt_classification()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_attempt UUID;
    new_attempt UUID;
    locked_record RECORD;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT attempt_id
        INTO old_attempt
        FROM attempt_items
        WHERE id = OLD.attempt_item_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        SELECT attempt_id
        INTO new_attempt
        FROM attempt_items
        WHERE id = NEW.attempt_item_id;
    END IF;

    FOR locked_record IN
        SELECT id, started_at
        FROM attempts
        WHERE id = old_attempt
           OR id = new_attempt
        ORDER BY id
        FOR UPDATE
    LOOP
        IF locked_record.started_at IS NOT NULL THEN
            RAISE EXCEPTION
                'Attempt % is sealed; classification mutation rejected',
                locked_record.id;
        END IF;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_attempt_item_classification_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON attempt_item_classification_skills
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_attempt_classification();


CREATE OR REPLACE FUNCTION educore_validate_attempt_instantiation(
    target_attempt UUID
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    attempt_record RECORD;
    item_count BIGINT;
BEGIN
    SELECT *
    INTO attempt_record
    FROM attempts
    WHERE id = target_attempt
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF attempt_record.started_at IS NULL THEN
        RAISE EXCEPTION
            'Attempt % must have started_at before COMMIT',
            target_attempt;
    END IF;

    SELECT COUNT(*)
    INTO item_count
    FROM attempt_items
    WHERE attempt_id = target_attempt;

    IF item_count < 1 THEN
        RAISE EXCEPTION
            'Attempt % requires at least one AttemptItem',
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
            'Every AttemptItem requires exactly one AttemptResponse';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM attempt_items ai
        WHERE ai.attempt_id = target_attempt
          AND NOT EXISTS (
              SELECT 1
              FROM attempt_item_classification_skills aics
              WHERE aics.attempt_item_id = ai.id
                AND aics.role = 'primary'
          )
    ) THEN
        RAISE EXCEPTION
            'Every AttemptItem requires at least one historical primary Skill';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM attempt_items ai
        JOIN assessment_item_revisions air
          ON air.id = ai.assessment_item_revision_id
        WHERE ai.attempt_id = target_attempt
          AND ai.primary_topic_id
              IS DISTINCT FROM air.primary_topic_id
    ) THEN
        RAISE EXCEPTION
            'AttemptItem Primary Topic snapshot does not match source revision';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM attempt_items ai
        WHERE ai.attempt_id = target_attempt
          AND (
              EXISTS (
                  SELECT svp.skill_id, airs.role
                  FROM assessment_item_revision_skills airs
                  JOIN skill_version_placements svp
                    ON svp.id = airs.skill_version_placement_id
                  WHERE airs.assessment_item_revision_id =
                        ai.assessment_item_revision_id

                  EXCEPT

                  SELECT aics.skill_id, aics.role
                  FROM attempt_item_classification_skills aics
                  WHERE aics.attempt_item_id = ai.id
              )
              OR
              EXISTS (
                  SELECT aics.skill_id, aics.role
                  FROM attempt_item_classification_skills aics
                  WHERE aics.attempt_item_id = ai.id

                  EXCEPT

                  SELECT svp.skill_id, airs.role
                  FROM assessment_item_revision_skills airs
                  JOIN skill_version_placements svp
                    ON svp.id = airs.skill_version_placement_id
                  WHERE airs.assessment_item_revision_id =
                        ai.assessment_item_revision_id
              )
          )
    ) THEN
        RAISE EXCEPTION
            'AttemptItem historical Skill classification does not match source revision';
    END IF;

    IF attempt_record.exam_generation_id IS NOT NULL THEN
        IF EXISTS (
            SELECT
                egi.id,
                egi.assessment_item_revision_id,
                egi.assessment_item_id
            FROM exam_generation_items egi
            WHERE egi.exam_generation_id =
                  attempt_record.exam_generation_id

            EXCEPT

            SELECT
                ai.exam_generation_item_id,
                ai.assessment_item_revision_id,
                ai.assessment_item_id
            FROM attempt_items ai
            WHERE ai.attempt_id = target_attempt
        )
        OR EXISTS (
            SELECT
                ai.exam_generation_item_id,
                ai.assessment_item_revision_id,
                ai.assessment_item_id
            FROM attempt_items ai
            WHERE ai.attempt_id = target_attempt

            EXCEPT

            SELECT
                egi.id,
                egi.assessment_item_revision_id,
                egi.assessment_item_id
            FROM exam_generation_items egi
            WHERE egi.exam_generation_id =
                  attempt_record.exam_generation_id
        ) THEN
            RAISE EXCEPTION
                'Exam Attempt source-set does not exactly equal ExamGenerationItems';
        END IF;
    ELSE
        IF EXISTS (
            SELECT
                pai.assessment_item_revision_id,
                pai.assessment_item_id
            FROM practice_activity_items pai
            WHERE pai.practice_activity_id =
                  attempt_record.practice_activity_id

            EXCEPT

            SELECT
                ai.assessment_item_revision_id,
                ai.assessment_item_id
            FROM attempt_items ai
            WHERE ai.attempt_id = target_attempt
        )
        OR EXISTS (
            SELECT
                ai.assessment_item_revision_id,
                ai.assessment_item_id
            FROM attempt_items ai
            WHERE ai.attempt_id = target_attempt

            EXCEPT

            SELECT
                pai.assessment_item_revision_id,
                pai.assessment_item_id
            FROM practice_activity_items pai
            WHERE pai.practice_activity_id =
                  attempt_record.practice_activity_id
        ) THEN
            RAISE EXCEPTION
                'Practice Attempt source-set does not exactly equal creation-time PracticeActivity membership';
        END IF;
    END IF;
END;
$$;


CREATE OR REPLACE FUNCTION educore_validate_attempt_from_insert()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM educore_validate_attempt_instantiation(NEW.id);
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ctrg_attempt_instantiation
AFTER INSERT ON attempts
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_attempt_from_insert();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS ctrg_attempt_instantiation
    ON attempts;
DROP FUNCTION IF EXISTS educore_validate_attempt_from_insert();

DROP FUNCTION IF EXISTS educore_validate_attempt_instantiation(UUID);

DROP TRIGGER IF EXISTS trg_attempt_item_classification_integrity
    ON attempt_item_classification_skills;
DROP FUNCTION IF EXISTS educore_guard_attempt_classification();

DROP TRIGGER IF EXISTS trg_attempt_items_integrity
    ON attempt_items;
DROP FUNCTION IF EXISTS educore_guard_attempt_item();

DROP TRIGGER IF EXISTS trg_attempts_integrity
    ON attempts;
DROP FUNCTION IF EXISTS educore_guard_attempt();
SQL);
    }
};

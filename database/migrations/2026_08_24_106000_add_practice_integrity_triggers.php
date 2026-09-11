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
CREATE OR REPLACE FUNCTION educore_guard_practice_activity()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'UPDATE'
       AND OLD.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id THEN
        RAISE EXCEPTION
            'PracticeActivity curriculum_version_id is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_practice_activities_integrity
BEFORE UPDATE ON practice_activities
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_practice_activity();


CREATE OR REPLACE FUNCTION educore_lock_practice_activity_membership()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_activity UUID;
    new_activity UUID;
    locked_id UUID;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_activity := OLD.practice_activity_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        new_activity := NEW.practice_activity_id;
    END IF;

    FOR locked_id IN
        SELECT id
        FROM practice_activities
        WHERE id = old_activity
           OR id = new_activity
        ORDER BY id
        FOR UPDATE
    LOOP
        NULL;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_practice_activity_items_parent_lock
BEFORE INSERT OR UPDATE OR DELETE ON practice_activity_items
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_practice_activity_membership();


CREATE OR REPLACE FUNCTION educore_validate_active_practice_activity(
    target_activity UUID
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    activity_status TEXT;
    item_count BIGINT;
    unreleased_count BIGINT;
BEGIN
    SELECT status
    INTO activity_status
    FROM practice_activities
    WHERE id = target_activity
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF activity_status <> 'active' THEN
        RETURN;
    END IF;

    SELECT COUNT(*)
    INTO item_count
    FROM practice_activity_items
    WHERE practice_activity_id = target_activity;

    IF item_count < 1 THEN
        RAISE EXCEPTION
            'Active PracticeActivity % requires at least one item',
            target_activity;
    END IF;

    SELECT COUNT(*)
    INTO unreleased_count
    FROM practice_activity_items pai
    JOIN assessment_item_revisions air
      ON air.id = pai.assessment_item_revision_id
    WHERE pai.practice_activity_id = target_activity
      AND air.released_at IS NULL;

    IF unreleased_count > 0 THEN
        RAISE EXCEPTION
            'Active PracticeActivity % contains unreleased AssessmentItemRevision',
            target_activity;
    END IF;
END;
$$;


CREATE OR REPLACE FUNCTION educore_validate_practice_activity_from_activity()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM educore_validate_active_practice_activity(NEW.id);
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ctrg_practice_activities_completeness
AFTER INSERT OR UPDATE ON practice_activities
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_practice_activity_from_activity();


CREATE OR REPLACE FUNCTION educore_validate_practice_activity_from_item()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        PERFORM educore_validate_active_practice_activity(
            NEW.practice_activity_id
        );

        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        PERFORM educore_validate_active_practice_activity(
            OLD.practice_activity_id
        );

        RETURN OLD;
    END IF;

    IF OLD.practice_activity_id IS DISTINCT FROM NEW.practice_activity_id THEN
        IF OLD.practice_activity_id::text < NEW.practice_activity_id::text THEN
            PERFORM educore_validate_active_practice_activity(
                OLD.practice_activity_id
            );
            PERFORM educore_validate_active_practice_activity(
                NEW.practice_activity_id
            );
        ELSE
            PERFORM educore_validate_active_practice_activity(
                NEW.practice_activity_id
            );
            PERFORM educore_validate_active_practice_activity(
                OLD.practice_activity_id
            );
        END IF;
    ELSE
        PERFORM educore_validate_active_practice_activity(
            NEW.practice_activity_id
        );
    END IF;

    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ctrg_practice_activity_items_completeness
AFTER INSERT OR UPDATE OR DELETE ON practice_activity_items
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_practice_activity_from_item();


CREATE OR REPLACE FUNCTION educore_lock_practice_attempt_source()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.practice_activity_id IS NOT NULL THEN
        PERFORM 1
        FROM practice_activities
        WHERE id = NEW.practice_activity_id
        FOR UPDATE;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'Practice Attempt references missing PracticeActivity %',
                NEW.practice_activity_id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_attempts_practice_source_lock
BEFORE INSERT ON attempts
FOR EACH ROW
WHEN (NEW.practice_activity_id IS NOT NULL)
EXECUTE PROCEDURE educore_lock_practice_attempt_source();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_attempts_practice_source_lock
    ON attempts;
DROP FUNCTION IF EXISTS educore_lock_practice_attempt_source();

DROP TRIGGER IF EXISTS ctrg_practice_activity_items_completeness
    ON practice_activity_items;
DROP FUNCTION IF EXISTS educore_validate_practice_activity_from_item();

DROP TRIGGER IF EXISTS ctrg_practice_activities_completeness
    ON practice_activities;
DROP FUNCTION IF EXISTS educore_validate_practice_activity_from_activity();

DROP FUNCTION IF EXISTS educore_validate_active_practice_activity(UUID);

DROP TRIGGER IF EXISTS trg_practice_activity_items_parent_lock
    ON practice_activity_items;
DROP FUNCTION IF EXISTS educore_lock_practice_activity_membership();

DROP TRIGGER IF EXISTS trg_practice_activities_integrity
    ON practice_activities;
DROP FUNCTION IF EXISTS educore_guard_practice_activity();
SQL);
    }
};

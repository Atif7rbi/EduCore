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
CREATE OR REPLACE FUNCTION educore_guard_assessment_item()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    current_revision_released_at TIMESTAMPTZ;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'draft' THEN
            RAISE EXCEPTION
                'AssessmentItem must be created in draft status';
        END IF;
    ELSE
        IF OLD.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id THEN
            RAISE EXCEPTION
                'AssessmentItem curriculum_version_id is immutable';
        END IF;

        IF OLD.item_type IS DISTINCT FROM NEW.item_type THEN
            RAISE EXCEPTION
                'AssessmentItem item_type is immutable';
        END IF;

        IF OLD.status IS DISTINCT FROM NEW.status THEN
            IF NOT (
                (OLD.status = 'draft' AND NEW.status = 'published')
                OR
                (OLD.status = 'published' AND NEW.status = 'retired')
            ) THEN
                RAISE EXCEPTION
                    'Invalid AssessmentItem lifecycle transition: % -> %',
                    OLD.status,
                    NEW.status;
            END IF;
        END IF;
    END IF;

    IF NEW.status = 'published' THEN
        IF NEW.published_revision_id IS NULL THEN
            RAISE EXCEPTION
                'Published AssessmentItem requires published_revision_id';
        END IF;

        SELECT released_at
        INTO current_revision_released_at
        FROM assessment_item_revisions
        WHERE id = NEW.published_revision_id
          AND assessment_item_id = NEW.id
        FOR UPDATE;

        IF NOT FOUND OR current_revision_released_at IS NULL THEN
            RAISE EXCEPTION
                'Published AssessmentItem requires a released same-item revision';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_assessment_items_integrity
BEFORE INSERT OR UPDATE ON assessment_items
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_assessment_item();


CREATE OR REPLACE FUNCTION educore_guard_assessment_revision()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    primary_count BIGINT;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.released_at IS NOT NULL THEN
            RAISE EXCEPTION
                'AssessmentItemRevision must be created unreleased';
        END IF;

        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.released_at IS NOT NULL THEN
            RAISE EXCEPTION
                'Released AssessmentItemRevision is immutable';
        END IF;

        RETURN OLD;
    END IF;

    IF OLD.released_at IS NOT NULL THEN
        RAISE EXCEPTION
            'Released AssessmentItemRevision is immutable';
    END IF;

    IF OLD.released_at IS NULL
       AND NEW.released_at IS NOT NULL THEN

        SELECT COUNT(*)
        INTO primary_count
        FROM assessment_item_revision_skills
        WHERE assessment_item_revision_id = NEW.id
          AND role = 'primary';

        IF primary_count < 1 THEN
            RAISE EXCEPTION
                'AssessmentItemRevision release requires at least one primary Skill';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_assessment_item_revisions_integrity
BEFORE INSERT OR UPDATE OR DELETE ON assessment_item_revisions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_assessment_revision();


CREATE OR REPLACE FUNCTION educore_guard_assessment_revision_skills()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_revision UUID;
    new_revision UUID;
    locked_record RECORD;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_revision := OLD.assessment_item_revision_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        new_revision := NEW.assessment_item_revision_id;
    END IF;

    FOR locked_record IN
        SELECT id, released_at
        FROM assessment_item_revisions
        WHERE id = old_revision
           OR id = new_revision
        ORDER BY id
        FOR UPDATE
    LOOP
        IF locked_record.released_at IS NOT NULL THEN
            RAISE EXCEPTION
                'AssessmentItemRevision % classification is sealed',
                locked_record.id;
        END IF;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_assessment_item_revision_skills_integrity
BEFORE INSERT OR UPDATE OR DELETE ON assessment_item_revision_skills
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_assessment_revision_skills();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_assessment_item_revision_skills_integrity
    ON assessment_item_revision_skills;
DROP FUNCTION IF EXISTS educore_guard_assessment_revision_skills();

DROP TRIGGER IF EXISTS trg_assessment_item_revisions_integrity
    ON assessment_item_revisions;
DROP FUNCTION IF EXISTS educore_guard_assessment_revision();

DROP TRIGGER IF EXISTS trg_assessment_items_integrity
    ON assessment_items;
DROP FUNCTION IF EXISTS educore_guard_assessment_item();
SQL);
    }
};

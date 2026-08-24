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
CREATE OR REPLACE FUNCTION educore_guard_exam_template_version()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    current_template UUID;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.exam_template_id IS DISTINCT FROM NEW.exam_template_id THEN
            RAISE EXCEPTION
                'ExamTemplateVersion exam_template_id is immutable';
        END IF;

        IF OLD.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id THEN
            RAISE EXCEPTION
                'ExamTemplateVersion curriculum_version_id is immutable';
        END IF;

        IF OLD.status IS DISTINCT FROM NEW.status THEN
            IF NOT (
                (OLD.status = 'draft' AND NEW.status = 'published')
                OR
                (OLD.status = 'published' AND NEW.status = 'retired')
            ) THEN
                RAISE EXCEPTION
                    'Invalid ExamTemplateVersion transition: % -> %',
                    OLD.status,
                    NEW.status;
            END IF;

            IF OLD.status = 'published'
               AND NEW.status = 'retired' THEN

                SELECT id
                INTO current_template
                FROM exam_templates
                WHERE id = OLD.exam_template_id
                  AND published_version_id = OLD.id
                FOR UPDATE;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'Current ExamTemplateVersion cannot be retired';
                END IF;
            END IF;
        END IF;

        IF OLD.status = 'published' THEN
            IF OLD.rules_payload IS DISTINCT FROM NEW.rules_payload
               OR OLD.rules_schema_version IS DISTINCT FROM NEW.rules_schema_version THEN
                RAISE EXCEPTION
                    'Published ExamTemplateVersion rules are immutable';
            END IF;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_exam_template_versions_integrity
BEFORE UPDATE ON exam_template_versions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_exam_template_version();


CREATE OR REPLACE FUNCTION educore_guard_exam_template_pointer()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    version_status TEXT;
BEGIN
    IF NEW.published_version_id IS NOT NULL THEN
        SELECT status
        INTO version_status
        FROM exam_template_versions
        WHERE id = NEW.published_version_id
          AND exam_template_id = NEW.id
        FOR UPDATE;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'ExamTemplate current pointer must target same-template version';
        END IF;

        IF version_status <> 'published' THEN
            RAISE EXCEPTION
                'ExamTemplate current pointer must target published version';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_exam_templates_pointer_integrity
BEFORE INSERT OR UPDATE OF published_version_id ON exam_templates
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_exam_template_pointer();


CREATE OR REPLACE FUNCTION educore_guard_exam_generation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    template_status TEXT;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.generated_at IS NOT NULL THEN
            RAISE EXCEPTION
                'ExamGeneration must be created unsealed';
        END IF;

        SELECT status
        INTO template_status
        FROM exam_template_versions
        WHERE id = NEW.exam_template_version_id
          AND curriculum_version_id = NEW.curriculum_version_id
        FOR UPDATE;

        IF NOT FOUND OR template_status <> 'published' THEN
            RAISE EXCEPTION
                'ExamGeneration requires published ExamTemplateVersion';
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.exam_template_version_id IS DISTINCT FROM NEW.exam_template_version_id
       OR OLD.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id
       OR OLD.rules_snapshot IS DISTINCT FROM NEW.rules_snapshot
       OR OLD.rules_schema_version IS DISTINCT FROM NEW.rules_schema_version
       OR OLD.generator_version IS DISTINCT FROM NEW.generator_version
       OR OLD.seed IS DISTINCT FROM NEW.seed THEN
        RAISE EXCEPTION
            'ExamGeneration provenance and snapshot fields are immutable';
    END IF;

    IF OLD.generated_at IS NOT NULL THEN
        RAISE EXCEPTION
            'Sealed ExamGeneration is immutable';
    END IF;

    IF OLD.generated_at IS NULL
       AND NEW.generated_at IS NULL THEN
        RETURN NEW;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_exam_generations_integrity
BEFORE INSERT OR UPDATE ON exam_generations
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_exam_generation();


CREATE OR REPLACE FUNCTION educore_lock_exam_generation_items()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_generation UUID;
    new_generation UUID;
    locked_record RECORD;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_generation := OLD.exam_generation_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        new_generation := NEW.exam_generation_id;
    END IF;

    FOR locked_record IN
        SELECT id, generated_at
        FROM exam_generations
        WHERE id = old_generation
           OR id = new_generation
        ORDER BY id
        FOR UPDATE
    LOOP
        IF locked_record.generated_at IS NOT NULL THEN
            RAISE EXCEPTION
                'ExamGeneration % is sealed',
                locked_record.id;
        END IF;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_exam_generation_items_integrity
BEFORE INSERT OR UPDATE OR DELETE ON exam_generation_items
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_exam_generation_items();


CREATE OR REPLACE FUNCTION educore_validate_exam_generation(
    target_generation UUID
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    seal_time TIMESTAMPTZ;
    item_count BIGINT;
    unreleased_count BIGINT;
BEGIN
    SELECT generated_at
    INTO seal_time
    FROM exam_generations
    WHERE id = target_generation
    FOR UPDATE;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    IF seal_time IS NULL THEN
        RAISE EXCEPTION
            'ExamGeneration % must be sealed before COMMIT',
            target_generation;
    END IF;

    SELECT COUNT(*)
    INTO item_count
    FROM exam_generation_items
    WHERE exam_generation_id = target_generation;

    IF item_count < 1 THEN
        RAISE EXCEPTION
            'ExamGeneration % requires at least one item',
            target_generation;
    END IF;

    SELECT COUNT(*)
    INTO unreleased_count
    FROM exam_generation_items egi
    JOIN assessment_item_revisions air
      ON air.id = egi.assessment_item_revision_id
    WHERE egi.exam_generation_id = target_generation
      AND air.released_at IS NULL;

    IF unreleased_count > 0 THEN
        RAISE EXCEPTION
            'ExamGeneration % contains unreleased AssessmentItemRevision',
            target_generation;
    END IF;
END;
$$;


CREATE OR REPLACE FUNCTION educore_validate_exam_generation_from_generation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM educore_validate_exam_generation(NEW.id);
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ctrg_exam_generations_completeness
AFTER INSERT OR UPDATE ON exam_generations
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_exam_generation_from_generation();


CREATE OR REPLACE FUNCTION educore_validate_exam_generation_from_item()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        PERFORM educore_validate_exam_generation(
            NEW.exam_generation_id
        );
        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        PERFORM educore_validate_exam_generation(
            OLD.exam_generation_id
        );
        RETURN OLD;
    END IF;

    IF OLD.exam_generation_id IS DISTINCT FROM NEW.exam_generation_id THEN
        IF OLD.exam_generation_id::text < NEW.exam_generation_id::text THEN
            PERFORM educore_validate_exam_generation(
                OLD.exam_generation_id
            );
            PERFORM educore_validate_exam_generation(
                NEW.exam_generation_id
            );
        ELSE
            PERFORM educore_validate_exam_generation(
                NEW.exam_generation_id
            );
            PERFORM educore_validate_exam_generation(
                OLD.exam_generation_id
            );
        END IF;
    ELSE
        PERFORM educore_validate_exam_generation(
            NEW.exam_generation_id
        );
    END IF;

    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ctrg_exam_generation_items_completeness
AFTER INSERT OR UPDATE OR DELETE ON exam_generation_items
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_exam_generation_from_item();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS ctrg_exam_generation_items_completeness
    ON exam_generation_items;
DROP FUNCTION IF EXISTS educore_validate_exam_generation_from_item();

DROP TRIGGER IF EXISTS ctrg_exam_generations_completeness
    ON exam_generations;
DROP FUNCTION IF EXISTS educore_validate_exam_generation_from_generation();

DROP FUNCTION IF EXISTS educore_validate_exam_generation(UUID);

DROP TRIGGER IF EXISTS trg_exam_generation_items_integrity
    ON exam_generation_items;
DROP FUNCTION IF EXISTS educore_lock_exam_generation_items();

DROP TRIGGER IF EXISTS trg_exam_generations_integrity
    ON exam_generations;
DROP FUNCTION IF EXISTS educore_guard_exam_generation();

DROP TRIGGER IF EXISTS trg_exam_templates_pointer_integrity
    ON exam_templates;
DROP FUNCTION IF EXISTS educore_guard_exam_template_pointer();

DROP TRIGGER IF EXISTS trg_exam_template_versions_integrity
    ON exam_template_versions;
DROP FUNCTION IF EXISTS educore_guard_exam_template_version();
SQL);
    }
};

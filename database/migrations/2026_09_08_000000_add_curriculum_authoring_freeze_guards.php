<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore curriculum authoring freeze requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION educore_guard_draft_curriculum_authoring()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_version UUID;
    new_version UUID;
    target_version UUID;
    locked_status TEXT;
BEGIN
    old_version := NULL;
    new_version := NULL;

    IF TG_OP <> 'INSERT' THEN
        old_version := OLD.curriculum_version_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        new_version := NEW.curriculum_version_id;
    END IF;

    FOR target_version IN
        SELECT version_id
        FROM (
            VALUES (old_version), (new_version)
        ) AS candidate(version_id)
        WHERE version_id IS NOT NULL
        GROUP BY version_id
        ORDER BY version_id
    LOOP
        SELECT status
        INTO locked_status
        FROM curriculum_versions
        WHERE id = target_version
        FOR UPDATE;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'Authoring table % references missing CurriculumVersion %',
                TG_TABLE_NAME,
                target_version;
        END IF;

        IF locked_status <> 'draft' THEN
            RAISE EXCEPTION
                'CurriculumVersion % is not draft; authoring table % is frozen',
                target_version,
                TG_TABLE_NAME;
        END IF;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;


CREATE TRIGGER trg_00_curriculum_authoring_lesson_revisions
BEFORE INSERT OR UPDATE OR DELETE ON lesson_revisions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();

CREATE TRIGGER trg_00_curriculum_authoring_lesson_revision_skills
BEFORE INSERT OR UPDATE OR DELETE ON lesson_revision_skills
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();


CREATE TRIGGER trg_00_curriculum_authoring_assessment_revisions
BEFORE INSERT OR UPDATE OR DELETE ON assessment_item_revisions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();

CREATE TRIGGER trg_00_curriculum_authoring_assessment_revision_skills
BEFORE INSERT OR UPDATE OR DELETE ON assessment_item_revision_skills
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();


CREATE TRIGGER trg_00_curriculum_authoring_practice_activities
BEFORE INSERT OR UPDATE OR DELETE ON practice_activities
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();

CREATE TRIGGER trg_00_curriculum_authoring_practice_activity_items
BEFORE INSERT OR UPDATE OR DELETE ON practice_activity_items
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();


CREATE TRIGGER trg_00_curriculum_authoring_exam_templates
BEFORE INSERT OR UPDATE OR DELETE ON exam_templates
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();

CREATE TRIGGER trg_00_curriculum_authoring_exam_template_versions
BEFORE INSERT OR UPDATE OR DELETE ON exam_template_versions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_draft_curriculum_authoring();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore curriculum authoring freeze requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_exam_template_versions
    ON exam_template_versions;

DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_exam_templates
    ON exam_templates;

DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_practice_activity_items
    ON practice_activity_items;

DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_practice_activities
    ON practice_activities;

DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_assessment_revision_skills
    ON assessment_item_revision_skills;

DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_assessment_revisions
    ON assessment_item_revisions;

DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_lesson_revision_skills
    ON lesson_revision_skills;

DROP TRIGGER IF EXISTS
    trg_00_curriculum_authoring_lesson_revisions
    ON lesson_revisions;

DROP FUNCTION IF EXISTS educore_guard_draft_curriculum_authoring();
SQL);
    }
};

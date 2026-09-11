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
CREATE OR REPLACE FUNCTION educore_check_curriculum_version_lifecycle()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.status IS DISTINCT FROM NEW.status THEN
        IF NOT (
            (OLD.status = 'draft' AND NEW.status = 'published')
            OR
            (OLD.status = 'published' AND NEW.status = 'retired')
        ) THEN
            RAISE EXCEPTION
                'Invalid CurriculumVersion lifecycle transition: % -> %',
                OLD.status,
                NEW.status;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_curriculum_versions_lifecycle
BEFORE UPDATE ON curriculum_versions
FOR EACH ROW
EXECUTE PROCEDURE educore_check_curriculum_version_lifecycle();


CREATE OR REPLACE FUNCTION educore_lock_draft_curriculum_structure()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_version UUID;
    new_version UUID;
    locked_record RECORD;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_version := OLD.curriculum_version_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        new_version := NEW.curriculum_version_id;
    END IF;

    FOR locked_record IN
        SELECT id, status
        FROM curriculum_versions
        WHERE id = old_version
           OR id = new_version
        ORDER BY id
        FOR UPDATE
    LOOP
        IF locked_record.status <> 'draft' THEN
            RAISE EXCEPTION
                'CurriculumVersion % structural membership is frozen in status %',
                locked_record.id,
                locked_record.status;
        END IF;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_topics_curriculum_structure
BEFORE INSERT OR UPDATE OR DELETE ON topics
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_draft_curriculum_structure();

CREATE TRIGGER trg_skill_version_placements_curriculum_structure
BEFORE INSERT OR UPDATE OR DELETE ON skill_version_placements
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_draft_curriculum_structure();

CREATE TRIGGER trg_skill_home_topics_curriculum_structure
BEFORE INSERT OR UPDATE OR DELETE ON skill_home_topics
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_draft_curriculum_structure();

CREATE TRIGGER trg_lessons_curriculum_structure
BEFORE INSERT OR DELETE OR UPDATE OF curriculum_version_id ON lessons
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_draft_curriculum_structure();

CREATE TRIGGER trg_assessment_items_curriculum_structure
BEFORE INSERT OR DELETE OR UPDATE OF curriculum_version_id ON assessment_items
FOR EACH ROW
EXECUTE PROCEDURE educore_lock_draft_curriculum_structure();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_assessment_items_curriculum_structure
    ON assessment_items;
DROP TRIGGER IF EXISTS trg_lessons_curriculum_structure
    ON lessons;
DROP TRIGGER IF EXISTS trg_skill_home_topics_curriculum_structure
    ON skill_home_topics;
DROP TRIGGER IF EXISTS trg_skill_version_placements_curriculum_structure
    ON skill_version_placements;
DROP TRIGGER IF EXISTS trg_topics_curriculum_structure
    ON topics;

DROP FUNCTION IF EXISTS educore_lock_draft_curriculum_structure();

DROP TRIGGER IF EXISTS trg_curriculum_versions_lifecycle
    ON curriculum_versions;

DROP FUNCTION IF EXISTS educore_check_curriculum_version_lifecycle();
SQL);
    }
};

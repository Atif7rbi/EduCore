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
CREATE OR REPLACE FUNCTION educore_guard_lesson()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    current_revision_released_at TIMESTAMPTZ;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'draft' THEN
            RAISE EXCEPTION
                'Lesson must be created in draft status';
        END IF;
    ELSE
        IF OLD.curriculum_version_id IS DISTINCT FROM NEW.curriculum_version_id THEN
            RAISE EXCEPTION
                'Lesson curriculum_version_id is immutable';
        END IF;

        IF OLD.status IS DISTINCT FROM NEW.status THEN
            IF NOT (
                (OLD.status = 'draft' AND NEW.status = 'published')
                OR
                (OLD.status = 'published' AND NEW.status = 'retired')
            ) THEN
                RAISE EXCEPTION
                    'Invalid Lesson lifecycle transition: % -> %',
                    OLD.status,
                    NEW.status;
            END IF;
        END IF;
    END IF;

    IF NEW.status = 'published' THEN
        IF NEW.published_revision_id IS NULL THEN
            RAISE EXCEPTION
                'Published Lesson requires published_revision_id';
        END IF;

        SELECT released_at
        INTO current_revision_released_at
        FROM lesson_revisions
        WHERE id = NEW.published_revision_id
          AND lesson_id = NEW.id
        FOR UPDATE;

        IF NOT FOUND OR current_revision_released_at IS NULL THEN
            RAISE EXCEPTION
                'Published Lesson requires a released same-Lesson revision';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_lessons_integrity
BEFORE INSERT OR UPDATE ON lessons
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_lesson();


CREATE OR REPLACE FUNCTION educore_guard_lesson_revision()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.released_at IS NOT NULL THEN
            RAISE EXCEPTION
                'LessonRevision must be created unreleased';
        END IF;

        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.released_at IS NOT NULL THEN
            RAISE EXCEPTION
                'Released LessonRevision is immutable';
        END IF;

        RETURN OLD;
    END IF;

    IF OLD.released_at IS NOT NULL THEN
        RAISE EXCEPTION
            'Released LessonRevision is immutable';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_lesson_revisions_integrity
BEFORE INSERT OR UPDATE OR DELETE ON lesson_revisions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_lesson_revision();


CREATE OR REPLACE FUNCTION educore_guard_lesson_revision_skills()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_revision UUID;
    new_revision UUID;
    locked_record RECORD;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        old_revision := OLD.lesson_revision_id;
    END IF;

    IF TG_OP <> 'DELETE' THEN
        new_revision := NEW.lesson_revision_id;
    END IF;

    FOR locked_record IN
        SELECT id, released_at
        FROM lesson_revisions
        WHERE id = old_revision
           OR id = new_revision
        ORDER BY id
        FOR UPDATE
    LOOP
        IF locked_record.released_at IS NOT NULL THEN
            RAISE EXCEPTION
                'LessonRevision % classification is sealed',
                locked_record.id;
        END IF;
    END LOOP;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_lesson_revision_skills_integrity
BEFORE INSERT OR UPDATE OR DELETE ON lesson_revision_skills
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_lesson_revision_skills();


CREATE OR REPLACE FUNCTION educore_guard_lesson_progress()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    revision_released_at TIMESTAMPTZ;
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT released_at
        INTO revision_released_at
        FROM lesson_revisions
        WHERE id = NEW.lesson_revision_id
        FOR UPDATE;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'LessonProgress references missing LessonRevision %',
                NEW.lesson_revision_id;
        END IF;

        IF revision_released_at IS NULL THEN
            RAISE EXCEPTION
                'LessonProgress requires a released LessonRevision';
        END IF;

        IF NEW.status <> 'in_progress' THEN
            RAISE EXCEPTION
                'LessonProgress must be created in in_progress status';
        END IF;

        RETURN NEW;
    END IF;

    IF OLD.lesson_revision_id IS DISTINCT FROM NEW.lesson_revision_id THEN
        RAISE EXCEPTION
            'LessonProgress.lesson_revision_id is immutable';
    END IF;

    IF OLD.status IS DISTINCT FROM NEW.status THEN
        IF NOT (
            OLD.status = 'in_progress'
            AND NEW.status = 'completed'
        ) THEN
            RAISE EXCEPTION
                'Invalid LessonProgress transition: % -> %',
                OLD.status,
                NEW.status;
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_lesson_progresses_integrity
BEFORE INSERT OR UPDATE ON lesson_progresses
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_lesson_progress();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_lesson_progresses_integrity
    ON lesson_progresses;
DROP FUNCTION IF EXISTS educore_guard_lesson_progress();

DROP TRIGGER IF EXISTS trg_lesson_revision_skills_integrity
    ON lesson_revision_skills;
DROP FUNCTION IF EXISTS educore_guard_lesson_revision_skills();

DROP TRIGGER IF EXISTS trg_lesson_revisions_integrity
    ON lesson_revisions;
DROP FUNCTION IF EXISTS educore_guard_lesson_revision();

DROP TRIGGER IF EXISTS trg_lessons_integrity
    ON lessons;
DROP FUNCTION IF EXISTS educore_guard_lesson();
SQL);
    }
};

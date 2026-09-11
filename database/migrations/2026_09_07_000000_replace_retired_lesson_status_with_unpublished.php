<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore lesson lifecycle migration requires PostgreSQL.'
            );
        }

        DB::transaction(function (): void {
            DB::statement(
                'DROP TRIGGER IF EXISTS trg_lessons_integrity ON lessons'
            );

            DB::statement(
                'ALTER TABLE lessons DROP CONSTRAINT chk_lessons_status'
            );

            DB::table('lessons')
                ->where('status', 'retired')
                ->update([
                    'status' => 'unpublished',
                    'updated_at' => now(),
                ]);

            DB::statement(<<<'SQL'
ALTER TABLE lessons
    ADD CONSTRAINT chk_lessons_status
    CHECK (status IN ('draft', 'published', 'unpublished'))
SQL);

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
                (OLD.status = 'published' AND NEW.status = 'unpublished')
                OR
                (OLD.status = 'unpublished' AND NEW.status = 'published')
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
SQL);
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore lesson lifecycle migration requires PostgreSQL.'
            );
        }

        DB::transaction(function (): void {
            DB::statement(
                'DROP TRIGGER IF EXISTS trg_lessons_integrity ON lessons'
            );

            DB::statement(
                'ALTER TABLE lessons DROP CONSTRAINT chk_lessons_status'
            );

            DB::table('lessons')
                ->where('status', 'unpublished')
                ->update([
                    'status' => 'retired',
                    'updated_at' => now(),
                ]);

            DB::statement(<<<'SQL'
ALTER TABLE lessons
    ADD CONSTRAINT chk_lessons_status
    CHECK (status IN ('draft', 'published', 'retired'))
SQL);

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
SQL);
        });
    }
};

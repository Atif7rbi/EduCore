<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore Curriculum ownership requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE curricula
    ADD COLUMN teacher_subject_assignment_id UUID NULL;

ALTER TABLE curricula
    ADD CONSTRAINT fk_curricula_teacher_assignment_subject
        FOREIGN KEY (
            teacher_subject_assignment_id,
            subject_id
        )
        REFERENCES teacher_subject_assignments (
            id,
            subject_id
        )
        ON DELETE RESTRICT;

CREATE INDEX idx_curricula_teacher_subject_assignment
    ON curricula (teacher_subject_assignment_id);


CREATE OR REPLACE FUNCTION educore_guard_curriculum_ownership()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    assignment_identity RECORD;
    teacher_record RECORD;
    subject_record RECORD;
    assignment_record RECORD;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF OLD.subject_id
               IS DISTINCT FROM NEW.subject_id THEN
            RAISE EXCEPTION
                'Curriculum.subject_id is immutable after INSERT'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_curricula_subject_immutable';
        END IF;

        IF OLD.teacher_subject_assignment_id
               IS DISTINCT FROM
               NEW.teacher_subject_assignment_id THEN
            RAISE EXCEPTION
                'Curriculum ownership is immutable after INSERT'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_curricula_owner_immutable';
        END IF;

        RETURN NEW;
    END IF;

    IF NEW.teacher_subject_assignment_id IS NULL THEN
        RAISE EXCEPTION
            'New Curriculum requires TeacherSubjectAssignment ownership'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_curricula_owner_required';
    END IF;

    /*
     * Identity discovery is intentionally unlocked.
     * The authoritative lock order is:
     *
     * Teacher User
     * -> Subject
     * -> TeacherSubjectAssignment
     */
    SELECT
        id,
        teacher_id,
        subject_id
    INTO assignment_identity
    FROM teacher_subject_assignments
    WHERE id = NEW.teacher_subject_assignment_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Curriculum references missing TeacherSubjectAssignment'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_curricula_teacher_assignment_subject';
    END IF;

    SELECT
        id,
        role,
        status
    INTO teacher_record
    FROM users
    WHERE id = assignment_identity.teacher_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Curriculum ownership references missing Teacher'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_teacher_subject_assignments_teacher';
    END IF;

    SELECT
        id,
        code,
        status
    INTO subject_record
    FROM subjects
    WHERE id = NEW.subject_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Curriculum references missing Subject'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_curricula_subject';
    END IF;

    SELECT
        id,
        teacher_id,
        subject_id,
        status
    INTO assignment_record
    FROM teacher_subject_assignments
    WHERE id = NEW.teacher_subject_assignment_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Curriculum references missing TeacherSubjectAssignment'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_curricula_teacher_assignment_subject';
    END IF;

    IF teacher_record.role <> 'teacher'
       OR teacher_record.status <> 'active' THEN
        RAISE EXCEPTION
            'Curriculum ownership requires an active TEACHER'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_curricula_owner_teacher_eligible';
    END IF;

    IF subject_record.code IS NULL
       OR subject_record.status <> 'active' THEN
        RAISE EXCEPTION
            'Curriculum ownership requires an active canonical Subject'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_curricula_owner_subject_eligible';
    END IF;

    IF assignment_record.status <> 'active' THEN
        RAISE EXCEPTION
            'Curriculum ownership requires an active TeacherSubjectAssignment'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_curricula_owner_assignment_active';
    END IF;

    IF assignment_record.subject_id
           IS DISTINCT FROM NEW.subject_id THEN
        RAISE EXCEPTION
            'Curriculum Subject must match TeacherSubjectAssignment Subject'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_curricula_owner_subject_match';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_curricula_ownership_integrity
BEFORE INSERT OR UPDATE OF
    subject_id,
    teacher_subject_assignment_id
ON curricula
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_curriculum_ownership();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'EduCore Curriculum ownership foundation is intentionally forward-only.'
        );
    }
};

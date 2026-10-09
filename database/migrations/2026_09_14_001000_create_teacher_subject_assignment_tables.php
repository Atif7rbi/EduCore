<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore TeacherSubjectAssignment requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE teacher_subject_assignments (
    id UUID PRIMARY KEY,
    teacher_id UUID NOT NULL,
    subject_id UUID NOT NULL,
    status TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_teacher_subject_assignments_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_teacher_subject_assignments_subject
        FOREIGN KEY (subject_id)
        REFERENCES subjects(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_teacher_subject_assignments_status
        CHECK (status IN ('active', 'inactive')),

    CONSTRAINT uq_teacher_subject_assignments_pair
        UNIQUE (teacher_id, subject_id),

    CONSTRAINT uq_teacher_subject_assignments_id_subject
        UNIQUE (id, subject_id)
);

CREATE INDEX idx_teacher_subject_assignments_subject
    ON teacher_subject_assignments (subject_id);

CREATE TABLE teacher_subject_assignment_transitions (
    id UUID PRIMARY KEY,
    sequence_number BIGINT GENERATED ALWAYS AS IDENTITY,
    assignment_id UUID NOT NULL,
    from_status TEXT NULL,
    to_status TEXT NOT NULL,
    actor_user_id UUID NOT NULL,
    operation_id UUID NOT NULL,
    reason TEXT NOT NULL,
    effective_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT fk_teacher_subject_assignment_transitions_assignment
        FOREIGN KEY (assignment_id)
        REFERENCES teacher_subject_assignments(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_teacher_subject_assignment_transitions_actor
        FOREIGN KEY (actor_user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_teacher_subject_assignment_transitions_operation
        UNIQUE (operation_id),

    CONSTRAINT uq_teacher_subject_assignment_transitions_sequence
        UNIQUE (sequence_number),

    CONSTRAINT chk_teacher_subject_assignment_transitions_from_status
        CHECK (
            from_status IS NULL
            OR from_status IN ('active', 'inactive')
        ),

    CONSTRAINT chk_teacher_subject_assignment_transitions_to_status
        CHECK (to_status IN ('active', 'inactive')),

    CONSTRAINT chk_teacher_subject_assignment_transitions_shape
        CHECK (
            (
                from_status IS NULL
                AND to_status = 'active'
            )
            OR
            (
                from_status = 'active'
                AND to_status = 'inactive'
            )
            OR
            (
                from_status = 'inactive'
                AND to_status = 'active'
            )
        ),

    CONSTRAINT chk_teacher_subject_assignment_transitions_reason
        CHECK (BTRIM(reason) <> '')
);

CREATE INDEX idx_teacher_subject_assignment_transitions_assignment
    ON teacher_subject_assignment_transitions (
        assignment_id,
        effective_at
    );

CREATE INDEX idx_teacher_subject_assignment_transitions_actor
    ON teacher_subject_assignment_transitions (actor_user_id);


CREATE OR REPLACE FUNCTION educore_guard_teacher_subject_assignment()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    teacher_record RECORD;
    subject_record RECORD;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignment cannot be deleted'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignments_durable';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.teacher_id IS DISTINCT FROM NEW.teacher_id
           OR OLD.subject_id IS DISTINCT FROM NEW.subject_id THEN
            RAISE EXCEPTION
                'TeacherSubjectAssignment identity is immutable'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_teacher_subject_assignments_identity_immutable';
        END IF;

        IF OLD.status IS DISTINCT FROM NEW.status THEN
            IF NOT (
                (
                    OLD.status = 'active'
                    AND NEW.status = 'inactive'
                )
                OR
                (
                    OLD.status = 'inactive'
                    AND NEW.status = 'active'
                )
            ) THEN
                RAISE EXCEPTION
                    'Invalid TeacherSubjectAssignment transition: % -> %',
                    OLD.status,
                    NEW.status
                    USING
                        ERRCODE = '23514',
                        CONSTRAINT =
                            'chk_teacher_subject_assignments_transition';
            END IF;
        END IF;

        IF NOT (
            OLD.status = 'inactive'
            AND NEW.status = 'active'
        ) THEN
            RETURN NEW;
        END IF;
    ELSE
        IF NEW.status <> 'active' THEN
            RAISE EXCEPTION
                'TeacherSubjectAssignment must be created active'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_teacher_subject_assignments_initial_status';
        END IF;
    END IF;

    SELECT id, role, status
    INTO teacher_record
    FROM users
    WHERE id = NEW.teacher_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignment references missing Teacher'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_teacher_subject_assignments_teacher';
    END IF;

    IF teacher_record.role <> 'teacher'
       OR teacher_record.status <> 'active' THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignment requires an active TEACHER'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignments_teacher_eligible';
    END IF;

    SELECT id, code, status
    INTO subject_record
    FROM subjects
    WHERE id = NEW.subject_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignment references missing Subject'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_teacher_subject_assignments_subject';
    END IF;

    IF subject_record.code IS NULL
       OR subject_record.status <> 'active' THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignment requires an active canonical Subject'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignments_subject_eligible';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_teacher_subject_assignments_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON teacher_subject_assignments
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_teacher_subject_assignment();


CREATE OR REPLACE FUNCTION educore_guard_teacher_subject_assignment_transition()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    previous_status TEXT;
    actor_record RECORD;
    assignment_record RECORD;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignmentTransition is append-only'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignment_transitions_append_only';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignmentTransition cannot be deleted'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignment_transitions_append_only';
    END IF;

    SELECT id, role, status
    INTO actor_record
    FROM users
    WHERE id = NEW.actor_user_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignmentTransition references missing actor'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_teacher_subject_assignment_transitions_actor';
    END IF;

    IF actor_record.role <> 'admin'
       OR actor_record.status <> 'active' THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignmentTransition requires an active ADMIN actor'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignment_transitions_actor_eligible';
    END IF;

    SELECT id, status
    INTO assignment_record
    FROM teacher_subject_assignments
    WHERE id = NEW.assignment_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignmentTransition references missing Assignment'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_teacher_subject_assignment_transitions_assignment';
    END IF;

    SELECT to_status
    INTO previous_status
    FROM teacher_subject_assignment_transitions
    WHERE assignment_id = NEW.assignment_id
    ORDER BY sequence_number DESC
    LIMIT 1;

    IF NOT FOUND THEN
        IF NEW.from_status IS NOT NULL
           OR NEW.to_status <> 'active' THEN
            RAISE EXCEPTION
                'Initial TeacherSubjectAssignmentTransition must be NULL -> active'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_teacher_subject_assignment_transitions_initial';
        END IF;
    ELSE
        IF NEW.from_status IS DISTINCT FROM previous_status THEN
            RAISE EXCEPTION
                'TeacherSubjectAssignmentTransition history chain mismatch'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_teacher_subject_assignment_transitions_chain';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_teacher_subject_assignment_transitions_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON teacher_subject_assignment_transitions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_teacher_subject_assignment_transition();


CREATE OR REPLACE FUNCTION educore_validate_teacher_subject_assignment_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_assignment UUID;
    assignment_status TEXT;
    latest_status TEXT;
    transition_count BIGINT;
BEGIN
    IF TG_TABLE_NAME = 'teacher_subject_assignments' THEN
        target_assignment := NEW.id;
    ELSE
        target_assignment := NEW.assignment_id;
    END IF;

    SELECT status
    INTO assignment_status
    FROM teacher_subject_assignments
    WHERE id = target_assignment;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
    INTO transition_count
    FROM teacher_subject_assignment_transitions
    WHERE assignment_id = target_assignment;

    IF transition_count < 1 THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignment requires authoritative transition history'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignments_history_required';
    END IF;

    SELECT to_status
    INTO latest_status
    FROM teacher_subject_assignment_transitions
    WHERE assignment_id = target_assignment
    ORDER BY sequence_number DESC
    LIMIT 1;

    IF latest_status IS DISTINCT FROM assignment_status THEN
        RAISE EXCEPTION
            'TeacherSubjectAssignment current status does not match authoritative transition history'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_teacher_subject_assignments_history_consistent';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER trg_teacher_subject_assignments_history_consistency
AFTER INSERT OR UPDATE OF status
ON teacher_subject_assignments
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_teacher_subject_assignment_history();

CREATE CONSTRAINT TRIGGER trg_teacher_subject_assignment_transitions_history_consistency
AFTER INSERT
ON teacher_subject_assignment_transitions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_teacher_subject_assignment_history();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'EduCore TeacherSubjectAssignment foundation is intentionally forward-only.'
        );
    }
};

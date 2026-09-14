<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore StudentEnrollment requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE student_enrollments (
    id UUID PRIMARY KEY,
    learner_profile_id UUID NOT NULL,
    teacher_subject_assignment_id UUID NOT NULL,
    status TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NULL,

    CONSTRAINT fk_student_enrollments_learner_profile
        FOREIGN KEY (learner_profile_id)
        REFERENCES learner_profiles(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_student_enrollments_teacher_assignment
        FOREIGN KEY (teacher_subject_assignment_id)
        REFERENCES teacher_subject_assignments(id)
        ON DELETE RESTRICT,

    CONSTRAINT chk_student_enrollments_status
        CHECK (
            status IN (
                'pending',
                'active',
                'inactive'
            )
        ),

    CONSTRAINT uq_student_enrollments_pair
        UNIQUE (
            learner_profile_id,
            teacher_subject_assignment_id
        )
);

CREATE INDEX idx_student_enrollments_assignment
    ON student_enrollments (
        teacher_subject_assignment_id,
        status
    );

CREATE INDEX idx_student_enrollments_learner
    ON student_enrollments (
        learner_profile_id,
        status
    );


CREATE TABLE student_enrollment_transitions (
    id UUID PRIMARY KEY,
    sequence_number BIGINT GENERATED ALWAYS AS IDENTITY,
    enrollment_id UUID NOT NULL,
    from_status TEXT NULL,
    to_status TEXT NOT NULL,
    actor_user_id UUID NOT NULL,
    operation_id UUID NOT NULL,
    outcome TEXT NOT NULL,
    reason TEXT NOT NULL,
    effective_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT fk_student_enrollment_transitions_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES student_enrollments(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_student_enrollment_transitions_actor
        FOREIGN KEY (actor_user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_student_enrollment_transitions_operation
        UNIQUE (operation_id),

    CONSTRAINT uq_student_enrollment_transitions_sequence
        UNIQUE (sequence_number),

    CONSTRAINT chk_student_enrollment_transitions_from_status
        CHECK (
            from_status IS NULL
            OR from_status IN (
                'pending',
                'active',
                'inactive'
            )
        ),

    CONSTRAINT chk_student_enrollment_transitions_to_status
        CHECK (
            to_status IN (
                'pending',
                'active',
                'inactive'
            )
        ),

    CONSTRAINT chk_student_enrollment_transitions_outcome
        CHECK (
            outcome IN (
                'requested',
                'accepted',
                'declined',
                'deactivated',
                'rejoined'
            )
        ),

    CONSTRAINT chk_student_enrollment_transitions_shape
        CHECK (
            (
                from_status IS NULL
                AND to_status = 'pending'
                AND outcome = 'requested'
            )
            OR
            (
                from_status = 'pending'
                AND to_status = 'active'
                AND outcome = 'accepted'
            )
            OR
            (
                from_status = 'pending'
                AND to_status = 'inactive'
                AND outcome = 'declined'
            )
            OR
            (
                from_status = 'active'
                AND to_status = 'inactive'
                AND outcome = 'deactivated'
            )
            OR
            (
                from_status = 'inactive'
                AND to_status = 'pending'
                AND outcome = 'rejoined'
            )
        ),

    CONSTRAINT chk_student_enrollment_transitions_reason
        CHECK (BTRIM(reason) <> '')
);

CREATE INDEX idx_student_enrollment_transitions_enrollment
    ON student_enrollment_transitions (
        enrollment_id,
        sequence_number
    );

CREATE INDEX idx_student_enrollment_transitions_actor
    ON student_enrollment_transitions (
        actor_user_id
    );


CREATE OR REPLACE FUNCTION educore_guard_student_enrollment()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    learner_record RECORD;
    learner_user RECORD;
    assignment_record RECORD;
    teacher_record RECORD;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'StudentEnrollment cannot be deleted'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_student_enrollments_durable';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.learner_profile_id
               IS DISTINCT FROM NEW.learner_profile_id
           OR OLD.teacher_subject_assignment_id
               IS DISTINCT FROM NEW.teacher_subject_assignment_id THEN
            RAISE EXCEPTION
                'StudentEnrollment identity is immutable'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollments_identity_immutable';
        END IF;

        IF OLD.status IS DISTINCT FROM NEW.status THEN
            IF NOT (
                (
                    OLD.status = 'pending'
                    AND NEW.status IN (
                        'active',
                        'inactive'
                    )
                )
                OR
                (
                    OLD.status = 'active'
                    AND NEW.status = 'inactive'
                )
                OR
                (
                    OLD.status = 'inactive'
                    AND NEW.status = 'pending'
                )
            ) THEN
                RAISE EXCEPTION
                    'Invalid StudentEnrollment transition: % -> %',
                    OLD.status,
                    NEW.status
                    USING
                        ERRCODE = '23514',
                        CONSTRAINT =
                            'chk_student_enrollments_transition';
            END IF;
        END IF;

        IF OLD.status IS NOT DISTINCT FROM NEW.status THEN
            RETURN NEW;
        END IF;

        IF NOT (
            (
                OLD.status = 'inactive'
                AND NEW.status = 'pending'
            )
            OR
            (
                OLD.status = 'pending'
                AND NEW.status = 'active'
            )
        ) THEN
            RETURN NEW;
        END IF;
    ELSE
        IF NEW.status <> 'pending' THEN
            RAISE EXCEPTION
                'StudentEnrollment must be created pending'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollments_initial_status';
        END IF;
    END IF;

    SELECT id, user_id
    INTO learner_record
    FROM learner_profiles
    WHERE id = NEW.learner_profile_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollment references missing LearnerProfile'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_student_enrollments_learner_profile';
    END IF;

    SELECT id, role, status
    INTO learner_user
    FROM users
    WHERE id = learner_record.user_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollment LearnerProfile has missing User'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_learner_profiles_user';
    END IF;

    SELECT id, teacher_id, status
    INTO assignment_record
    FROM teacher_subject_assignments
    WHERE id = NEW.teacher_subject_assignment_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollment references missing TeacherSubjectAssignment'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_student_enrollments_teacher_assignment';
    END IF;

    SELECT id, role, status
    INTO teacher_record
    FROM users
    WHERE id = assignment_record.teacher_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollment assignment references missing Teacher'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_teacher_subject_assignments_teacher';
    END IF;

    SELECT id, teacher_id, status
    INTO assignment_record
    FROM teacher_subject_assignments
    WHERE id = NEW.teacher_subject_assignment_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollment references missing TeacherSubjectAssignment'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_student_enrollments_teacher_assignment';
    END IF;

    IF TG_OP = 'INSERT'
       OR (
           TG_OP = 'UPDATE'
           AND OLD.status = 'inactive'
           AND NEW.status = 'pending'
       ) THEN
        IF learner_user.role <> 'student'
           OR learner_user.status <> 'active' THEN
            RAISE EXCEPTION
                'StudentEnrollment request requires an active STUDENT owner'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollments_student_eligible';
        END IF;
    END IF;

    IF assignment_record.status <> 'active' THEN
        RAISE EXCEPTION
            'StudentEnrollment grant transition requires an active TeacherSubjectAssignment'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_student_enrollments_assignment_eligible';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_student_enrollments_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON student_enrollments
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_student_enrollment();


CREATE OR REPLACE FUNCTION educore_guard_student_enrollment_transition()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    enrollment_identity RECORD;
    learner_record RECORD;
    learner_user RECORD;
    assignment_identity RECORD;
    assignment_record RECORD;
    teacher_record RECORD;
    actor_record RECORD;
    previous_status TEXT;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition is append-only'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_student_enrollment_transitions_append_only';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition cannot be deleted'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_student_enrollment_transitions_append_only';
    END IF;

    /*
     * Read actor identity without locking so an ADMIN actor can be
     * placed at the front of the shared C/D lock protocol.
     * users.role is DB-immutable. Status is re-read under lock below.
     */
    SELECT id, role, status
    INTO actor_record
    FROM users
    WHERE id = NEW.actor_user_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition references missing actor'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_student_enrollment_transitions_actor';
    END IF;

    IF actor_record.role = 'admin' THEN
        SELECT id, role, status
        INTO actor_record
        FROM users
        WHERE id = NEW.actor_user_id
        FOR UPDATE;

        IF actor_record.status <> 'active' THEN
            RAISE EXCEPTION
                'Enrollment ADMIN actor must be active'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_admin_actor';
        END IF;
    END IF;

    SELECT
        id,
        learner_profile_id,
        teacher_subject_assignment_id,
        status
    INTO enrollment_identity
    FROM student_enrollments
    WHERE id = NEW.enrollment_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition references missing Enrollment'
            USING
                ERRCODE = '23503',
                CONSTRAINT =
                    'fk_student_enrollment_transitions_enrollment';
    END IF;

    SELECT id, user_id
    INTO learner_record
    FROM learner_profiles
    WHERE id = enrollment_identity.learner_profile_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition references missing LearnerProfile'
            USING ERRCODE = '23503';
    END IF;

    SELECT id, role, status
    INTO learner_user
    FROM users
    WHERE id = learner_record.user_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition references missing learner User'
            USING ERRCODE = '23503';
    END IF;

    SELECT id, teacher_id, status
    INTO assignment_identity
    FROM teacher_subject_assignments
    WHERE id = enrollment_identity.teacher_subject_assignment_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition references missing TeacherSubjectAssignment'
            USING ERRCODE = '23503';
    END IF;

    SELECT id, role, status
    INTO teacher_record
    FROM users
    WHERE id = assignment_identity.teacher_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition references missing Teacher'
            USING ERRCODE = '23503';
    END IF;

    SELECT id, teacher_id, status
    INTO assignment_record
    FROM teacher_subject_assignments
    WHERE id = enrollment_identity.teacher_subject_assignment_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'StudentEnrollmentTransition references missing TeacherSubjectAssignment'
            USING ERRCODE = '23503';
    END IF;

    IF actor_record.role <> 'admin' THEN
        /*
         * STUDENT and TEACHER actors are already locked through the
         * canonical learner-owner / assignment-teacher parent rows.
         * Re-read actor state without acquiring a second lock order.
         */
        SELECT id, role, status
        INTO actor_record
        FROM users
        WHERE id = NEW.actor_user_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION
                'StudentEnrollmentTransition references missing actor'
                USING
                    ERRCODE = '23503',
                    CONSTRAINT =
                        'fk_student_enrollment_transitions_actor';
        END IF;
    END IF;

    IF NEW.outcome IN ('requested', 'rejoined') THEN
        IF NEW.actor_user_id <> learner_user.id
           OR learner_user.role <> 'student'
           OR learner_user.status <> 'active' THEN
            RAISE EXCEPTION
                'Enrollment request/rejoin requires the active STUDENT owner'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_student_actor';
        END IF;

        IF assignment_record.status <> 'active' THEN
            RAISE EXCEPTION
                'Enrollment request/rejoin requires an active assignment'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_assignment_active';
        END IF;
    ELSIF NEW.outcome IN ('accepted', 'declined') THEN
        IF NEW.actor_user_id <> teacher_record.id
           OR teacher_record.role <> 'teacher'
           OR teacher_record.status <> 'active' THEN
            RAISE EXCEPTION
                'Enrollment accept/decline requires the active assignment TEACHER'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_teacher_actor';
        END IF;

        IF NEW.outcome = 'accepted'
           AND assignment_record.status <> 'active' THEN
            RAISE EXCEPTION
                'Enrollment acceptance requires an active assignment'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_assignment_active';
        END IF;
    ELSIF NEW.outcome = 'deactivated' THEN
        IF NOT (
            (
                NEW.actor_user_id = teacher_record.id
                AND teacher_record.role = 'teacher'
                AND teacher_record.status = 'active'
            )
            OR
            (
                actor_record.role = 'admin'
                AND actor_record.status = 'active'
            )
        ) THEN
            RAISE EXCEPTION
                'Enrollment deactivation requires owning active TEACHER or active ADMIN'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_deactivate_actor';
        END IF;
    END IF;

    SELECT to_status
    INTO previous_status
    FROM student_enrollment_transitions
    WHERE enrollment_id = NEW.enrollment_id
    ORDER BY sequence_number DESC
    LIMIT 1;

    IF NOT FOUND THEN
        IF NEW.from_status IS NOT NULL
           OR NEW.to_status <> 'pending'
           OR NEW.outcome <> 'requested' THEN
            RAISE EXCEPTION
                'Initial StudentEnrollmentTransition must be requested NULL -> pending'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_initial';
        END IF;
    ELSE
        IF NEW.from_status IS DISTINCT FROM previous_status THEN
            RAISE EXCEPTION
                'StudentEnrollmentTransition history chain mismatch'
                USING
                    ERRCODE = '23514',
                    CONSTRAINT =
                        'chk_student_enrollment_transitions_chain';
        END IF;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_student_enrollment_transitions_integrity
BEFORE INSERT OR UPDATE OR DELETE
ON student_enrollment_transitions
FOR EACH ROW
EXECUTE PROCEDURE educore_guard_student_enrollment_transition();


CREATE OR REPLACE FUNCTION educore_validate_student_enrollment_history()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    target_enrollment UUID;
    enrollment_status TEXT;
    latest_status TEXT;
    transition_count BIGINT;
BEGIN
    IF TG_TABLE_NAME = 'student_enrollments' THEN
        target_enrollment := NEW.id;
    ELSE
        target_enrollment := NEW.enrollment_id;
    END IF;

    SELECT status
    INTO enrollment_status
    FROM student_enrollments
    WHERE id = target_enrollment;

    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    SELECT COUNT(*)
    INTO transition_count
    FROM student_enrollment_transitions
    WHERE enrollment_id = target_enrollment;

    IF transition_count < 1 THEN
        RAISE EXCEPTION
            'StudentEnrollment requires authoritative transition history'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_student_enrollments_history_required';
    END IF;

    SELECT to_status
    INTO latest_status
    FROM student_enrollment_transitions
    WHERE enrollment_id = target_enrollment
    ORDER BY sequence_number DESC
    LIMIT 1;

    IF latest_status IS DISTINCT FROM enrollment_status THEN
        RAISE EXCEPTION
            'StudentEnrollment current status does not match authoritative transition history'
            USING
                ERRCODE = '23514',
                CONSTRAINT =
                    'chk_student_enrollments_history_consistent';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER trg_student_enrollments_history_consistency
AFTER INSERT OR UPDATE OF status
ON student_enrollments
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_student_enrollment_history();

CREATE CONSTRAINT TRIGGER trg_student_enrollment_transitions_history_consistency
AFTER INSERT
ON student_enrollment_transitions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE PROCEDURE educore_validate_student_enrollment_history();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'EduCore StudentEnrollment foundation is intentionally forward-only.'
        );
    }
};

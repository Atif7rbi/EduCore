import type {
    CatalogStatus,
    UserStatus,
} from './types';

export type StudentEnrollmentStatus =
    | 'pending'
    | 'active'
    | 'inactive';

export interface AdminStudent {
    /*
     * Canonical Admin Student route identity.
     * This is users.id.
     */
    user_id: string;

    /*
     * Educational identity.
     * Never interchangeable with user_id.
     */
    learner_profile_id: string;

    name: string;
    email: string;
    role: 'student';
    status: UserStatus;

    enrollment_counts: {
        pending: number;
        active: number;
        inactive: number;
        total: number;
    };

    created_at: string | null;
    learner_profile_created_at:
        string | null;
}

export interface StudentEnrollmentTransition {
    sequence_number: number;
    from_status:
        StudentEnrollmentStatus | null;
    to_status:
        StudentEnrollmentStatus;
    outcome: string;

    /*
     * Immutable event provenance.
     */
    actor_user_id: string;

    /*
     * Current User display state only.
     * Not an event-time snapshot.
     */
    actor_current: {
        name: string;
        role:
            | 'admin'
            | 'teacher'
            | 'student';
        status: UserStatus;
    };

    operation_id: string;
    reason: string;
    effective_at: string | null;
    created_at: string | null;
}

export interface StudentEnrollmentRead {
    id: string;

    student: {
        user_id: string;
        learner_profile_id: string;
        name: string;
        email: string;
        status: UserStatus;
    };

    /*
     * Raw StudentEnrollment lifecycle status.
     * This must not be presented as effective
     * learner authorization.
     */
    status: StudentEnrollmentStatus;

    teacher_subject_assignment: {
        id: string;
        status:
            | 'active'
            | 'inactive';

        teacher: {
            user_id: string;
            name: string;
            email: string;
            status: UserStatus;
        };

        subject: {
            id: string;
            code: string;
            name: string;
            status: CatalogStatus;
        };
    };

    created_at: string | null;
    updated_at: string | null;

    /*
     * Present on detail reads only.
     */
    transitions?:
        StudentEnrollmentTransition[];
}

export interface StudentEnrollmentOperationPayload {
    operation_id: string;
    reason: string;
}

export interface StudentEnrollmentLifecycleResult {
    id: string;
    learner_profile_id: string;
    teacher_subject_assignment_id: string;
    status: StudentEnrollmentStatus;
    created_at: string | null;
    updated_at: string | null;
}

export type UserStatus =
    | 'active'
    | 'disabled';

export type CatalogStatus =
    | 'active'
    | 'inactive';

export interface AdminCanonicalSubject {
    id: string;
    code: string;
    name: string;
    status: CatalogStatus;
}

export type TeacherProvisioningState =
    | 'untracked'
    | 'pending_setup'
    | 'completed';

export interface AdminTeacher {
    user_id: string;
    name: string;
    email: string;
    role: 'teacher';
    status: UserStatus;
    provisioning: {
        state: TeacherProvisioningState;
        provisioned_by_user_id: string | null;
        setup_completed_at: string | null;
        created_at: string | null;
    };
    assignment_counts: {
        active: number;
        inactive: number;
        total: number;
    };
    created_at: string | null;
}

export interface ProvisionTeacherPayload {
    name: string;
    email: string;
}

export interface ProvisionTeacherResult {
    teacher: {
        id: string;
        name: string;
        email: string;
        role: 'teacher';
        status: UserStatus;
    };
    setup: {
        delivery:
            | 'sent'
            | 'failed';
    };
}

export interface TeacherSubjectAssignment {
    id: string;
    teacher_user_id: string;
    subject: {
        id: string;
        code: string;
        name: string;
        status: string;
    };
    status:
        | 'active'
        | 'inactive';
    created_at: string | null;
    updated_at: string | null;
}

export interface AssignmentOperationPayload {
    operation_id: string;
    reason: string;
}

export interface AssignTeacherSubjectPayload
    extends AssignmentOperationPayload {
    subject_id: string;
}

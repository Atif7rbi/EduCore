import {
    apiRequest,
} from '../api/client';

export type TeacherEnrollmentStatus =
    | 'pending'
    | 'active';

export interface TeacherEnrollment {
    id: string;
    student: {
        user_id: string;
        name: string;
    };
    teacher_subject_assignment: {
        id: string;
        status: 'active';
        subject: {
            id: string;
            code: string;
            name: string;
        };
    };
    status: TeacherEnrollmentStatus;
    created_at: string | null;
    accepted_at: string | null;
    updated_at: string | null;
}

export interface TeacherEnrollmentPage {
    items: TeacherEnrollment[];
    pagination: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
}

export type TeacherEnrollmentDecision =
    | 'accept'
    | 'decline';

export function teacherEnrollmentsKey(
    authenticatedUserId: string,
    status: TeacherEnrollmentStatus,
    page: number,
    assignmentId: string | null = null,
) {
    return [
        'teacher',
        authenticatedUserId,
        'enrollments',
        status,
        assignmentId ?? 'all-assignments',
        page,
    ] as const;
}

export function teacherEnrollmentsRootKey(
    authenticatedUserId: string,
) {
    return [
        'teacher',
        authenticatedUserId,
        'enrollments',
    ] as const;
}

export function fetchTeacherEnrollments(
    status: TeacherEnrollmentStatus,
    page: number,
    signal?: AbortSignal,
    assignmentId: string | null = null,
): Promise<TeacherEnrollmentPage> {
    return apiRequest({
        method: 'GET',
        url: '/api/teacher/enrollments',
        params: {
            status,
            assignment_id: assignmentId ?? undefined,
            page,
            per_page: 20,
        },
        signal,
    });
}

export function decideTeacherEnrollment(
    enrollmentId: string,
    decision: TeacherEnrollmentDecision,
    operationId: string,
    reason: string,
): Promise<{
    id: string;
    status: TeacherEnrollmentStatus | 'inactive';
}> {
    return apiRequest({
        method: 'POST',
        url: '/api/teacher/enrollments/'
            + enrollmentId
            + '/'
            + decision,
        data: {
            operation_id: operationId,
            reason,
        },
    });
}

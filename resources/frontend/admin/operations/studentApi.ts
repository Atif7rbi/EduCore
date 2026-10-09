import {
    apiRequest,
} from '../../api/client';

import type {
    AdminStudent,
    StudentEnrollmentLifecycleResult,
    StudentEnrollmentOperationPayload,
    StudentEnrollmentRead,
} from './studentTypes';

export function adminStudentsKey() {
    return [
        'admin',
        'operations',
        'students',
    ] as const;
}

export function adminStudentKey(
    studentUserId: string,
) {
    return [
        ...adminStudentsKey(),
        studentUserId,
    ] as const;
}

export function studentEnrollmentsKey(
    studentUserId: string,
) {
    return [
        ...adminStudentKey(
            studentUserId
        ),
        'enrollments',
    ] as const;
}

export function studentEnrollmentKey(
    enrollmentId: string,
) {
    return [
        'admin',
        'operations',
        'student-enrollments',
        enrollmentId,
    ] as const;
}

export function fetchAdminStudents():
Promise<AdminStudent[]> {
    return apiRequest<AdminStudent[]>({
        method: 'GET',
        url: '/api/admin/students',
    });
}

export function fetchAdminStudent(
    studentUserId: string,
): Promise<AdminStudent> {
    return apiRequest<AdminStudent>({
        method: 'GET',
        url:
            `/api/admin/students/${studentUserId}`,
    });
}

export function fetchStudentEnrollments(
    studentUserId: string,
): Promise<StudentEnrollmentRead[]> {
    return apiRequest<
        StudentEnrollmentRead[]
    >({
        method: 'GET',
        /*
         * Route identity is users.id.
         * The backend resolves the exact
         * learner_profile_id internally.
         */
        url:
            `/api/admin/students/${studentUserId}/enrollments`,
    });
}

export function fetchStudentEnrollment(
    enrollmentId: string,
): Promise<StudentEnrollmentRead> {
    return apiRequest<
        StudentEnrollmentRead
    >({
        method: 'GET',
        url:
            `/api/admin/student-enrollments/${enrollmentId}`,
    });
}

export function deactivateStudentEnrollment(
    enrollmentId: string,
    payload:
        StudentEnrollmentOperationPayload,
): Promise<StudentEnrollmentLifecycleResult> {
    return apiRequest<
        StudentEnrollmentLifecycleResult
    >({
        method: 'POST',
        url:
            `/api/admin/student-enrollments/${enrollmentId}/deactivate`,
        data: payload,
    });
}

import {
    apiRequest,
} from '../../api/client';

import type {
    AdminDirectoryParameters,
    DirectoryPage,
} from './types';
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

export function adminStudentDirectoryKey(
    parameters: AdminDirectoryParameters,
) {
    return [
        ...adminStudentsKey(),
        'directory',
        parameters.page,
        parameters.perPage,
        parameters.search,
        parameters.status ?? 'all',
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

export function fetchAdminStudents(
    signal?: AbortSignal,
):
Promise<AdminStudent[]> {
    return apiRequest<AdminStudent[]>({
        method: 'GET',
        url: '/api/admin/students',
        signal,
    });
}

export function fetchAdminStudentDirectory(
    parameters: AdminDirectoryParameters,
    signal?: AbortSignal,
): Promise<DirectoryPage<AdminStudent>> {
    return apiRequest<DirectoryPage<AdminStudent>>({
        method: 'GET',
        url: '/api/admin/directories/students',
        params: {
            page: parameters.page,
            per_page: parameters.perPage,
            search: parameters.search || undefined,
            status: parameters.status ?? undefined,
        },
        signal,
    });
}

export function fetchAdminStudent(
    studentUserId: string,
    signal?: AbortSignal,
): Promise<AdminStudent> {
    return apiRequest<AdminStudent>({
        method: 'GET',
        url:
            `/api/admin/students/${studentUserId}`,
        signal,
    });
}

export function fetchStudentEnrollments(
    studentUserId: string,
    signal?: AbortSignal,
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
        signal,
    });
}

export function fetchStudentEnrollment(
    enrollmentId: string,
    signal?: AbortSignal,
): Promise<StudentEnrollmentRead> {
    return apiRequest<
        StudentEnrollmentRead
    >({
        method: 'GET',
        url:
            `/api/admin/student-enrollments/${enrollmentId}`,
        signal,
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

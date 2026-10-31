import {
    apiRequest,
} from '../../api/client';

import type {
    AdminDirectoryParameters,
    AdminCanonicalSubject,
    AdminTeacher,
    AssignmentOperationPayload,
    AssignTeacherSubjectPayload,
    DirectoryPage,
    ProvisionTeacherPayload,
    ProvisionTeacherResult,
    TeacherSubjectAssignment,
} from './types';

export function adminCanonicalSubjectsKey() {
    return [
        'admin',
        'operations',
        'subjects',
    ] as const;
}

export function fetchAdminCanonicalSubjects(
    signal?: AbortSignal,
):
Promise<AdminCanonicalSubject[]> {
    return apiRequest<AdminCanonicalSubject[]>({
        method: 'GET',
        url: '/api/admin/subjects',
        signal,
    });
}

export function adminTeachersKey() {
    return [
        'admin',
        'operations',
        'teachers',
    ] as const;
}

export function adminTeacherDirectoryKey(
    parameters: AdminDirectoryParameters,
) {
    return [
        ...adminTeachersKey(),
        'directory',
        parameters.page,
        parameters.perPage,
        parameters.search,
        parameters.status ?? 'all',
    ] as const;
}

export function adminTeacherKey(
    teacherUserId: string,
) {
    return [
        ...adminTeachersKey(),
        teacherUserId,
    ] as const;
}

export function teacherAssignmentsKey(
    teacherUserId: string,
) {
    return [
        ...adminTeacherKey(
            teacherUserId
        ),
        'subject-assignments',
    ] as const;
}

export function fetchAdminTeachers(
    signal?: AbortSignal,
):
Promise<AdminTeacher[]> {
    return apiRequest<AdminTeacher[]>({
        method: 'GET',
        url: '/api/admin/teachers',
        signal,
    });
}

export function fetchAdminTeacherDirectory(
    parameters: AdminDirectoryParameters,
    signal?: AbortSignal,
): Promise<DirectoryPage<AdminTeacher>> {
    return apiRequest<DirectoryPage<AdminTeacher>>({
        method: 'GET',
        url: '/api/admin/directories/teachers',
        params: {
            page: parameters.page,
            per_page: parameters.perPage,
            search: parameters.search || undefined,
            status: parameters.status ?? undefined,
        },
        signal,
    });
}

export function fetchAdminTeacher(
    teacherUserId: string,
    signal?: AbortSignal,
): Promise<AdminTeacher> {
    return apiRequest<AdminTeacher>({
        method: 'GET',
        url:
            `/api/admin/teachers/${teacherUserId}`,
        signal,
    });
}

export function provisionTeacher(
    payload: ProvisionTeacherPayload,
): Promise<ProvisionTeacherResult> {
    return apiRequest<ProvisionTeacherResult>({
        method: 'POST',
        url: '/api/admin/teachers',
        data: payload,
    });
}

export function fetchTeacherAssignments(
    teacherUserId: string,
    signal?: AbortSignal,
): Promise<TeacherSubjectAssignment[]> {
    return apiRequest<
        TeacherSubjectAssignment[]
    >({
        method: 'GET',
        url:
            `/api/admin/teachers/${teacherUserId}/subject-assignments`,
        signal,
    });
}

export function assignTeacherSubject(
    teacherUserId: string,
    payload: AssignTeacherSubjectPayload,
): Promise<TeacherSubjectAssignment> {
    return apiRequest<
        TeacherSubjectAssignment
    >({
        method: 'POST',
        url:
            `/api/admin/teachers/${teacherUserId}/subject-assignments`,
        data: payload,
    });
}

export function deactivateTeacherAssignment(
    assignmentId: string,
    payload: AssignmentOperationPayload,
): Promise<TeacherSubjectAssignment> {
    return apiRequest<
        TeacherSubjectAssignment
    >({
        method: 'POST',
        url:
            `/api/admin/teacher-subject-assignments/${assignmentId}/deactivate`,
        data: payload,
    });
}

export function reactivateTeacherAssignment(
    assignmentId: string,
    payload: AssignmentOperationPayload,
): Promise<TeacherSubjectAssignment> {
    return apiRequest<
        TeacherSubjectAssignment
    >({
        method: 'POST',
        url:
            `/api/admin/teacher-subject-assignments/${assignmentId}/reactivate`,
        data: payload,
    });
}

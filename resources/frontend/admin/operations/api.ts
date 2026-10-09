import {
    apiRequest,
} from '../../api/client';

import type {
    AdminCanonicalSubject,
    AdminTeacher,
    AssignmentOperationPayload,
    AssignTeacherSubjectPayload,
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

export function fetchAdminCanonicalSubjects():
Promise<AdminCanonicalSubject[]> {
    return apiRequest<AdminCanonicalSubject[]>({
        method: 'GET',
        url: '/api/admin/subjects',
    });
}

export function adminTeachersKey() {
    return [
        'admin',
        'operations',
        'teachers',
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

export function fetchAdminTeachers():
Promise<AdminTeacher[]> {
    return apiRequest<AdminTeacher[]>({
        method: 'GET',
        url: '/api/admin/teachers',
    });
}

export function fetchAdminTeacher(
    teacherUserId: string,
): Promise<AdminTeacher> {
    return apiRequest<AdminTeacher>({
        method: 'GET',
        url:
            `/api/admin/teachers/${teacherUserId}`,
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
): Promise<TeacherSubjectAssignment[]> {
    return apiRequest<
        TeacherSubjectAssignment[]
    >({
        method: 'GET',
        url:
            `/api/admin/teachers/${teacherUserId}/subject-assignments`,
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

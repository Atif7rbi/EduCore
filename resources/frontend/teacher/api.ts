import type {
    AxiosRequestConfig,
} from 'axios';

import {
    apiRequest,
} from '../api/client';

import type {
    TeacherCurriculum,
    TeacherCurriculumVersion,
    TeacherSubjectAssignment,
} from './types';

export interface TeacherQueryScope {
    authenticatedUserId: string;
    assignmentId?: string;
    curriculumId?: string;
    curriculumVersionId?: string;
    resourceType: string;
    resourceId?: string;
}

export function teacherQueryKey({
    assignmentId,
    authenticatedUserId,
    curriculumId,
    curriculumVersionId,
    resourceId,
    resourceType,
}: TeacherQueryScope) {
    return [
        'teacher',
        authenticatedUserId,
        'assignment',
        assignmentId ?? null,
        'curriculum',
        curriculumId ?? null,
        'curriculum-version',
        curriculumVersionId ?? null,
        'resource',
        resourceType,
        resourceId ?? null,
    ] as const;
}

export function teacherAssignmentsKey(
    authenticatedUserId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        resourceType: 'assignments',
    });
}

export function teacherCurriculaKey(
    authenticatedUserId: string,
    assignmentId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        resourceType: 'curricula',
    });
}

export function teacherCurriculumVersionsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        resourceType: 'curriculum-versions',
    });
}

export function teacherApiRequest<T>(
    config: AxiosRequestConfig,
    signal?: AbortSignal,
): Promise<T> {
    return apiRequest<T>({
        ...config,
        signal: signal ?? config.signal,
    });
}

export function fetchTeacherAssignments(
    signal?: AbortSignal,
): Promise<TeacherSubjectAssignment[]> {
    return teacherApiRequest({
        method: 'GET',
        url: '/api/teacher/subject-assignments',
    }, signal);
}

export function fetchTeacherCurricula(
    assignmentId: string,
    signal?: AbortSignal,
): Promise<TeacherCurriculum[]> {
    return teacherApiRequest({
        method: 'GET',
        url:
            '/api/teacher/subject-assignments/'
            + assignmentId
            + '/curricula',
    }, signal);
}

export function fetchTeacherCurriculumVersions(
    assignmentId: string,
    curriculumId: string,
    signal?: AbortSignal,
): Promise<TeacherCurriculumVersion[]> {
    return teacherApiRequest({
        method: 'GET',
        url:
            '/api/teacher/subject-assignments/'
            + assignmentId
            + '/curricula/'
            + curriculumId
            + '/versions',
    }, signal);
}

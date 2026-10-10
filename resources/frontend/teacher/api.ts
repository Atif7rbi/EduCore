import type {
    AxiosRequestConfig,
} from 'axios';

import {
    apiRequest,
} from '../api/client';

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

export function teacherApiRequest<T>(
    config: AxiosRequestConfig,
    signal?: AbortSignal,
): Promise<T> {
    return apiRequest<T>({
        ...config,
        signal: signal ?? config.signal,
    });
}
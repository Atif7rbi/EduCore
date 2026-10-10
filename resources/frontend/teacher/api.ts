import type {
    AxiosRequestConfig,
} from 'axios';

import {
    apiRequest,
} from '../api/client';

import type {
    TeacherCurriculum,
    TeacherCurriculumVersion,
    TeacherSkill,
    TeacherSkillPlacement,
    TeacherSubjectAssignment,
    TeacherTopic,
} from './types';

export interface TeacherQueryScope {
    authenticatedUserId: string;
    assignmentId?: string;
    curriculumId?: string;
    curriculumVersionId?: string;
    resourceType: string;
    resourceId?: string;
}

export interface TeacherTopicPayload {
    name: string;
    display_order: number;
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

export function teacherTopicsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'topics',
    });
}

export function teacherSkillPlacementsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'skill-placements',
    });
}

export function teacherSkillsKey(
    authenticatedUserId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        resourceType: 'skills',
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

function versionPath(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
): string {
    return '/api/teacher/subject-assignments/'
        + assignmentId
        + '/curricula/'
        + curriculumId
        + '/versions/'
        + curriculumVersionId;
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
        url: '/api/teacher/subject-assignments/'
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
        url: '/api/teacher/subject-assignments/'
            + assignmentId
            + '/curricula/'
            + curriculumId
            + '/versions',
    }, signal);
}

export function fetchTeacherTopics(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    signal?: AbortSignal,
): Promise<TeacherTopic[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/topics',
    }, signal);
}

export function createTeacherTopic(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    payload: TeacherTopicPayload,
): Promise<TeacherTopic> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/topics',
        data: payload,
    });
}

export function updateTeacherTopic(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    topicId: string,
    payload: TeacherTopicPayload,
): Promise<TeacherTopic> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/topics/' + topicId,
        data: payload,
    });
}

export function fetchTeacherSkills(
    signal?: AbortSignal,
): Promise<TeacherSkill[]> {
    return teacherApiRequest({
        method: 'GET',
        url: '/api/teacher/skills',
    }, signal);
}

export function fetchTeacherSkillPlacements(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    signal?: AbortSignal,
): Promise<TeacherSkillPlacement[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/skill-placements',
    }, signal);
}

export function createTeacherSkillPlacement(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    skillId: string,
): Promise<TeacherSkillPlacement> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/skill-placements',
        data: {
            skill_id: skillId,
        },
    });
}

export function deleteTeacherSkillPlacement(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    placementId: string,
): Promise<{
    id: string;
    deleted: boolean;
}> {
    return teacherApiRequest({
        method: 'DELETE',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/skill-placements/' + placementId,
    });
}

export function createTeacherHomeTopic(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    placementId: string,
    topicId: string,
) {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/skill-placements/'
            + placementId
            + '/home-topics',
        data: {
            topic_id: topicId,
        },
    });
}

export function deleteTeacherHomeTopic(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    placementId: string,
    homeTopicId: string,
): Promise<{
    id: string;
    deleted: boolean;
}> {
    return teacherApiRequest({
        method: 'DELETE',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/skill-placements/'
            + placementId
            + '/home-topics/'
            + homeTopicId,
    });
}

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

export function teacherLessonsKey(
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
        resourceType: 'lessons',
    });
}

export function teacherLessonRevisionsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'lesson-revisions',
        resourceId: lessonId,
    });
}

export function teacherLessonRevisionSkillsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    revisionId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'lesson-revision-skills',
        resourceId: revisionId,
    });
}

export function teacherAssessmentItemsKey(
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
        resourceType: 'assessment-items',
    });
}

export function teacherAssessmentRevisionsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    assessmentItemId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'assessment-revisions',
        resourceId: assessmentItemId,
    });
}

export function teacherAssessmentRevisionSkillsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    revisionId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'assessment-revision-skills',
        resourceId: revisionId,
    });
}

export function fetchTeacherLessons(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherLesson[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(
            assignmentId,
            curriculumId,
            curriculumVersionId,
        ) + '/lessons',
    }, signal);
}

export function createTeacherLesson(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    payload: import('./types').TeacherLessonPayload,
): Promise<import('./types').TeacherLesson> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons',
        data: payload,
    });
}

export function updateTeacherLesson(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    payload: import('./types').TeacherLessonPayload,
): Promise<import('./types').TeacherLesson> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId,
        data: payload,
    });
}

export function fetchTeacherLessonRevisions(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherLessonRevision[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/revisions',
    }, signal);
}

export function createTeacherLessonRevision(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    payload: import('./types').TeacherLessonRevisionPayload,
): Promise<import('./types').TeacherLessonRevision> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/revisions',
        data: payload,
    });
}

export function updateTeacherLessonRevision(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    revisionId: string,
    payload: import('./types').TeacherLessonRevisionPayload,
): Promise<import('./types').TeacherLessonRevision> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/revisions/' + revisionId,
        data: payload,
    });
}

export function fetchTeacherLessonRevisionSkills(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    revisionId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherLessonRevisionSkill[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/revisions/' + revisionId + '/skills',
    }, signal);
}

export function createTeacherLessonRevisionSkill(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    revisionId: string,
    placementId: string,
): Promise<import('./types').TeacherLessonRevisionSkill> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/revisions/' + revisionId + '/skills',
        data: {
            skill_version_placement_id: placementId,
        },
    });
}

export function deleteTeacherLessonRevisionSkill(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    revisionId: string,
    associationId: string,
): Promise<{ id: string; deleted: boolean }> {
    return teacherApiRequest({
        method: 'DELETE',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/revisions/' + revisionId
            + '/skills/' + associationId,
    });
}

export function releaseTeacherLessonRevision(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    revisionId: string,
): Promise<import('./types').TeacherLessonRevision> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/revisions/' + revisionId + '/release',
    });
}

export function publishTeacherLesson(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
    revisionId: string,
): Promise<import('./types').TeacherLesson> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/publish',
        data: {
            published_revision_id: revisionId,
        },
    });
}

export function unpublishTeacherLesson(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    lessonId: string,
): Promise<import('./types').TeacherLesson> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/lessons/' + lessonId + '/unpublish',
    });
}

export function fetchTeacherAssessmentItems(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherAssessmentItem[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items',
    }, signal);
}

export function createTeacherAssessmentItem(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    payload: import('./types').TeacherAssessmentPayload,
): Promise<import('./types').TeacherAssessmentItem> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items',
        data: payload,
    });
}

export function updateTeacherAssessmentItem(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    payload: import('./types').TeacherAssessmentPayload,
): Promise<import('./types').TeacherAssessmentItem> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId,
        data: payload,
    });
}

export function fetchTeacherAssessmentRevisions(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherAssessmentItemRevision[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/revisions',
    }, signal);
}

export function createTeacherAssessmentRevision(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    payload: import('./types').TeacherAssessmentRevisionPayload,
): Promise<import('./types').TeacherAssessmentItemRevision> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/revisions',
        data: payload,
    });
}

export function updateTeacherAssessmentRevision(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    revisionId: string,
    payload: import('./types').TeacherAssessmentRevisionPayload,
): Promise<import('./types').TeacherAssessmentItemRevision> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/revisions/' + revisionId,
        data: payload,
    });
}

export function fetchTeacherAssessmentRevisionSkills(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    revisionId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherAssessmentItemRevisionSkill[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/revisions/' + revisionId + '/skills',
    }, signal);
}

export function createTeacherAssessmentRevisionSkill(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    revisionId: string,
    placementId: string,
    role: 'primary' | 'supporting',
): Promise<import('./types').TeacherAssessmentItemRevisionSkill> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/revisions/' + revisionId + '/skills',
        data: {
            skill_version_placement_id: placementId,
            role,
        },
    });
}

export function deleteTeacherAssessmentRevisionSkill(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    revisionId: string,
    associationId: string,
): Promise<{ id: string; deleted: boolean }> {
    return teacherApiRequest({
        method: 'DELETE',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/revisions/' + revisionId
            + '/skills/' + associationId,
    });
}

export function releaseTeacherAssessmentRevision(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    revisionId: string,
): Promise<import('./types').TeacherAssessmentItemRevision> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/revisions/' + revisionId + '/release',
    });
}

export function publishTeacherAssessmentItem(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
    revisionId: string,
): Promise<import('./types').TeacherAssessmentItem> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/publish',
        data: {
            published_revision_id: revisionId,
        },
    });
}

export function retireTeacherAssessmentItem(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    itemId: string,
): Promise<import('./types').TeacherAssessmentItem> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/assessment-items/' + itemId + '/retire',
    });
}

export function teacherPracticeActivitiesKey(
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
        resourceType: 'practice-activities',
    });
}

export function teacherPracticeItemsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    practiceId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'practice-activity-items',
        resourceId: practiceId,
    });
}

export function teacherExamTemplatesKey(
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
        resourceType: 'exam-templates',
    });
}

export function teacherExamTemplateVersionsKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'exam-template-versions',
        resourceId: templateId,
    });
}

export function teacherExamTemplateVersionKey(
    authenticatedUserId: string,
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateVersionId: string,
) {
    return teacherQueryKey({
        authenticatedUserId,
        assignmentId,
        curriculumId,
        curriculumVersionId,
        resourceType: 'exam-template-version',
        resourceId: templateVersionId,
    });
}

export function fetchTeacherPracticeActivities(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherPracticeActivity[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/practice-activities',
    }, signal);
}

export function createTeacherPracticeActivity(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    payload: import('./types').TeacherPracticePayload,
): Promise<import('./types').TeacherPracticeActivity> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/practice-activities',
        data: payload,
    });
}

export function updateTeacherPracticeActivity(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    practiceId: string,
    payload: import('./types').TeacherPracticePayload,
): Promise<import('./types').TeacherPracticeActivity> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/practice-activities/' + practiceId,
        data: payload,
    });
}

export function setTeacherPracticeActivityStatus(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    practiceId: string,
    status: import('./types').TeacherPracticeStatus,
): Promise<import('./types').TeacherPracticeActivity> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/practice-activities/' + practiceId + '/'
            + (status === 'active' ? 'activate' : 'archive'),
    });
}

export function fetchTeacherPracticeItems(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    practiceId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherPracticeActivityItem[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/practice-activities/' + practiceId + '/items',
    }, signal);
}

export function addTeacherPracticeItems(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    practiceId: string,
    payload: import('./types').TeacherPracticeMembershipPayload,
): Promise<import('./types').TeacherPracticeActivityItem[]> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/practice-activities/' + practiceId + '/items',
        data: payload,
    });
}

export function deleteTeacherPracticeItem(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    practiceId: string,
    membershipId: string,
): Promise<{ deleted: boolean }> {
    return teacherApiRequest({
        method: 'DELETE',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/practice-activities/' + practiceId + '/items/' + membershipId,
    });
}

export function fetchTeacherExamTemplates(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherExamTemplate[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates',
    }, signal);
}

export function createTeacherExamTemplate(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    payload: { name: string; description: string | null },
): Promise<import('./types').TeacherExamTemplate> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates',
        data: payload,
    });
}

export function updateTeacherExamTemplate(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    payload: { name: string; description: string | null },
): Promise<import('./types').TeacherExamTemplate> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId,
        data: payload,
    });
}

export function setTeacherExamTemplateStatus(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    status: import('./types').TeacherExamTemplateStatus,
): Promise<import('./types').TeacherExamTemplate> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/'
            + (status === 'active' ? 'activate' : 'archive'),
    });
}

export function fetchTeacherExamTemplateVersions(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherExamTemplateVersion[]> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/versions',
    }, signal);
}

export function fetchTeacherExamTemplateVersion(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    templateVersionId: string,
    signal?: AbortSignal,
): Promise<import('./types').TeacherExamTemplateVersion> {
    return teacherApiRequest({
        method: 'GET',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/versions/'
            + templateVersionId,
    }, signal);
}

export function createTeacherExamTemplateVersion(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    payload: import('./types').TeacherExamTemplateVersionPayload,
): Promise<import('./types').TeacherExamTemplateVersion> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/versions',
        data: payload,
    });
}

export function updateTeacherExamTemplateVersion(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    templateVersionId: string,
    payload: Omit<import('./types').TeacherExamTemplateVersionPayload, 'version_number'>,
): Promise<import('./types').TeacherExamTemplateVersion> {
    return teacherApiRequest({
        method: 'PUT',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/versions/'
            + templateVersionId,
        data: payload,
    });
}

export function publishTeacherExamTemplateVersion(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    templateVersionId: string,
): Promise<import('./types').TeacherExamTemplateVersion> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/versions/'
            + templateVersionId + '/publish',
    });
}

export function retireTeacherExamTemplateVersion(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    templateVersionId: string,
): Promise<import('./types').TeacherExamTemplateVersion> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/versions/'
            + templateVersionId + '/retire',
    });
}

export function createTeacherExamGeneration(
    assignmentId: string,
    curriculumId: string,
    curriculumVersionId: string,
    templateId: string,
    templateVersionId: string,
    payload: import('./types').TeacherExamGenerationPayload,
): Promise<import('./types').TeacherExamGeneration> {
    return teacherApiRequest({
        method: 'POST',
        url: versionPath(assignmentId, curriculumId, curriculumVersionId)
            + '/exam-templates/' + templateId + '/versions/'
            + templateVersionId + '/generations',
        data: payload,
    });
}

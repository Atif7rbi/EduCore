import {
    apiRequest,
} from '../../api/client';

import type {
    Curriculum,
    CurriculumVersion,
    Skill,
    SkillPlacement,
    Subject,
    Topic,
} from './types';

export function adminSubjectsKey() {
    return [
        'admin',
        'content',
        'subjects',
    ] as const;
}

export function adminCurriculaKey(
    subjectId: string,
) {
    return [
        'admin',
        'content',
        'subjects',
        subjectId,
        'curricula',
    ] as const;
}

export function adminVersionsKey(
    curriculumId: string,
) {
    return [
        'admin',
        'content',
        'curricula',
        curriculumId,
        'versions',
    ] as const;
}

export function adminTopicsKey(
    curriculumVersionId: string,
) {
    return [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'topics',
    ] as const;
}

export function adminSkillsKey() {
    return [
        'admin',
        'content',
        'skills',
    ] as const;
}

export function adminPlacementsKey(
    curriculumVersionId: string,
) {
    return [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'skill-placements',
    ] as const;
}

export function fetchSubjects():
Promise<Subject[]> {
    return apiRequest<Subject[]>({
        method: 'GET',
        url: '/api/admin/subjects',
    });
}

export function fetchCurricula(
    subjectId: string,
): Promise<Curriculum[]> {
    return apiRequest<Curriculum[]>({
        method: 'GET',
        url:
            `/api/admin/subjects/${subjectId}/curricula`,
    });
}

export function fetchVersions(
    curriculumId: string,
): Promise<CurriculumVersion[]> {
    return apiRequest<CurriculumVersion[]>({
        method: 'GET',
        url:
            `/api/admin/curricula/${curriculumId}/versions`,
    });
}

export function fetchTopics(
    curriculumVersionId: string,
): Promise<Topic[]> {
    return apiRequest<Topic[]>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/topics`,
    });
}

export function fetchSkills():
Promise<Skill[]> {
    return apiRequest<Skill[]>({
        method: 'GET',
        url: '/api/admin/skills',
    });
}

export function fetchPlacements(
    curriculumVersionId: string,
): Promise<SkillPlacement[]> {
    return apiRequest<SkillPlacement[]>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/skill-placements`,
    });
}

export interface TopicPayload {
    name: string;
    display_order: number;
}

export function createTopic(
    curriculumVersionId: string,
    payload: TopicPayload,
): Promise<Topic> {
    return apiRequest<Topic>({
        method: 'POST',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/topics`,
        data: payload,
    });
}

export function updateTopic(
    topicId: string,
    payload: TopicPayload,
): Promise<Topic> {
    return apiRequest<Topic>({
        method: 'PUT',
        url:
            `/api/admin/topics/${topicId}`,
        data: payload,
    });
}

export interface SkillPayload {
    name: string;
    description: string | null;
}

export function createSkill(
    payload: SkillPayload,
): Promise<Skill> {
    return apiRequest<Skill>({
        method: 'POST',
        url: '/api/admin/skills',
        data: payload,
    });
}

export function updateSkill(
    skillId: string,
    payload: SkillPayload,
): Promise<Skill> {
    return apiRequest<Skill>({
        method: 'PUT',
        url:
            `/api/admin/skills/${skillId}`,
        data: payload,
    });
}

export function createPlacement(
    curriculumVersionId: string,
    skillId: string,
): Promise<SkillPlacement> {
    return apiRequest<SkillPlacement>({
        method: 'POST',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/skill-placements`,
        data: {
            skill_id: skillId,
        },
    });
}

export function deletePlacement(
    placementId: string,
): Promise<{
    id: string;
    deleted: boolean;
}> {
    return apiRequest({
        method: 'DELETE',
        url:
            `/api/admin/skill-placements/${placementId}`,
    });
}

export function createHomeTopic(
    placementId: string,
    topicId: string,
): Promise<SkillPlacement['home_topics'][number]> {
    return apiRequest({
        method: 'POST',
        url:
            `/api/admin/skill-placements/${placementId}/home-topics`,
        data: {
            topic_id: topicId,
        },
    });
}

export function deleteHomeTopic(
    placementId: string,
    homeTopicId: string,
): Promise<{
    id: string;
    deleted: boolean;
}> {
    return apiRequest({
        method: 'DELETE',
        url:
            `/api/admin/skill-placements/${placementId}/home-topics/${homeTopicId}`,
    });
}

import type {
    Lesson,
} from './types';

export function adminLessonsKey(
    curriculumVersionId: string,
) {
    return [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'lessons',
    ] as const;
}

export function fetchLessons(
    curriculumVersionId: string,
): Promise<Lesson[]> {
    return apiRequest<Lesson[]>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/lessons`,
    });
}

export interface LessonPayload {
    title: string;
    description: string | null;
    display_order: number;
}

export function createLesson(
    curriculumVersionId: string,
    payload: LessonPayload,
): Promise<Lesson> {
    return apiRequest<Lesson>({
        method: 'POST',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/lessons`,
        data: payload,
    });
}

export function updateLesson(
    lessonId: string,
    payload: LessonPayload,
): Promise<Lesson> {
    return apiRequest<Lesson>({
        method: 'PUT',
        url:
            `/api/admin/lessons/${lessonId}`,
        data: payload,
    });
}

import type {
    LessonRevision,
} from './types';

export function adminLessonRevisionsKey(
    lessonId: string,
) {
    return [
        'admin',
        'content',
        'lessons',
        lessonId,
        'revisions',
    ] as const;
}

export function fetchLessonRevisions(
    lessonId: string,
): Promise<LessonRevision[]> {
    return apiRequest<LessonRevision[]>({
        method: 'GET',
        url:
            `/api/admin/lessons/${lessonId}/revisions`,
    });
}

export interface LessonRevisionPayload {
    revision_number: number;
    primary_topic_id: string;
    content_payload:
        | unknown[]
        | Record<string, unknown>;
    content_schema_version: number;
}

export function createLessonRevision(
    lessonId: string,
    payload: LessonRevisionPayload,
): Promise<LessonRevision> {
    return apiRequest<LessonRevision>({
        method: 'POST',
        url:
            `/api/admin/lessons/${lessonId}/revisions`,
        data: payload,
    });
}

export function releaseLessonRevision(
    lessonRevisionId: string,
): Promise<LessonRevision> {
    return apiRequest<LessonRevision>({
        method: 'POST',
        url:
            `/api/lesson-revisions/${lessonRevisionId}/release`,
    });
}

export function publishLesson(
    lessonId: string,
    publishedRevisionId: string,
): Promise<Lesson> {
    return apiRequest<Lesson>({
        method: 'POST',
        url:
            `/api/lessons/${lessonId}/publish`,
        data: {
            published_revision_id:
                publishedRevisionId,
        },
    });
}

export function retireLesson(
    lessonId: string,
): Promise<Lesson> {
    return apiRequest<Lesson>({
        method: 'POST',
        url:
            `/api/lessons/${lessonId}/retire`,
    });
}

import type {
    LessonRevisionSkill,
} from './types';

export function adminRevisionSkillsKey(
    lessonRevisionId: string,
) {
    return [
        'admin',
        'content',
        'lesson-revisions',
        lessonRevisionId,
        'skills',
    ] as const;
}

export function fetchRevisionSkills(
    lessonRevisionId: string,
): Promise<LessonRevisionSkill[]> {
    return apiRequest<LessonRevisionSkill[]>({
        method: 'GET',
        url:
            `/api/admin/lesson-revisions/${lessonRevisionId}/skills`,
    });
}

export function createRevisionSkill(
    lessonRevisionId: string,
    skillVersionPlacementId: string,
): Promise<LessonRevisionSkill> {
    return apiRequest<LessonRevisionSkill>({
        method: 'POST',
        url:
            `/api/admin/lesson-revisions/${lessonRevisionId}/skills`,
        data: {
            skill_version_placement_id:
                skillVersionPlacementId,
        },
    });
}

export function deleteRevisionSkill(
    lessonRevisionId: string,
    lessonRevisionSkillId: string,
): Promise<{
    id: string;
    deleted: boolean;
}> {
    return apiRequest({
        method: 'DELETE',
        url:
            `/api/admin/lesson-revisions/${lessonRevisionId}/skills/${lessonRevisionSkillId}`,
    });
}

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

import type {
    AssessmentItem,
} from './types';

export function adminAssessmentItemsKey(
    curriculumVersionId: string,
) {
    return [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'assessment-items',
    ] as const;
}

export function fetchAssessmentItems(
    curriculumVersionId: string,
): Promise<AssessmentItem[]> {
    return apiRequest<AssessmentItem[]>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/assessment-items`,
    });
}

export interface AssessmentItemPayload {
    item_type: string;
    internal_label: string | null;
}

export function createAssessmentItem(
    curriculumVersionId: string,
    payload: AssessmentItemPayload,
): Promise<AssessmentItem> {
    return apiRequest<AssessmentItem>({
        method: 'POST',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/assessment-items`,
        data: payload,
    });
}

export function updateAssessmentItem(
    assessmentItemId: string,
    payload: AssessmentItemPayload,
): Promise<AssessmentItem> {
    return apiRequest<AssessmentItem>({
        method: 'PUT',
        url:
            `/api/admin/assessment-items/${assessmentItemId}`,
        data: payload,
    });
}

import type {
    AssessmentDifficulty,
    AssessmentItemRevision,
} from './types';

export function adminAssessmentItemRevisionsKey(
    assessmentItemId: string,
) {
    return [
        'admin',
        'content',
        'assessment-items',
        assessmentItemId,
        'revisions',
    ] as const;
}

export function fetchAssessmentItemRevisions(
    assessmentItemId: string,
): Promise<AssessmentItemRevision[]> {
    return apiRequest<AssessmentItemRevision[]>({
        method: 'GET',
        url:
            `/api/admin/assessment-items/${assessmentItemId}/revisions`,
    });
}

export interface AssessmentItemRevisionPayload {
    revision_number: number;
    primary_topic_id: string | null;
    difficulty: AssessmentDifficulty;
    content_payload:
        | unknown[]
        | Record<string, unknown>;
    content_schema_version: number;
    scoring_payload:
        | unknown[]
        | Record<string, unknown>;
    scoring_schema_version: number;
}

export function createAssessmentItemRevision(
    assessmentItemId: string,
    payload: AssessmentItemRevisionPayload,
): Promise<AssessmentItemRevision> {
    return apiRequest<AssessmentItemRevision>({
        method: 'POST',
        url:
            `/api/admin/assessment-items/${assessmentItemId}/revisions`,
        data: payload,
    });
}

import type {
    AssessmentRevisionSkill,
    AssessmentRevisionSkillRole,
} from './types';

export function adminAssessmentRevisionSkillsKey(
    revisionId: string,
) {
    return [
        'admin',
        'content',
        'assessment-item-revisions',
        revisionId,
        'skills',
    ] as const;
}

export function fetchAssessmentRevisionSkills(
    revisionId: string,
): Promise<AssessmentRevisionSkill[]> {
    return apiRequest<AssessmentRevisionSkill[]>({
        method: 'GET',
        url:
            `/api/admin/assessment-item-revisions/${revisionId}/skills`,
    });
}

export function createAssessmentRevisionSkill(
    revisionId: string,
    placementId: string,
    role: AssessmentRevisionSkillRole,
): Promise<AssessmentRevisionSkill> {
    return apiRequest<AssessmentRevisionSkill>({
        method: 'POST',
        url:
            `/api/admin/assessment-item-revisions/${revisionId}/skills`,
        data: {
            skill_version_placement_id:
                placementId,
            role,
        },
    });
}

export function deleteAssessmentRevisionSkill(
    revisionId: string,
    classificationId: string,
): Promise<{
    id: string;
    deleted: boolean;
}> {
    return apiRequest<{
        id: string;
        deleted: boolean;
    }>({
        method: 'DELETE',
        url:
            `/api/admin/assessment-item-revisions/${revisionId}/skills/${classificationId}`,
    });
}

import type {
    PracticeActivity,
} from './types';

export function adminPracticeActivitiesKey(
    curriculumVersionId: string,
) {
    return [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'practice-activities',
    ] as const;
}

export function fetchPracticeActivities(
    curriculumVersionId: string,
): Promise<PracticeActivity[]> {
    return apiRequest<PracticeActivity[]>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/practice-activities`,
    });
}

export interface PracticeActivityPayload {
    lesson_id: string | null;
    name: string;
    description: string | null;
}

export function createPracticeActivity(
    curriculumVersionId: string,
    payload: PracticeActivityPayload,
): Promise<PracticeActivity> {
    return apiRequest<PracticeActivity>({
        method: 'POST',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/practice-activities`,
        data: payload,
    });
}

export function updatePracticeActivity(
    practiceActivityId: string,
    payload: PracticeActivityPayload,
): Promise<PracticeActivity> {
    return apiRequest<PracticeActivity>({
        method: 'PUT',
        url:
            `/api/admin/practice-activities/${practiceActivityId}`,
        data: payload,
    });
}

import type {
    PracticeActivityItem,
} from './types';

export function adminPracticeActivityItemsKey(
    practiceActivityId: string,
) {
    return [
        'admin',
        'content',
        'practice-activities',
        practiceActivityId,
        'items',
    ] as const;
}

export function fetchPracticeActivityItems(
    practiceActivityId: string,
): Promise<PracticeActivityItem[]> {
    return apiRequest<PracticeActivityItem[]>({
        method: 'GET',
        url:
            `/api/admin/practice-activities/${practiceActivityId}/items`,
    });
}

export function createPracticeActivityItem(
    practiceActivityId: string,
    assessmentItemRevisionId: string,
    displayOrder: number,
): Promise<PracticeActivityItem> {
    return apiRequest<PracticeActivityItem>({
        method: 'POST',
        url:
            `/api/admin/practice-activities/${practiceActivityId}/items`,
        data: {
            assessment_item_revision_id:
                assessmentItemRevisionId,
            display_order:
                displayOrder,
        },
    });
}

export function deletePracticeActivityItem(
    practiceActivityId: string,
    practiceActivityItemId: string,
): Promise<{
    id: string;
    deleted: boolean;
}> {
    return apiRequest<{
        id: string;
        deleted: boolean;
    }>({
        method: 'DELETE',
        url:
            `/api/admin/practice-activities/${practiceActivityId}/items/${practiceActivityItemId}`,
    });
}

export function activatePracticeActivity(
    practiceActivityId: string,
): Promise<PracticeActivity> {
    return apiRequest<PracticeActivity>({
        method: 'POST',
        url:
            `/api/admin/practice-activities/${practiceActivityId}/activate`,
    });
}

export function archivePracticeActivity(
    practiceActivityId: string,
): Promise<PracticeActivity> {
    return apiRequest<PracticeActivity>({
        method: 'POST',
        url:
            `/api/admin/practice-activities/${practiceActivityId}/archive`,
    });
}

import type {
    ExamTemplate,
} from './types';

export function adminExamTemplatesKey(
    curriculumVersionId: string,
) {
    return [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'exam-templates',
    ] as const;
}

export function fetchExamTemplates(
    curriculumVersionId: string,
): Promise<ExamTemplate[]> {
    return apiRequest<ExamTemplate[]>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/exam-templates`,
    });
}

export interface ExamTemplatePayload {
    name: string;
    description: string | null;
}

export function createExamTemplate(
    curriculumVersionId: string,
    payload: ExamTemplatePayload,
): Promise<ExamTemplate> {
    return apiRequest<ExamTemplate>({
        method: 'POST',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/exam-templates`,
        data: payload,
    });
}

export function updateExamTemplate(
    examTemplateId: string,
    payload: ExamTemplatePayload,
): Promise<ExamTemplate> {
    return apiRequest<ExamTemplate>({
        method: 'PUT',
        url:
            `/api/admin/exam-templates/${examTemplateId}`,
        data: payload,
    });
}

export function activateExamTemplate(
    examTemplateId: string,
): Promise<ExamTemplate> {
    return apiRequest<ExamTemplate>({
        method: 'POST',
        url:
            `/api/admin/exam-templates/${examTemplateId}/activate`,
    });
}

export function archiveExamTemplate(
    examTemplateId: string,
): Promise<ExamTemplate> {
    return apiRequest<ExamTemplate>({
        method: 'POST',
        url:
            `/api/admin/exam-templates/${examTemplateId}/archive`,
    });
}

import type {
    ExamTemplateVersion,
} from './types';

export function adminExamTemplateVersionsKey(
    examTemplateId: string,
) {
    return [
        'admin',
        'content',
        'exam-templates',
        examTemplateId,
        'versions',
    ] as const;
}

export function fetchExamTemplateVersions(
    examTemplateId: string,
): Promise<ExamTemplateVersion[]> {
    return apiRequest<ExamTemplateVersion[]>({
        method: 'GET',
        url:
            `/api/admin/exam-templates/${examTemplateId}/versions`,
    });
}

export interface CreateExamTemplateVersionPayload {
    version_number: number;
    label: string | null;
    rules_payload:
        | unknown[]
        | Record<string, unknown>;
    rules_schema_version: number;
}

export interface UpdateExamTemplateVersionPayload {
    label: string | null;
    rules_payload:
        | unknown[]
        | Record<string, unknown>;
    rules_schema_version: number;
}

export function createExamTemplateVersion(
    examTemplateId: string,
    payload: CreateExamTemplateVersionPayload,
): Promise<ExamTemplateVersion> {
    return apiRequest<ExamTemplateVersion>({
        method: 'POST',
        url:
            `/api/admin/exam-templates/${examTemplateId}/versions`,
        data: payload,
    });
}

export function updateExamTemplateVersion(
    examTemplateVersionId: string,
    payload: UpdateExamTemplateVersionPayload,
): Promise<ExamTemplateVersion> {
    return apiRequest<ExamTemplateVersion>({
        method: 'PUT',
        url:
            `/api/admin/exam-template-versions/${examTemplateVersionId}`,
        data: payload,
    });
}

export interface PublishExamTemplateVersionResult {
    version: ExamTemplateVersion;
    template: ExamTemplate;
}

export function publishExamTemplateVersion(
    examTemplateVersionId: string,
): Promise<PublishExamTemplateVersionResult> {
    return apiRequest<PublishExamTemplateVersionResult>({
        method: 'POST',
        url:
            `/api/admin/exam-template-versions/${examTemplateVersionId}/publish`,
    });
}

export function retireExamTemplateVersion(
    examTemplateVersionId: string,
): Promise<ExamTemplateVersion> {
    return apiRequest<ExamTemplateVersion>({
        method: 'POST',
        url:
            `/api/admin/exam-template-versions/${examTemplateVersionId}/retire`,
    });
}

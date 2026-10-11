import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    apiRequest,
} from '../api/client';
import {
    addTeacherPracticeItems,
    createTeacherExamGeneration,
    createTeacherExamTemplate,
    createTeacherExamTemplateVersion,
    createTeacherPracticeActivity,
    deleteTeacherPracticeItem,
    fetchTeacherExamTemplates,
    fetchTeacherExamTemplateVersion,
    fetchTeacherExamTemplateVersions,
    fetchTeacherPracticeActivities,
    fetchTeacherPracticeItems,
    publishTeacherExamTemplateVersion,
    retireTeacherExamTemplateVersion,
    setTeacherExamTemplateStatus,
    setTeacherPracticeActivityStatus,
    teacherExamTemplatesKey,
    teacherExamTemplateVersionKey,
    teacherExamTemplateVersionsKey,
    teacherPracticeActivitiesKey,
    teacherPracticeItemsKey,
    updateTeacherExamTemplate,
    updateTeacherExamTemplateVersion,
    updateTeacherPracticeActivity,
} from './api';

vi.mock('../api/client', () => ({
    apiRequest: vi.fn(),
}));

const request = vi.mocked(apiRequest);
const prefix = '/api/teacher/subject-assignments/assignment-a'
    + '/curricula/curriculum-a/versions/version-a';

describe('teacher Practice and Exam API contracts', () => {
    beforeEach(() => {
        request.mockReset();
        request.mockResolvedValue([]);
    });

    it('scopes Practice and Exam query keys to principal and exact parents', () => {
        expect(teacherPracticeActivitiesKey(
            'teacher-a',
            'assignment-a',
            'curriculum-a',
            'version-a',
        )).toContain('practice-activities');
        expect(teacherPracticeItemsKey(
            'teacher-a',
            'assignment-a',
            'curriculum-a',
            'version-a',
            'practice-a',
        )).toContain('practice-a');
        expect(teacherExamTemplatesKey(
            'teacher-a',
            'assignment-a',
            'curriculum-a',
            'version-a',
        )).toContain('exam-templates');
        expect(teacherExamTemplateVersionsKey(
            'teacher-a',
            'assignment-a',
            'curriculum-a',
            'version-a',
            'template-a',
        )).toContain('template-a');
        expect(teacherExamTemplateVersionKey(
            'teacher-a',
            'assignment-a',
            'curriculum-a',
            'version-a',
            'template-version-a',
        )).toContain('template-version-a');
    });

    it('uses exact Practice endpoints and propagates abort signals', async () => {
        const controller = new AbortController();
        await fetchTeacherPracticeActivities(
            'assignment-a',
            'curriculum-a',
            'version-a',
            controller.signal,
        );
        await createTeacherPracticeActivity(
            'assignment-a',
            'curriculum-a',
            'version-a',
            {
                description: null,
                lesson_id: null,
                name: 'Practice',
            },
        );
        await updateTeacherPracticeActivity(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'practice-a',
            {
                description: 'Updated',
                lesson_id: 'lesson-a',
                name: 'Practice',
            },
        );
        await setTeacherPracticeActivityStatus(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'practice-a',
            'active',
        );
        await fetchTeacherPracticeItems(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'practice-a',
            controller.signal,
        );
        await addTeacherPracticeItems(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'practice-a',
            {
                assessment_item_revision_ids: ['revision-b', 'revision-a'],
                display_order: 3,
            },
        );
        await deleteTeacherPracticeItem(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'practice-a',
            'membership-a',
        );

        expect(request).toHaveBeenNthCalledWith(1, {
            method: 'GET',
            signal: controller.signal,
            url: prefix + '/practice-activities',
        });
        expect(request).toHaveBeenCalledWith(expect.objectContaining({
            data: {
                assessment_item_revision_ids: ['revision-b', 'revision-a'],
                display_order: 3,
            },
            method: 'POST',
            url: prefix + '/practice-activities/practice-a/items',
        }));
        expect(request).toHaveBeenLastCalledWith({
            method: 'DELETE',
            signal: undefined,
            url: prefix + '/practice-activities/practice-a/items/membership-a',
        });
    });

    it('uses exact template, version and lifecycle endpoints', async () => {
        const controller = new AbortController();
        await fetchTeacherExamTemplates('assignment-a', 'curriculum-a', 'version-a', controller.signal);
        await createTeacherExamTemplate('assignment-a', 'curriculum-a', 'version-a', {
            description: null,
            name: 'Exam',
        });
        await updateTeacherExamTemplate('assignment-a', 'curriculum-a', 'version-a', 'template-a', {
            description: 'Updated',
            name: 'Exam',
        });
        await setTeacherExamTemplateStatus('assignment-a', 'curriculum-a', 'version-a', 'template-a', 'archived');
        await fetchTeacherExamTemplateVersions('assignment-a', 'curriculum-a', 'version-a', 'template-a', controller.signal);
        await fetchTeacherExamTemplateVersion('assignment-a', 'curriculum-a', 'version-a', 'template-a', 'template-version-a', controller.signal);
        await createTeacherExamTemplateVersion('assignment-a', 'curriculum-a', 'version-a', 'template-a', {
            label: 'v1',
            rules_payload: [],
            rules_schema_version: 1,
            version_number: 1,
        });
        await updateTeacherExamTemplateVersion('assignment-a', 'curriculum-a', 'version-a', 'template-a', 'template-version-a', {
            label: 'updated',
            rules_payload: {},
            rules_schema_version: 2,
        });
        await publishTeacherExamTemplateVersion('assignment-a', 'curriculum-a', 'version-a', 'template-a', 'template-version-a');
        await retireTeacherExamTemplateVersion('assignment-a', 'curriculum-a', 'version-a', 'template-a', 'template-version-a');

        expect(request).toHaveBeenCalledWith({
            method: 'GET',
            signal: controller.signal,
            url: prefix + '/exam-templates/template-a/versions/template-version-a',
        });
        expect(request).toHaveBeenCalledWith({
            method: 'POST',
            signal: undefined,
            url: prefix + '/exam-templates/template-a/versions/template-version-a/publish',
        });
        expect(request).toHaveBeenLastCalledWith({
            method: 'POST',
            signal: undefined,
            url: prefix + '/exam-templates/template-a/versions/template-version-a/retire',
        });
    });

    it('creates a generation once and preserves unique caller order in the payload', async () => {
        request.mockResolvedValue({
            curriculum_version_id: 'version-a',
            exam_template_version_id: 'template-version-a',
            generated_at: '2026-10-11T00:00:00Z',
            id: 'generation-a',
            items: [],
        });

        await createTeacherExamGeneration(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'template-a',
            'template-version-a',
            {
                assessment_item_revision_ids: ['revision-b', 'revision-a'],
                generator_version: 'h6',
                seed: 'seed-a',
            },
        );

        expect(request).toHaveBeenCalledTimes(1);
        expect(request).toHaveBeenCalledWith({
            data: {
                assessment_item_revision_ids: ['revision-b', 'revision-a'],
                generator_version: 'h6',
                seed: 'seed-a',
            },
            method: 'POST',
            signal: undefined,
            url: prefix + '/exam-templates/template-a/versions/template-version-a/generations',
        });
    });
});

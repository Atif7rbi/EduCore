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
    fetchTeacherAssignments,
    fetchTeacherCurricula,
    fetchTeacherCurriculumVersions,
    fetchTeacherTopics,
    fetchTeacherSkills,
    fetchTeacherSkillPlacements,
    createTeacherTopic,
    updateTeacherTopic,
    createTeacherSkillPlacement,
    deleteTeacherSkillPlacement,
    createTeacherHomeTopic,
    deleteTeacherHomeTopic,
    teacherApiRequest,
    teacherAssignmentsKey,
    teacherCurriculaKey,
    teacherCurriculumVersionsKey,
    teacherQueryKey,
} from './api';

vi.mock('../api/client', () => ({
    apiRequest: vi.fn(),
}));

const apiRequestMock =
    vi.mocked(apiRequest);

describe('teacher API foundation', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
        apiRequestMock.mockResolvedValue([]);
    });

    it('scopes query keys to the authenticated teacher and exact provenance', () => {
        const first = teacherQueryKey({
            authenticatedUserId: 'teacher-a',
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
            resourceType: 'lesson',
            resourceId: 'lesson-a',
        });
        const second = teacherQueryKey({
            authenticatedUserId: 'teacher-b',
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
            resourceType: 'lesson',
            resourceId: 'lesson-a',
        });

        expect(first).toEqual([
            'teacher',
            'teacher-a',
            'assignment',
            'assignment-a',
            'curriculum',
            'curriculum-a',
            'curriculum-version',
            'version-a',
            'resource',
            'lesson',
            'lesson-a',
        ]);
        expect(second).not.toEqual(first);
        expect(
            teacherAssignmentsKey('teacher-a'),
        ).toEqual([
            'teacher',
            'teacher-a',
            'assignment',
            null,
            'curriculum',
            null,
            'curriculum-version',
            null,
            'resource',
            'assignments',
            null,
        ]);
        expect(
            teacherCurriculaKey(
                'teacher-a',
                'assignment-a',
            ),
        ).toContain('curricula');
        expect(
            teacherCurriculumVersionsKey(
                'teacher-a',
                'assignment-a',
                'curriculum-a',
            ),
        ).toContain('curriculum-versions');
    });

    it('preserves a signal supplied by the Axios configuration', async () => {
        const controller =
            new AbortController();

        await teacherApiRequest<unknown[]>({
            method: 'GET',
            url: '/api/teacher/subject-assignments',
            signal: controller.signal,
        });

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/teacher/subject-assignments',
            signal: controller.signal,
        });
    });

    it('passes an explicit signal to the shared request client', async () => {
        const controller =
            new AbortController();

        await teacherApiRequest<unknown[]>(
            {
                method: 'GET',
                url: '/api/teacher/subject-assignments',
            },
            controller.signal,
        );

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/teacher/subject-assignments',
            signal: controller.signal,
        });
    });

    it('gives the explicit signal precedence over the Axios configuration', async () => {
        const configured =
            new AbortController();
        const explicit =
            new AbortController();

        await teacherApiRequest<unknown[]>(
            {
                method: 'GET',
                url: '/api/teacher/subject-assignments',
                signal: configured.signal,
            },
            explicit.signal,
        );

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/teacher/subject-assignments',
            signal: explicit.signal,
        });
    });

    it('leaves the shared request signal unset when neither source provides one', async () => {
        await teacherApiRequest<unknown[]>({
            method: 'GET',
            url: '/api/teacher/subject-assignments',
        });

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/teacher/subject-assignments',
            signal: undefined,
        });
    });

    it('uses only the three authorized teacher context read endpoints', async () => {
        const controller =
            new AbortController();

        await fetchTeacherAssignments(
            controller.signal,
        );
        await fetchTeacherCurricula(
            'assignment-a',
            controller.signal,
        );
        await fetchTeacherCurriculumVersions(
            'assignment-a',
            'curriculum-a',
            controller.signal,
        );

        expect(apiRequestMock).toHaveBeenNthCalledWith(
            1,
            {
                method: 'GET',
                url: '/api/teacher/subject-assignments',
                signal: controller.signal,
            },
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            2,
            {
                method: 'GET',
                url:
                    '/api/teacher/subject-assignments/'
                    + 'assignment-a'
                    + '/curricula',
                signal: controller.signal,
            },
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            3,
            {
                method: 'GET',
                url:
                    '/api/teacher/subject-assignments/'
                    + 'assignment-a'
                    + '/curricula/'
                    + 'curriculum-a'
                    + '/versions',
                signal: controller.signal,
            },
        );
    });

    it('uses only scoped teacher topic and skill-placement endpoints', async () => {
        await fetchTeacherTopics(
            'assignment-a',
            'curriculum-a',
            'version-a',
        );
        await fetchTeacherSkills();
        await fetchTeacherSkillPlacements(
            'assignment-a',
            'curriculum-a',
            'version-a',
        );
        await createTeacherTopic(
            'assignment-a',
            'curriculum-a',
            'version-a',
            {
                display_order: 2,
                name: 'النسب',
            },
        );
        await updateTeacherTopic(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'topic-a',
            {
                display_order: 3,
                name: 'الكسور',
            },
        );
        await createTeacherSkillPlacement(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'skill-a',
        );
        await deleteTeacherSkillPlacement(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'placement-a',
        );
        await createTeacherHomeTopic(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'placement-a',
            'topic-a',
        );
        await deleteTeacherHomeTopic(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'placement-a',
            'home-a',
        );

        const base =
            '/api/teacher/subject-assignments/'
            + 'assignment-a/curricula/curriculum-a/'
            + 'versions/version-a';

        expect(apiRequestMock).toHaveBeenNthCalledWith(
            1,
            expect.objectContaining({
                method: 'GET',
                url: base + '/topics',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            2,
            expect.objectContaining({
                method: 'GET',
                url: '/api/teacher/skills',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            3,
            expect.objectContaining({
                method: 'GET',
                url: base + '/skill-placements',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            4,
            expect.objectContaining({
                data: {
                    display_order: 2,
                    name: 'النسب',
                },
                method: 'POST',
                url: base + '/topics',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            5,
            expect.objectContaining({
                data: {
                    display_order: 3,
                    name: 'الكسور',
                },
                method: 'PUT',
                url: base + '/topics/topic-a',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            6,
            expect.objectContaining({
                data: {
                    skill_id: 'skill-a',
                },
                method: 'POST',
                url: base + '/skill-placements',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            7,
            expect.objectContaining({
                method: 'DELETE',
                url: base + '/skill-placements/placement-a',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            8,
            expect.objectContaining({
                data: {
                    topic_id: 'topic-a',
                },
                method: 'POST',
                url:
                    base
                    + '/skill-placements/placement-a/home-topics',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            9,
            expect.objectContaining({
                method: 'DELETE',
                url:
                    base
                    + '/skill-placements/placement-a/'
                    + 'home-topics/home-a',
            }),
        );
    });

});

describe('lesson and assessment authoring API', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
        apiRequestMock.mockResolvedValue([]);
    });

    it('uses exact-context lesson and assessment endpoints with payloads', async () => {
        const api = await import('./api');

        await api.createTeacherLesson(
            'assignment-a',
            'curriculum-a',
            'version-a',
            {
                description: null,
                display_order: 0,
                title: 'درس',
            },
        );
        await api.createTeacherLessonRevision(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'lesson-a',
            {
                content_payload: {},
                content_schema_version: 1,
                primary_topic_id: 'topic-a',
                revision_number: 1,
            },
        );
        await api.createTeacherAssessmentItem(
            'assignment-a',
            'curriculum-a',
            'version-a',
            {
                internal_label: 'سؤال',
                item_type: 'multiple_choice',
            },
        );
        await api.createTeacherAssessmentRevision(
            'assignment-a',
            'curriculum-a',
            'version-a',
            'item-a',
            {
                content_payload: {},
                content_schema_version: 1,
                difficulty: 'medium',
                primary_topic_id: null,
                revision_number: 1,
                scoring_payload: {},
                scoring_schema_version: 1,
            },
        );

        expect(apiRequestMock).toHaveBeenNthCalledWith(
            1,
            expect.objectContaining({
                data: {
                    description: null,
                    display_order: 0,
                    title: 'درس',
                },
                method: 'POST',
                url: '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            2,
            expect.objectContaining({
                data: expect.objectContaining({
                    primary_topic_id: 'topic-a',
                }),
                method: 'POST',
                url: '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons/lesson-a/revisions',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            3,
            expect.objectContaining({
                data: {
                    internal_label: 'سؤال',
                    item_type: 'multiple_choice',
                },
                method: 'POST',
                url: '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items',
            }),
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            4,
            expect.objectContaining({
                data: expect.objectContaining({
                    scoring_schema_version: 1,
                }),
                method: 'POST',
                url: '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items/item-a/revisions',
            }),
        );
    });

    it('keeps lifecycle and revision-skill paths scoped to the exact child', async () => {
        const api = await import('./api');

        await api.createTeacherLessonRevisionSkill('assignment-a', 'curriculum-a', 'version-a', 'lesson-a', 'revision-a', 'placement-a');
        await api.deleteTeacherLessonRevisionSkill('assignment-a', 'curriculum-a', 'version-a', 'lesson-a', 'revision-a', 'association-a');
        await api.releaseTeacherLessonRevision('assignment-a', 'curriculum-a', 'version-a', 'lesson-a', 'revision-a');
        await api.publishTeacherLesson('assignment-a', 'curriculum-a', 'version-a', 'lesson-a', 'revision-a');
        await api.unpublishTeacherLesson('assignment-a', 'curriculum-a', 'version-a', 'lesson-a');
        await api.createTeacherAssessmentRevisionSkill('assignment-a', 'curriculum-a', 'version-a', 'item-a', 'revision-b', 'placement-a', 'primary');
        await api.deleteTeacherAssessmentRevisionSkill('assignment-a', 'curriculum-a', 'version-a', 'item-a', 'revision-b', 'association-b');
        await api.releaseTeacherAssessmentRevision('assignment-a', 'curriculum-a', 'version-a', 'item-a', 'revision-b');
        await api.publishTeacherAssessmentItem('assignment-a', 'curriculum-a', 'version-a', 'item-a', 'revision-b');
        await api.retireTeacherAssessmentItem('assignment-a', 'curriculum-a', 'version-a', 'item-a');

        expect(apiRequestMock.mock.calls.map(([config]) => config.url)).toEqual([
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons/lesson-a/revisions/revision-a/skills',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons/lesson-a/revisions/revision-a/skills/association-a',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons/lesson-a/revisions/revision-a/release',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons/lesson-a/publish',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons/lesson-a/unpublish',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items/item-a/revisions/revision-b/skills',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items/item-a/revisions/revision-b/skills/association-b',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items/item-a/revisions/revision-b/release',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items/item-a/publish',
            '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items/item-a/retire',
        ]);
        expect(apiRequestMock.mock.calls[5][0].data).toEqual({
            role: 'primary',
            skill_version_placement_id: 'placement-a',
        });
    });
});

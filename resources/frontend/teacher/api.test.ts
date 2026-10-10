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

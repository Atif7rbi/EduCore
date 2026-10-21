import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    decideTeacherEnrollment,
    fetchTeacherEnrollments,
    teacherEnrollmentsKey,
    teacherEnrollmentsRootKey,
} from './enrollmentApi';

const apiRequestMock = vi.hoisted(() => vi.fn());

vi.mock('../api/client', () => ({
    apiRequest: apiRequestMock,
}));

describe('teacher enrollment API client', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
        apiRequestMock.mockResolvedValue({
            items: [],
            pagination: {
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 0,
            },
        });
    });

    it('requests a paginated teacher-scoped enrollment status', async () => {
        const abortController =
            new AbortController();

        await fetchTeacherEnrollments(
            'pending',
            2,
            abortController.signal,
            'assignment-one',
        );

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            params: {
                assignment_id: 'assignment-one',
                page: 2,
                per_page: 20,
                status: 'pending',
            },
            signal: abortController.signal,
            url: '/api/teacher/enrollments',
        });
    });

    it('uses the canonical accept and decline endpoints', async () => {
        await decideTeacherEnrollment(
            'enrollment-one',
            'accept',
            'operation-one',
            'Accepted by teacher.',
        );
        await decideTeacherEnrollment(
            'enrollment-two',
            'decline',
            'operation-two',
            'Declined by teacher.',
        );

        expect(apiRequestMock).toHaveBeenNthCalledWith(
            1,
            {
                data: {
                    operation_id: 'operation-one',
                    reason: 'Accepted by teacher.',
                },
                method: 'POST',
                url: '/api/teacher/enrollments/enrollment-one/accept',
            },
        );
        expect(apiRequestMock).toHaveBeenNthCalledWith(
            2,
            {
                data: {
                    operation_id: 'operation-two',
                    reason: 'Declined by teacher.',
                },
                method: 'POST',
                url: '/api/teacher/enrollments/enrollment-two/decline',
            },
        );
    });

    it('scopes query keys to the authenticated teacher', () => {
        expect(
            teacherEnrollmentsKey(
                'teacher-one',
                'active',
                3,
            ),
        ).toEqual([
            'teacher',
            'teacher-one',
            'enrollments',
            'active',
            'all-assignments',
            3,
        ]);
        expect(
            teacherEnrollmentsKey(
                'teacher-one',
                'pending',
                2,
                'assignment-one',
            ),
        ).toEqual([
            'teacher',
            'teacher-one',
            'enrollments',
            'pending',
            'assignment-one',
            2,
        ]);
        expect(
            teacherEnrollmentsRootKey(
                'teacher-two',
            ),
        ).toEqual([
            'teacher',
            'teacher-two',
            'enrollments',
        ]);
    });
});

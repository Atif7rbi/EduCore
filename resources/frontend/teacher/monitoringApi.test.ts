import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    fetchTeacherAttemptResult,
    fetchTeacherAttempts,
    fetchTeacherLessonProgress,
    resolveTeacherMonitoringEnrollment,
    teacherAttemptResultKey,
    teacherAttemptsKey,
    teacherLessonProgressKey,
    teacherMonitoringEnrollmentContextKey,
} from './monitoringApi';

const requestMock = vi.hoisted(() => vi.fn());

vi.mock('../api/client', () => ({
    apiRequest: requestMock,
}));

describe('teacher monitoring API', () => {
    beforeEach(() => {
        requestMock.mockReset();
        requestMock.mockResolvedValue({});
    });

    it('uses scoped progress and attempt endpoints with AbortSignal support', async () => {
        const controller = new AbortController();

        await fetchTeacherLessonProgress('enrollment-1', 'version-1', controller.signal);
        await fetchTeacherAttempts('enrollment-1', 'version-1', 'practice', 'submitted', 2, controller.signal);
        await fetchTeacherAttemptResult('enrollment-1', 'attempt-1', controller.signal);

        expect(requestMock).toHaveBeenNthCalledWith(1, {
            method: 'GET',
            url: '/api/teacher/monitoring/enrollments/enrollment-1/progress',
            params: {
                curriculum_version_id: 'version-1',
            },
            signal: controller.signal,
        });
        expect(requestMock).toHaveBeenNthCalledWith(2, {
            method: 'GET',
            url: '/api/teacher/monitoring/enrollments/enrollment-1/attempts',
            params: {
                curriculum_version_id: 'version-1',
                kind: 'practice',
                status: 'submitted',
                page: 2,
                per_page: 20,
            },
            signal: controller.signal,
        });
        expect(requestMock).toHaveBeenNthCalledWith(3, {
            method: 'GET',
            url: '/api/teacher/monitoring/enrollments/enrollment-1/attempts/attempt-1/result',
            signal: controller.signal,
        });
    });

    it('keys every read by Teacher, enrollment, assignment and version', () => {
        expect(teacherMonitoringEnrollmentContextKey('teacher-1', 'enrollment-1')).toEqual([
            'teacher', 'teacher-1', 'monitoring', 'enrollment-context', 'enrollment-1',
        ]);
        expect(teacherLessonProgressKey('teacher-1', 'enrollment-1', 'assignment-1', 'version-1')).toEqual([
            'teacher', 'teacher-1', 'monitoring', 'enrollment', 'enrollment-1',
            'assignment', 'assignment-1', 'curriculum-version', 'version-1', 'progress',
        ]);
        expect(teacherAttemptsKey('teacher-1', 'enrollment-1', 'assignment-1', 'version-1', null, null, 1)).toContain('all-kinds');
        expect(teacherAttemptResultKey('teacher-1', 'enrollment-1', 'assignment-1', 'version-1', 'attempt-1')).toEqual([
            'teacher', 'teacher-1', 'monitoring', 'enrollment', 'enrollment-1',
            'assignment', 'assignment-1', 'curriculum-version', 'version-1',
            'attempt-result', 'attempt-1',
        ]);
    });

    it('resolves direct-link enrollment context only from Teacher-scoped active enrollment pages', async () => {
        const signal = new AbortController().signal;
        const expectedEnrollment = {
            id: 'enrollment-target',
            student: {
                user_id: 'student-1',
                name: 'الطالب',
            },
            teacher_subject_assignment: {
                id: 'assignment-1',
                status: 'active',
                subject: {
                    id: 'subject-1',
                    code: 'mathematics',
                    name: 'الرياضيات',
                },
            },
            status: 'active',
            created_at: null,
            accepted_at: null,
            updated_at: null,
        } as const;
        requestMock
            .mockResolvedValueOnce({
                items: [],
                pagination: {
                    current_page: 1,
                    per_page: 50,
                    total: 51,
                    last_page: 2,
                },
            })
            .mockResolvedValueOnce({
                items: [expectedEnrollment],
                pagination: {
                    current_page: 2,
                    per_page: 50,
                    total: 51,
                    last_page: 2,
                },
            });

        await expect(resolveTeacherMonitoringEnrollment(
            'enrollment-target',
            signal,
        )).resolves.toEqual(expectedEnrollment);

        expect(requestMock).toHaveBeenNthCalledWith(1, {
            method: 'GET',
            url: '/api/teacher/enrollments',
            params: {
                status: 'active',
                page: 1,
                per_page: 50,
            },
            signal,
        });
        expect(requestMock).toHaveBeenNthCalledWith(2, {
            method: 'GET',
            url: '/api/teacher/enrollments',
            params: {
                status: 'active',
                page: 2,
                per_page: 50,
            },
            signal,
        });
    });

    it('returns no authority when the active Teacher-scoped pages do not contain the enrollment', async () => {
        requestMock.mockResolvedValueOnce({
            items: [],
            pagination: {
                current_page: 1,
                per_page: 50,
                total: 0,
                last_page: 1,
            },
        });

        await expect(resolveTeacherMonitoringEnrollment('foreign-enrollment')).resolves.toBeNull();
        expect(requestMock).toHaveBeenCalledTimes(1);
    });
});

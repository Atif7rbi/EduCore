import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    apiRequest,
} from '../../api/client';

import {
    adminCanonicalSubjectsKey,
    adminTeacherKey,
    adminTeachersKey,
    assignTeacherSubject,
    deactivateTeacherAssignment,
    fetchAdminCanonicalSubjects,
    fetchAdminTeacher,
    fetchAdminTeachers,
    fetchTeacherAssignments,
    provisionTeacher,
    reactivateTeacherAssignment,
    teacherAssignmentsKey,
} from './api';

vi.mock('../../api/client', () => ({
    apiRequest: vi.fn(),
}));

const apiRequestMock =
    vi.mocked(apiRequest);

describe('admin operations api', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
        apiRequestMock.mockResolvedValue(
            undefined as never
        );
    });

    it('reads the canonical subject catalog through the operations contract', async () => {
        expect(
            adminCanonicalSubjectsKey()
        ).toEqual([
            'admin',
            'operations',
            'subjects',
        ]);

        await fetchAdminCanonicalSubjects();

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'GET',
            url: '/api/admin/subjects',
        });
    });

    it('defines stable teacher query keys', () => {
        expect(
            adminTeachersKey()
        ).toEqual([
            'admin',
            'operations',
            'teachers',
        ]);

        expect(
            adminTeacherKey(
                'teacher-1'
            )
        ).toEqual([
            'admin',
            'operations',
            'teachers',
            'teacher-1',
        ]);

        expect(
            teacherAssignmentsKey(
                'teacher-1'
            )
        ).toEqual([
            'admin',
            'operations',
            'teachers',
            'teacher-1',
            'subject-assignments',
        ]);
    });

    it('reads teacher collection and detail', async () => {
        await fetchAdminTeachers();

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'GET',
            url: '/api/admin/teachers',
        });

        await fetchAdminTeacher(
            'teacher-1'
        );

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'GET',
            url:
                '/api/admin/teachers/teacher-1',
        });
    });

    it('provisions teacher without client authority fields', async () => {
        await provisionTeacher({
            name: 'Teacher One',
            email:
                'teacher.one@example.test',
        });

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'POST',
            url: '/api/admin/teachers',
            data: {
                name: 'Teacher One',
                email:
                    'teacher.one@example.test',
            },
        });
    });

    it('reads teacher subject assignments', async () => {
        await fetchTeacherAssignments(
            'teacher-1'
        );

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'GET',
            url:
                '/api/admin/teachers/teacher-1/subject-assignments',
        });
    });

    it('assigns subject with explicit operation provenance', async () => {
        await assignTeacherSubject(
            'teacher-1',
            {
                subject_id:
                    'subject-1',
                operation_id:
                    'operation-1',
                reason:
                    'Administrative assignment.',
            },
        );

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'POST',
            url:
                '/api/admin/teachers/teacher-1/subject-assignments',
            data: {
                subject_id:
                    'subject-1',
                operation_id:
                    'operation-1',
                reason:
                    'Administrative assignment.',
            },
        });
    });

    it('uses explicit lifecycle operations for deactivate and reactivate', async () => {
        const payload = {
            operation_id:
                'operation-1',
            reason:
                'Administrative lifecycle operation.',
        };

        await deactivateTeacherAssignment(
            'assignment-1',
            payload,
        );

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'POST',
            url:
                '/api/admin/teacher-subject-assignments/assignment-1/deactivate',
            data: payload,
        });

        await reactivateTeacherAssignment(
            'assignment-1',
            payload,
        );

        expect(
            apiRequestMock
        ).toHaveBeenLastCalledWith({
            method: 'POST',
            url:
                '/api/admin/teacher-subject-assignments/assignment-1/reactivate',
            data: payload,
        });
    });
});

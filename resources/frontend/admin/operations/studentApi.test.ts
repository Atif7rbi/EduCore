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
    adminStudentKey,
    adminStudentDirectoryKey,
    adminStudentsKey,
    deactivateStudentEnrollment,
    fetchAdminStudent,
    fetchAdminStudentDirectory,
    fetchAdminStudents,
    fetchStudentEnrollment,
    fetchStudentEnrollments,
    studentEnrollmentKey,
    studentEnrollmentsKey,
} from './studentApi';

vi.mock('../../api/client', () => ({
    apiRequest: vi.fn(),
}));

const apiRequestMock =
    vi.mocked(apiRequest);

describe(
    'admin student operations api',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();

            apiRequestMock
                .mockResolvedValue(
                    undefined as never
                );
        });

        it('keeps User UUID as canonical Student resource identity', () => {
            expect(
                adminStudentsKey()
            ).toEqual([
                'admin',
                'operations',
                'students',
            ]);

            expect(
                adminStudentKey(
                    'student-user-1'
                )
            ).toEqual([
                'admin',
                'operations',
                'students',
                'student-user-1',
            ]);

            expect(
                studentEnrollmentsKey(
                    'student-user-1'
                )
            ).toEqual([
                'admin',
                'operations',
                'students',
                'student-user-1',
                'enrollments',
            ]);
        });

        it('reads Student collection and detail by User UUID', async () => {
            await fetchAdminStudents();

            expect(
                apiRequestMock
            ).toHaveBeenLastCalledWith({
                method: 'GET',
                url:
                    '/api/admin/students',
            });

            await fetchAdminStudent(
                'student-user-1'
            );

            expect(
                apiRequestMock
            ).toHaveBeenLastCalledWith({
                method: 'GET',
                url:
                    '/api/admin/students/student-user-1',
            });
        });

        it('scopes Student Directory reads to every parameter and AbortSignal', async () => {
            const parameters = {
                page: 2,
                perPage: 20,
                search: 'profile-uuid',
                status: 'active' as const,
            };
            const controller = new AbortController();

            expect(
                adminStudentDirectoryKey(parameters),
            ).toEqual([
                'admin',
                'operations',
                'students',
                'directory',
                2,
                20,
                'profile-uuid',
                'active',
            ]);

            await fetchAdminStudentDirectory(
                parameters,
                controller.signal,
            );

            expect(apiRequestMock).toHaveBeenLastCalledWith({
                method: 'GET',
                url: '/api/admin/directories/students',
                params: {
                    page: 2,
                    per_page: 20,
                    search: 'profile-uuid',
                    status: 'active',
                },
                signal: controller.signal,
            });
        });

        it('reads enrollments through Student User UUID rather than LearnerProfile UUID', async () => {
            await fetchStudentEnrollments(
                'student-user-1'
            );

            expect(
                apiRequestMock
            ).toHaveBeenLastCalledWith({
                method: 'GET',
                url:
                    '/api/admin/students/student-user-1/enrollments',
            });
        });

        it('defines enrollment detail identity separately from Student identity', async () => {
            expect(
                studentEnrollmentKey(
                    'enrollment-1'
                )
            ).toEqual([
                'admin',
                'operations',
                'student-enrollments',
                'enrollment-1',
            ]);

            await fetchStudentEnrollment(
                'enrollment-1'
            );

            expect(
                apiRequestMock
            ).toHaveBeenLastCalledWith({
                method: 'GET',
                url:
                    '/api/admin/student-enrollments/enrollment-1',
            });
        });

        it('exposes only the already-authorized Admin deactivation lifecycle mutation', async () => {
            const payload = {
                operation_id:
                    'operation-1',
                reason:
                    'Administrative deactivation.',
            };

            await deactivateStudentEnrollment(
                'enrollment-1',
                payload,
            );

            expect(
                apiRequestMock
            ).toHaveBeenLastCalledWith({
                method: 'POST',
                url:
                    '/api/admin/student-enrollments/enrollment-1/deactivate',
                data: payload,
            });
        });
    },
);

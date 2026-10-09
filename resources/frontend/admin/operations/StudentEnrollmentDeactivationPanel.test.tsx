import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    StudentEnrollmentDeactivationPanel,
} from './StudentEnrollmentDeactivationPanel';

const deactivateStudentEnrollmentMock =
    vi.fn();

vi.mock('./studentApi', () => ({
    adminStudentsKey: () => [
        'admin',
        'operations',
        'students',
    ],

    adminStudentKey: (
        id: string,
    ) => [
        'admin',
        'operations',
        'students',
        id,
    ],

    studentEnrollmentsKey: (
        id: string,
    ) => [
        'admin',
        'operations',
        'students',
        id,
        'enrollments',
    ],

    studentEnrollmentKey: (
        id: string,
    ) => [
        'admin',
        'operations',
        'student-enrollments',
        id,
    ],

    deactivateStudentEnrollment: (
        id: string,
        payload: unknown,
    ) =>
        deactivateStudentEnrollmentMock(
            id,
            payload,
        ),
}));

const operationUuid =
    '22222222-2222-4222-8222-222222222222';

function enrollment(
    status:
        | 'pending'
        | 'active'
        | 'inactive',
) {
    return {
        id: 'enrollment-1',

        student: {
            user_id:
                'student-user-1',
            learner_profile_id:
                'profile-1',
            name: 'طالب أول',
            email:
                'student@example.test',
            status:
                'active' as const,
        },

        status,

        teacher_subject_assignment: {
            id: 'assignment-1',
            status:
                'active' as const,

            teacher: {
                user_id:
                    'teacher-1',
                name: 'معلم',
                email:
                    'teacher@example.test',
                status:
                    'active' as const,
            },

            subject: {
                id:
                    'subject-math',
                code:
                    'mathematics',
                name:
                    'الرياضيات',
                status:
                    'active' as const,
            },
        },

        created_at: null,
        updated_at: null,
        transitions: [],
    };
}

function renderPanel(
    status:
        | 'pending'
        | 'active'
        | 'inactive',
) {
    const client =
        new QueryClient({
            defaultOptions: {
                queries: {
                    retry: false,
                },
                mutations: {
                    retry: false,
                },
            },
        });

    render(
        <QueryClientProvider
            client={client}
        >
            <StudentEnrollmentDeactivationPanel
                enrollment={
                    enrollment(status)
                }
            />
        </QueryClientProvider>,
    );

    return client;
}

describe(
    'StudentEnrollmentDeactivationPanel',
    () => {
        beforeEach(() => {
            vi.stubGlobal(
                'crypto',
                {
                    randomUUID: () =>
                        operationUuid,
                },
            );

            deactivateStudentEnrollmentMock
                .mockReset();

            deactivateStudentEnrollmentMock
                .mockResolvedValue({
                    id:
                        'enrollment-1',
                    learner_profile_id:
                        'profile-1',
                    teacher_subject_assignment_id:
                        'assignment-1',
                    status:
                        'inactive',
                    created_at: null,
                    updated_at: null,
                });
        });

        it('allows Admin deactivation only for active enrollment with explicit provenance', async () => {
            renderPanel('active');

            const button =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'إلغاء التسجيل',
                    },
                );

            expect(
                button
            ).toBeDisabled();

            fireEvent.change(
                screen.getByLabelText(
                    'سبب إلغاء التسجيل'
                ),
                {
                    target: {
                        value:
                            '  إلغاء إداري تشغيلي.  ',
                    },
                },
            );

            expect(
                button
            ).toBeEnabled();

            fireEvent.click(button);

            await waitFor(() => {
                expect(
                    deactivateStudentEnrollmentMock
                ).toHaveBeenCalledWith(
                    'enrollment-1',
                    {
                        operation_id:
                            operationUuid,
                        reason:
                            'إلغاء إداري تشغيلي.',
                    },
                );
            });
        });

        it('does not expose Admin deactivation for pending enrollment', () => {
            renderPanel('pending');

            expect(
                screen.getByText(
                    /بانتظار قرار المعلم/
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'button',
                    {
                        name:
                            'إلغاء التسجيل',
                    },
                ),
            ).not.toBeInTheDocument();

            expect(
                screen.getByText(
                    /لا يمنح Admin صلاحية/
                ),
            ).toBeInTheDocument();

            expect(
                deactivateStudentEnrollmentMock
            ).not.toHaveBeenCalled();
        });

        it('does not offer another lifecycle mutation for inactive enrollment', () => {
            renderPanel('inactive');

            expect(
                screen.getByText(
                    /غير نشط بالفعل/
                ),
            ).toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'button',
                    {
                        name:
                            'إلغاء التسجيل',
                    },
                ),
            ).not.toBeInTheDocument();

            expect(
                deactivateStudentEnrollmentMock
            ).not.toHaveBeenCalled();
        });

        it('refreshes Student identity counts, list, and historical detail after deactivation', async () => {
            const client =
                renderPanel('active');

            const invalidateSpy =
                vi.spyOn(
                    client,
                    'invalidateQueries',
                );

            fireEvent.change(
                screen.getByLabelText(
                    'سبب إلغاء التسجيل'
                ),
                {
                    target: {
                        value:
                            'Refresh truth.',
                    },
                },
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'إلغاء التسجيل',
                    },
                ),
            );

            await waitFor(() => {
                expect(
                    deactivateStudentEnrollmentMock
                ).toHaveBeenCalledTimes(
                    1
                );
            });

            await waitFor(() => {
                expect(
                    invalidateSpy
                ).toHaveBeenCalledWith({
                    queryKey: [
                        'admin',
                        'operations',
                        'students',
                    ],
                });

                expect(
                    invalidateSpy
                ).toHaveBeenCalledWith({
                    queryKey: [
                        'admin',
                        'operations',
                        'students',
                        'student-user-1',
                    ],
                });

                expect(
                    invalidateSpy
                ).toHaveBeenCalledWith({
                    queryKey: [
                        'admin',
                        'operations',
                        'students',
                        'student-user-1',
                        'enrollments',
                    ],
                });

                expect(
                    invalidateSpy
                ).toHaveBeenCalledWith({
                    queryKey: [
                        'admin',
                        'operations',
                        'student-enrollments',
                        'enrollment-1',
                    ],
                });
            });
        });
    },
);

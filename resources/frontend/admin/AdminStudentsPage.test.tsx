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
    MemoryRouter,
} from 'react-router-dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    AdminStudentsPage,
} from './AdminStudentsPage';

const fetchAdminStudentsMock =
    vi.fn();

const fetchAdminStudentMock =
    vi.fn();

const fetchStudentEnrollmentsMock =
    vi.fn();

const fetchStudentEnrollmentMock =
    vi.fn();

vi.mock(
    './operations/StudentEnrollmentDeactivationPanel',
    () => ({
        StudentEnrollmentDeactivationPanel: () => (
            <div data-testid="student-enrollment-deactivation-panel" />
        ),
    }),
);

vi.mock('./operations/studentApi', () => ({
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
    adminStudentDirectoryKey: (
        parameters: unknown,
    ) => [
        'admin',
        'operations',
        'students',
        'directory',
        parameters,
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
    fetchAdminStudentDirectory: (
        parameters: unknown,
        signal: AbortSignal,
    ) => fetchAdminStudentsMock(
        parameters,
        signal,
    ),
    fetchAdminStudent: (
        id: string,
    ) =>
        fetchAdminStudentMock(id),
    fetchStudentEnrollments: (
        id: string,
    ) =>
        fetchStudentEnrollmentsMock(
            id
        ),
    fetchStudentEnrollment: (
        id: string,
    ) =>
        fetchStudentEnrollmentMock(
            id
        ),
}));

function student({
    id = 'student-user-1',
    profileId = 'profile-1',
    name = 'طالب أول',
}: {
    id?: string;
    profileId?: string | null;
    name?: string;
} = {}) {
    return {
        user_id: id,
        learner_profile_id:
            profileId,
        name,
        email:
            `${id}@example.test`,
        role: 'student' as const,
        status: 'active' as const,
        enrollment_counts: {
            pending: 0,
            active: 1,
            inactive: 0,
            total: 1,
        },
        created_at: null,
        learner_profile_created_at:
            profileId
                ? null
                : null,
    };
}

function directoryPage(
    items: Array<
        Omit<ReturnType<typeof student>, 'status'>
        & { status: 'active' | 'disabled' }
    >,
    currentPage = 1,
    lastPage = 1,
) {
    return {
        items,
        pagination: {
            current_page: currentPage,
            per_page: 20,
            total: items.length,
            last_page: lastPage,
        },
    };
}

function enrollment() {
    return {
        id: 'enrollment-1',
        student: {
            user_id:
                'student-user-1',
            learner_profile_id:
                'profile-1',
            name: 'طالب أول',
            email:
                'student-user-1@example.test',
            status:
                'active' as const,
        },
        status:
            'active' as const,
        teacher_subject_assignment: {
            id: 'assignment-1',
            status:
                'active' as const,
            teacher: {
                user_id:
                    'teacher-1',
                name:
                    'معلم الرياضيات',
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
    };
}

function enrollmentDetail() {
    return {
        ...enrollment(),
        transitions: [
            {
                sequence_number: 1,
                from_status: null,
                to_status:
                    'pending' as const,
                outcome:
                    'requested',
                actor_user_id:
                    'student-user-1',
                actor_current: {
                    name:
                        'طالب أول',
                    role:
                        'student' as const,
                    status:
                        'active' as const,
                },
                operation_id:
                    'operation-1',
                reason:
                    'Student requested.',
                effective_at: null,
                created_at: null,
            },
            {
                sequence_number: 2,
                from_status:
                    'pending' as const,
                to_status:
                    'active' as const,
                outcome:
                    'accepted',
                actor_user_id:
                    'teacher-1',
                actor_current: {
                    name:
                        'معلم الرياضيات',
                    role:
                        'teacher' as const,
                    status:
                        'disabled' as const,
                },
                operation_id:
                    'operation-2',
                reason:
                    'Teacher accepted.',
                effective_at: null,
                created_at: null,
            },
        ],
    };
}

function renderPage(
    initialEntry = '/admin/students',
) {
    const client =
        new QueryClient({
            defaultOptions: {
                queries: {
                    retry: false,
                },
            },
        });

    render(
        <MemoryRouter
            initialEntries={[initialEntry]}
        >
            <QueryClientProvider
                client={client}
            >
                <AdminStudentsPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe(
    'AdminStudentsPage',
    () => {
        beforeEach(() => {
            fetchAdminStudentsMock
                .mockReset();

            fetchAdminStudentMock
                .mockReset();

            fetchStudentEnrollmentsMock
                .mockReset();

            fetchStudentEnrollmentMock
                .mockReset();

            fetchAdminStudentsMock
                .mockResolvedValue(
                    directoryPage([
                        student(),
                    ]),
                );

            fetchAdminStudentMock
                .mockResolvedValue(
                    student()
                );

            fetchStudentEnrollmentsMock
                .mockResolvedValue([
                    enrollment(),
                ]);

            fetchStudentEnrollmentMock
                .mockResolvedValue(
                    enrollmentDetail()
                );
        });

        it('keeps User UUID and LearnerProfile UUID visibly distinct', async () => {
            renderPage();

            expect(
                await screen.findByRole(
                    'heading',
                    {
                        name:
                            'إدارة الطلاب',
                    },
                ),
            ).toBeInTheDocument();

            expect(
                await screen.findByText('طالب أول'),
            ).toBeInTheDocument();

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'عرض الطالب طالب أول',
                }),
            );

            expect(
                await screen.findByText(
                    'student-user-1'
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    'profile-1'
                ),
            ).toBeInTheDocument();

            await waitFor(() => {
                expect(
                    fetchStudentEnrollmentsMock
                ).toHaveBeenCalledWith(
                    'student-user-1'
                );
            });
        });

        it('fails closed for malformed Student without LearnerProfile', async () => {
            const malformed =
                student({
                    id:
                        'student-malformed',
                    profileId:
                        null,
                    name:
                        'طالب بدون ملف',
                });

            fetchAdminStudentsMock
                .mockResolvedValue(
                    directoryPage([
                        malformed,
                    ]),
                );

            fetchAdminStudentMock
                .mockResolvedValue(
                    malformed
                );

            renderPage();

            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'عرض الطالب طالب بدون ملف',
                }),
            );

            expect(
                await screen.findByText(
                    /لا يملك LearnerProfile مطابقًا/
                ),
            ).toBeInTheDocument();

            expect(
                fetchStudentEnrollmentsMock
            ).not.toHaveBeenCalled();
        });

        it('shows raw enrollment relationship without claiming effective access', async () => {
            renderPage();

            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'عرض الطالب طالب أول',
                }),
            );

            expect(
                await screen.findByText(
                    'الرياضيات'
                ),
            ).toBeInTheDocument();

            expect(
                screen.getAllByText(
                    /معلم الرياضيات/
                ).length
            ).toBeGreaterThan(0);

            expect(
                screen.getByText(
                    /ليست إثباتًا للوصول التعليمي الفعلي/
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    /لا نستنتجه من enrollment.status وحده/
                ),
            ).toBeInTheDocument();
        });

        it('separates immutable transition actor provenance from current actor display state', async () => {
            renderPage();

            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'عرض الطالب طالب أول',
                }),
            );

            expect(
                await screen.findByText(
                    /actor_user_id هو/
                ),
            ).toBeInTheDocument();

            expect(
                screen.getAllByText(
                    'teacher-1'
                ).length
            ).toBeGreaterThan(0);

            expect(
                screen.getByText(
                    /معلم الرياضيات — disabled/
                ),
            ).toBeInTheDocument();

            expect(
                screen.getByText(
                    '#2 accepted'
                ),
            ).toBeInTheDocument();
        });

        it('submits Student search to the server', async () => {
            const physicsStudent = student({
                id: 'student-user-2',
                profileId: 'profile-2',
                name: 'طالب الفيزياء',
            });
            fetchAdminStudentsMock.mockImplementation(
                (parameters: { search: string }) =>
                    Promise.resolve(
                        directoryPage(
                            parameters.search
                                ? [physicsStudent]
                                : [student(), physicsStudent],
                        ),
                    ),
            );

            renderPage();

            await screen.findByText(
                'طالب أول'
            );

            expect(
                await screen.findByText(
                    'طالب الفيزياء'
                ),
            ).toBeInTheDocument();

            fireEvent.change(
                screen.getByRole(
                    'searchbox'
                ),
                {
                    target: {
                        value:
                            'الفيزياء',
                    },
                },
            );

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'بحث',
                }),
            );

            await waitFor(() => {
                expect(
                    fetchAdminStudentsMock,
                ).toHaveBeenLastCalledWith(
                    expect.objectContaining({
                        page: 1,
                        perPage: 20,
                        search: 'الفيزياء',
                        status: null,
                    }),
                    expect.any(AbortSignal),
                );
            });

            expect(
                await screen.findByText(
                    'طالب الفيزياء'
                ),
            ).toBeInTheDocument();
        });

        it('limits an oversized URL search before the first Student directory request', async () => {
            const effectiveSearch =
                'أ'.repeat(120);
            const params = new URLSearchParams({
                page: '4',
                search:
                    `  ${effectiveSearch}أ  `,
            });

            renderPage(
                `/admin/students?${params.toString()}`,
            );

            await waitFor(() => {
                expect(
                    fetchAdminStudentsMock,
                ).toHaveBeenCalledTimes(1);
                expect(
                    fetchAdminStudentsMock,
                ).toHaveBeenNthCalledWith(
                    1,
                    expect.objectContaining({
                        page: 4,
                        perPage: 20,
                        search: effectiveSearch,
                        status: null,
                    }),
                    expect.any(AbortSignal),
                );
            });

            expect(
                screen.getByRole('searchbox'),
            ).toHaveValue(effectiveSearch);
        });

        it('applies Student status and pagination without changing selected User identity', async () => {
            const disabledStudent = {
                ...student({
                    id: 'student-disabled',
                    profileId: null,
                    name: 'طالب معطّل',
                }),
                status: 'disabled' as const,
            };
            fetchAdminStudentsMock.mockImplementation(
                (parameters: { page: number; status: string | null }) =>
                    Promise.resolve(
                        parameters.status === 'disabled'
                            ? directoryPage(
                                [disabledStudent],
                                parameters.page,
                                2,
                            )
                            : directoryPage([student()], 1, 2),
                    ),
            );

            renderPage();
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'عرض الطالب طالب أول',
                }),
            );
            expect(await screen.findByText('student-user-1'))
                .toBeInTheDocument();

            fireEvent.change(
                screen.getByLabelText('حالة الحساب'),
                { target: { value: 'disabled' } },
            );

            expect(
                await screen.findByRole('button', {
                    name: 'عرض الطالب طالب معطّل',
                }),
            ).toBeInTheDocument();
            expect(screen.getAllByText('student-user-1').length)
                .toBeGreaterThan(0);

            fireEvent.click(
                screen.getByRole('button', { name: 'التالي' }),
            );

            await waitFor(() => {
                expect(fetchAdminStudentsMock).toHaveBeenLastCalledWith(
                    expect.objectContaining({
                        page: 2,
                        status: 'disabled',
                    }),
                    expect.any(AbortSignal),
                );
            });
        });

        it('does not expose enrollment create accept or decline controls', async () => {
            renderPage();

            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'عرض الطالب طالب أول',
                }),
            );

            await screen.findByText(
                'الرياضيات'
            );

            expect(
                screen.queryByRole(
                    'button',
                    {
                        name:
                            /إنشاء تسجيل/
                    },
                ),
            ).not.toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'button',
                    {
                        name:
                            /قبول/
                    },
                ),
            ).not.toBeInTheDocument();

            expect(
                screen.queryByRole(
                    'button',
                    {
                        name:
                            /رفض/
                    },
                ),
            ).not.toBeInTheDocument();
        });
    },
);

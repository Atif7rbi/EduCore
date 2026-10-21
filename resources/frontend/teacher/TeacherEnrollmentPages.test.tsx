import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import {
    MemoryRouter,
    useNavigate,
} from 'react-router-dom';

import {
    TeacherEnrollmentRequestsPage,
    TeacherStudentsPage,
} from './TeacherEnrollmentPages';
import {
    teacherEnrollmentsKey,
} from './enrollmentApi';

import type {
    TeacherEnrollmentPage,
} from './enrollmentApi';

const testState = vi.hoisted(() => ({
    userId: 'teacher-one',
}));

const enrollmentApiMocks = vi.hoisted(() => ({
    decide: vi.fn(),
    fetch: vi.fn(),
}));

vi.mock('../auth/AuthProvider', () => ({
    useAuth: () => ({
        status: 'authenticated',
        user: {
            email: 'teacher@example.test',
            id: testState.userId,
            learner_profile_id: null,
            name: 'معلم تجريبي',
            role: 'teacher',
            status: 'active',
        },
    }),
}));

vi.mock('./enrollmentApi', async () => {
    const actual =
        await vi.importActual<
            typeof import('./enrollmentApi')
        >('./enrollmentApi');

    return {
        ...actual,
        decideTeacherEnrollment:
            enrollmentApiMocks.decide,
        fetchTeacherEnrollments:
            enrollmentApiMocks.fetch,
    };
});

const pendingEnrollment = {
    id: 'enrollment-pending',
    student: {
        user_id: 'student-one',
        name: 'طالب معلّق',
    },
    teacher_subject_assignment: {
        id: 'assignment-one',
        status: 'active' as const,
        subject: {
            id: 'subject-one',
            code: 'mathematics',
            name: 'الرياضيات',
        },
    },
    status: 'pending' as const,
    created_at: '2026-10-21T08:00:00.000Z',
    accepted_at: null,
    updated_at: '2026-10-21T08:00:00.000Z',
};

const activeEnrollment = {
    ...pendingEnrollment,
    id: 'enrollment-active',
    student: {
        user_id: 'student-two',
        name: 'طالب نشط',
    },
    status: 'active' as const,
    accepted_at: '2026-10-21T09:00:00.000Z',
};

function enrollmentPage(
    items: TeacherEnrollmentPage['items'],
    lastPage = 1,
    currentPage = 1,
    total = items.length,
): TeacherEnrollmentPage {
    return {
        items,
        pagination: {
            current_page: currentPage,
            last_page: lastPage,
            per_page: 20,
            total,
        },
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return {
        promise,
        reject,
        resolve,
    };
}

function ContextSwitchControl() {
    const navigate = useNavigate();

    return (
        <button
            type="button"
            onClick={() => {
                navigate(
                    '/teacher/enrollments?assignment_id=assignment-two',
                );
            }}
        >
            تبديل سياق الاختبار
        </button>
    );
}

function renderPage(
    page: 'requests' | 'students',
    initialEntry = '/teacher/enrollments',
) {
    const queryClient = new QueryClient({
        defaultOptions: {
            mutations: {
                retry: false,
            },
            queries: {
                retry: false,
            },
        },
    });

    render(
        <MemoryRouter initialEntries={[initialEntry]}>
            <QueryClientProvider client={queryClient}>
                <ContextSwitchControl />
                {page === 'requests'
                    ? <TeacherEnrollmentRequestsPage />
                    : <TeacherStudentsPage />}
            </QueryClientProvider>
        </MemoryRouter>,
    );

    return queryClient;
}

describe('TeacherEnrollmentPages', () => {
    beforeEach(() => {
        testState.userId = 'teacher-one';
        enrollmentApiMocks.decide.mockReset();
        enrollmentApiMocks.fetch.mockReset();
        enrollmentApiMocks.decide.mockResolvedValue({
            id: pendingEnrollment.id,
            status: 'active',
        });
        vi.spyOn(window, 'confirm')
            .mockReturnValue(true);
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('renders pending requests and accepts only after confirmation', async () => {
        enrollmentApiMocks.fetch.mockResolvedValue(
            enrollmentPage([pendingEnrollment]),
        );

        const queryClient = renderPage('requests');

        expect(
            await screen.findByText('طالب معلّق'),
        ).toBeInTheDocument();
        expect(document.documentElement).toHaveAttribute('dir', 'rtl');
        expect(
            screen.getByRole('article'),
        ).toHaveClass('teacher-enrollment-card');
        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'طلبات الالتحاق',
            }).closest('section'),
        ).toHaveClass('teacher-enrollment-page');
        expect(
            screen.getByText('الرياضيات'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('teacher@example.test'),
        ).not.toBeInTheDocument();
        expect(
            enrollmentApiMocks.fetch,
        ).toHaveBeenCalledWith(
            'pending',
            1,
            expect.any(AbortSignal),
            null,
        );
        expect(
            queryClient.getQueryCache().getAll()[0]
                ?.queryKey,
        ).toEqual([
            'teacher',
            'teacher-one',
            'enrollments',
            'pending',
            'all-assignments',
            1,
        ]);

        vi.mocked(window.confirm)
            .mockReturnValueOnce(false);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'قبول الطلب',
            }),
        );

        expect(
            enrollmentApiMocks.decide,
        ).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'قبول الطلب',
            }),
        );

        await waitFor(() => {
            expect(
                enrollmentApiMocks.decide,
            ).toHaveBeenCalledWith(
                pendingEnrollment.id,
                'accept',
                expect.any(String),
                'Teacher accepted the enrollment request.',
            );
        });

        expect(
            await screen.findByText(
                'تم قبول طلب الالتحاق.',
            ),
        ).toBeInTheDocument();
        await waitFor(() => {
            expect(
                enrollmentApiMocks.fetch,
            ).toHaveBeenCalledTimes(2);
        });
    });

    it('prevents duplicate decisions while a request is pending', async () => {
        let resolveDecision!: (value: {
            id: string;
            status: 'active';
        }) => void;

        enrollmentApiMocks.fetch.mockResolvedValue(
            enrollmentPage([pendingEnrollment]),
        );
        enrollmentApiMocks.decide.mockReturnValue(
            new Promise((resolve) => {
                resolveDecision = resolve;
            }),
        );

        renderPage('requests');

        const accept = await screen.findByRole(
            'button',
            {
                name: 'قبول الطلب',
            },
        );

        fireEvent.click(accept);
        fireEvent.click(accept);

        await waitFor(() => {
            expect(
                enrollmentApiMocks.decide,
            ).toHaveBeenCalledTimes(1);
        });

        resolveDecision({
            id: pendingEnrollment.id,
            status: 'active',
        });

        await screen.findByText(
            'تم قبول طلب الالتحاق.',
        );
    });

    it('uses the canonical decline decision and reports errors', async () => {
        enrollmentApiMocks.fetch.mockResolvedValue(
            enrollmentPage([pendingEnrollment]),
        );
        enrollmentApiMocks.decide.mockRejectedValue(
            new Error('تعذر رفض الطلب.'),
        );

        renderPage('requests');

        fireEvent.click(
            await screen.findByRole('button', {
                name: 'رفض الطلب',
            }),
        );

        await waitFor(() => {
            expect(
                enrollmentApiMocks.decide,
            ).toHaveBeenCalledWith(
                pendingEnrollment.id,
                'decline',
                expect.any(String),
                'Teacher declined the enrollment request.',
            );
        });

        expect(
            await screen.findByText('تعذر رفض الطلب.'),
        ).toBeInTheDocument();
    });

    it('renders active students without enrollment decision actions', async () => {
        enrollmentApiMocks.fetch.mockResolvedValue(
            enrollmentPage([activeEnrollment]),
        );

        renderPage('students');

        expect(
            await screen.findByRole('heading', {
                level: 1,
                name: 'طلابي',
            }),
        ).toBeInTheDocument();
        expect(
            await screen.findByText('طالب نشط'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('تاريخ الالتحاق'),
        ).toBeInTheDocument();
        expect(
            enrollmentApiMocks.fetch,
        ).toHaveBeenCalledWith(
            'active',
            1,
            expect.any(AbortSignal),
            null,
        );
        expect(
            screen.queryByRole('button', {
                name: 'قبول الطلب',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'رفض الطلب',
            }),
        ).not.toBeInTheDocument();
    });

    it('provides loading, empty, error and pagination feedback', async () => {
        enrollmentApiMocks.fetch.mockResolvedValueOnce(
            enrollmentPage([], 2),
        );

        renderPage('requests');

        expect(
            screen.getByText('جارٍ تحميل البيانات...'),
        ).toBeInTheDocument();
        expect(
            await screen.findByText(
                'لا توجد طلبات التحاق معلّقة.',
            ),
        ).toBeInTheDocument();

        cleanup();
        enrollmentApiMocks.fetch.mockReset();
        enrollmentApiMocks.fetch.mockRejectedValueOnce(
            new Error('تعذر تحميل الطلبات.'),
        );

        renderPage('requests');

        expect(
            await screen.findByText(
                'تعذر تحميل الطلبات.',
            ),
        ).toBeInTheDocument();

        cleanup();
        enrollmentApiMocks.fetch.mockReset();
        enrollmentApiMocks.fetch.mockResolvedValue(
            enrollmentPage([activeEnrollment], 2),
        );

        renderPage('students');

        fireEvent.click(
            await screen.findByRole('button', {
                name: 'الصفحة التالية',
            }),
        );

        await waitFor(() => {
            expect(
                enrollmentApiMocks.fetch,
            ).toHaveBeenCalledWith(
                'active',
                2,
                expect.any(AbortSignal),
                null,
            );
        });
    });

    it('clamps after accepting the final entry and preserves filters', async () => {
        const remainingEnrollment = {
            ...pendingEnrollment,
            id: 'enrollment-remaining',
            student: {
                user_id: 'student-remaining',
                name: 'طالب متبقٍ',
            },
        };
        let pageTwoLoads = 0;

        enrollmentApiMocks.fetch.mockImplementation(
            async (
                status: string,
                page: number,
                _signal: AbortSignal,
                assignmentId: string | null,
            ) => {
                expect(status).toBe('pending');
                expect(assignmentId).toBe('assignment-one');

                if (page === 1) {
                    return enrollmentPage(
                        [remainingEnrollment],
                        pageTwoLoads >= 2 ? 1 : 2,
                        1,
                        pageTwoLoads >= 2 ? 1 : 2,
                    );
                }

                pageTwoLoads += 1;

                return pageTwoLoads === 1
                    ? enrollmentPage(
                        [pendingEnrollment],
                        2,
                        2,
                        2,
                    )
                    : enrollmentPage([], 1, 2, 1);
            },
        );

        renderPage(
            'requests',
            '/teacher/enrollments?assignment_id=assignment-one',
        );

        fireEvent.click(
            await screen.findByRole('button', {
                name: 'الصفحة التالية',
            }),
        );

        expect(
            await screen.findByText('طالب معلّق'),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'قبول الطلب',
            }),
        );

        expect(
            await screen.findByText('طالب متبقٍ'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('الصفحة 1 من 1'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'الصفحة السابقة',
            }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', {
                name: 'الصفحة التالية',
            }),
        ).toBeDisabled();
        expect(
            enrollmentApiMocks.decide,
        ).toHaveBeenCalledTimes(1);
        expect(
            enrollmentApiMocks.fetch.mock.calls.every(
                ([status, , , assignmentId]) =>
                    status === 'pending'
                    && assignmentId === 'assignment-one',
            ),
        ).toBe(true);
    });

    it('normalizes an empty decline result without repeating the mutation', async () => {
        const firstPageEnrollment = {
            ...pendingEnrollment,
            id: 'enrollment-first-page',
            student: {
                user_id: 'student-first-page',
                name: 'طالب في الصفحة الأولى',
            },
        };
        let decisionCompleted = false;

        enrollmentApiMocks.decide.mockImplementation(
            async () => {
                decisionCompleted = true;

                return {
                    id: pendingEnrollment.id,
                    status: 'declined',
                };
            },
        );
        enrollmentApiMocks.fetch.mockImplementation(
            async (
                status: string,
                page: number,
                _signal: AbortSignal,
                assignmentId: string | null,
            ) => {
                expect(status).toBe('pending');
                expect(assignmentId).toBe('assignment-one');

                if (decisionCompleted) {
                    return enrollmentPage([], 1, page, 0);
                }

                return page === 1
                    ? enrollmentPage(
                        [firstPageEnrollment],
                        2,
                        1,
                        2,
                    )
                    : enrollmentPage(
                        [pendingEnrollment],
                        2,
                        2,
                        2,
                    );
            },
        );

        renderPage(
            'requests',
            '/teacher/enrollments?assignment_id=assignment-one',
        );

        fireEvent.click(
            await screen.findByRole('button', {
                name: 'الصفحة التالية',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'رفض الطلب',
            }),
        );

        expect(
            await screen.findByText(
                'لا توجد طلبات التحاق معلّقة.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'رفض الطلب',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'الصفحة التالية',
            }),
        ).not.toBeInTheDocument();
        expect(
            enrollmentApiMocks.decide,
        ).toHaveBeenCalledTimes(1);
        expect(
            enrollmentApiMocks.fetch.mock.calls.every(
                ([status, , , assignmentId]) =>
                    status === 'pending'
                    && assignmentId === 'assignment-one',
            ),
        ).toBe(true);
    });

    it('hides stale cards and pagination until reconciliation succeeds', async () => {
        const refresh = deferred<TeacherEnrollmentPage>();
        const refreshedEnrollment = {
            ...pendingEnrollment,
            id: 'enrollment-refreshed',
            student: {
                user_id: 'student-refreshed',
                name: 'طالب محدث',
            },
        };

        enrollmentApiMocks.fetch
            .mockResolvedValueOnce(
                enrollmentPage([pendingEnrollment], 2, 1, 2),
            )
            .mockReturnValueOnce(refresh.promise);

        renderPage('requests');

        expect(
            await screen.findByText('طالب معلّق'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'الصفحة التالية',
            }),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'قبول الطلب',
            }),
        );

        expect(
            await screen.findByText(
                'جارٍ تحديث قائمة الالتحاق المعتمدة...',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('طالب معلّق'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'الصفحة التالية',
            }),
        ).not.toBeInTheDocument();
        expect(
            enrollmentApiMocks.decide,
        ).toHaveBeenCalledTimes(1);

        refresh.resolve(
            enrollmentPage([refreshedEnrollment]),
        );

        expect(
            await screen.findByText('طالب محدث'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('طالب معلّق'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('الصفحة 1 من 1'),
        ).toBeInTheDocument();
    });

    it('keeps stale data hidden and retries only the failed read', async () => {
        const retry = deferred<TeacherEnrollmentPage>();

        enrollmentApiMocks.fetch
            .mockResolvedValueOnce(
                enrollmentPage([pendingEnrollment], 2, 1, 2),
            )
            .mockRejectedValueOnce(
                new Error('تعذر تحديث القائمة.'),
            )
            .mockReturnValueOnce(retry.promise);

        renderPage(
            'requests',
            '/teacher/enrollments?assignment_id=assignment-one',
        );

        fireEvent.click(
            await screen.findByRole('button', {
                name: 'رفض الطلب',
            }),
        );

        expect(
            await screen.findByText(
                /تم تنفيذ قرار الالتحاق، لكن تعذر/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('طالب معلّق'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'الصفحة التالية',
            }),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إعادة تحميل القائمة',
            }),
        );

        expect(
            await screen.findByText(
                'جارٍ تحديث قائمة الالتحاق المعتمدة...',
            ),
        ).toBeInTheDocument();
        expect(
            enrollmentApiMocks.decide,
        ).toHaveBeenCalledTimes(1);

        retry.resolve(enrollmentPage([]));

        expect(
            await screen.findByText(
                'لا توجد طلبات التحاق معلّقة.',
            ),
        ).toBeInTheDocument();
        expect(
            enrollmentApiMocks.decide,
        ).toHaveBeenCalledTimes(1);
        expect(
            enrollmentApiMocks.fetch.mock.calls.every(
                ([status, , , assignmentId]) =>
                    status === 'pending'
                    && assignmentId === 'assignment-one',
            ),
        ).toBe(true);
    });

    it.each([
        ['accept', 'قبول الطلب'],
        ['decline', 'رفض الطلب'],
    ] as const)(
        'cancels a pre-decision read before %s reconciliation',
        async (action, buttonName) => {
            const preDecisionRead =
                deferred<TeacherEnrollmentPage>();
            let preDecisionSignal:
                AbortSignal | undefined;
            const refreshedEnrollment = {
                ...pendingEnrollment,
                id: `enrollment-after-${action}`,
                student: {
                    user_id: `student-after-${action}`,
                    name: action === 'accept'
                        ? 'طالب بعد القبول'
                        : 'طالب بعد الرفض',
                },
            };

            enrollmentApiMocks.fetch
                .mockResolvedValueOnce(
                    enrollmentPage([pendingEnrollment]),
                )
                .mockImplementationOnce(
                    async (
                        _status: string,
                        _page: number,
                        signal: AbortSignal,
                    ) => {
                        preDecisionSignal = signal;

                        return preDecisionRead.promise;
                    },
                )
                .mockResolvedValueOnce(
                    enrollmentPage([refreshedEnrollment]),
                );

            const queryClient = renderPage('requests');

            expect(
                await screen.findByText('طالب معلّق'),
            ).toBeInTheDocument();

            const earlierRefetch =
                queryClient.refetchQueries({
                    exact: true,
                    queryKey: teacherEnrollmentsKey(
                        'teacher-one',
                        'pending',
                        1,
                    ),
                });

            await waitFor(() => {
                expect(
                    enrollmentApiMocks.fetch,
                ).toHaveBeenCalledTimes(2);
            });

            fireEvent.click(
                screen.getByRole('button', {
                    name: buttonName,
                }),
            );

            expect(
                await screen.findByText(
                    refreshedEnrollment.student.name,
                ),
            ).toBeInTheDocument();
            expect(preDecisionSignal?.aborted).toBe(true);
            expect(
                enrollmentApiMocks.fetch,
            ).toHaveBeenCalledTimes(3);
            expect(
                enrollmentApiMocks.decide,
            ).toHaveBeenCalledTimes(1);

            preDecisionRead.resolve(
                enrollmentPage([pendingEnrollment]),
            );
            await earlierRefetch;

            await waitFor(() => {
                expect(
                    screen.queryByText('طالب معلّق'),
                ).not.toBeInTheDocument();
                expect(
                    screen.getByText(
                        refreshedEnrollment.student.name,
                    ),
                ).toBeInTheDocument();
            });
        },
    );

    it('ignores stale reconciliation completion after teacher and assignment change', async () => {
        const oldContextRead =
            deferred<TeacherEnrollmentPage>();
        let oldContextLoads = 0;
        const newContextEnrollment = {
            ...pendingEnrollment,
            id: 'enrollment-new-context',
            student: {
                user_id: 'student-new-context',
                name: 'طالب السياق الجديد',
            },
        };

        enrollmentApiMocks.fetch.mockImplementation(
            async (
                _status: string,
                _page: number,
                _signal: AbortSignal,
                assignmentId: string | null,
            ) => {
                if (assignmentId === 'assignment-two') {
                    return enrollmentPage(
                        [newContextEnrollment],
                    );
                }

                oldContextLoads += 1;

                return oldContextLoads === 1
                    ? enrollmentPage([pendingEnrollment])
                    : oldContextRead.promise;
            },
        );

        renderPage(
            'requests',
            '/teacher/enrollments?assignment_id=assignment-one',
        );

        fireEvent.click(
            await screen.findByRole('button', {
                name: 'قبول الطلب',
            }),
        );
        expect(
            await screen.findByText(
                'جارٍ تحديث قائمة الالتحاق المعتمدة...',
            ),
        ).toBeInTheDocument();

        testState.userId = 'teacher-two';
        fireEvent.click(
            screen.getByRole('button', {
                name: 'تبديل سياق الاختبار',
            }),
        );

        expect(
            await screen.findByText('طالب السياق الجديد'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('طالب معلّق'),
        ).not.toBeInTheDocument();

        oldContextRead.resolve(
            enrollmentPage([pendingEnrollment]),
        );

        await waitFor(() => {
            expect(
                screen.getByText('طالب السياق الجديد'),
            ).toBeInTheDocument();
            expect(
                screen.queryByText('طالب معلّق'),
            ).not.toBeInTheDocument();
        });
        expect(
            enrollmentApiMocks.decide,
        ).toHaveBeenCalledTimes(1);
        expect(
            enrollmentApiMocks.fetch.mock.calls.some(
                ([, , , assignmentId]) =>
                    assignmentId === 'assignment-two',
            ),
        ).toBe(true);
    });
});

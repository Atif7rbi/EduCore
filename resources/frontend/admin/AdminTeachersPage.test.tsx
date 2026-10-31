import {
    act,
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
    AdminTeachersPage,
} from './AdminTeachersPage';

const fetchAdminTeachersMock =
    vi.fn();

const fetchAdminTeacherMock =
    vi.fn();

const provisionTeacherMock =
    vi.fn();

vi.mock(
    './operations/TeacherAssignmentsPanel',
    () => ({
        TeacherAssignmentsPanel: () => (
            <div data-testid="teacher-assignments-panel" />
        ),
    }),
);

vi.mock('./operations/api', () => ({
    adminTeachersKey: () => [
        'admin',
        'operations',
        'teachers',
    ],
    adminTeacherKey: (
        teacherUserId: string,
    ) => [
        'admin',
        'operations',
        'teachers',
        teacherUserId,
    ],
    adminTeacherDirectoryKey: (
        parameters: unknown,
    ) => [
        'admin',
        'operations',
        'teachers',
        'directory',
        parameters,
    ],
    fetchAdminTeacherDirectory: (
        parameters: unknown,
        signal: AbortSignal,
    ) => fetchAdminTeachersMock(
        parameters,
        signal,
    ),
    fetchAdminTeacher: (
        teacherUserId: string,
    ) =>
        fetchAdminTeacherMock(
            teacherUserId
        ),
    provisionTeacher: (
        payload: unknown,
    ) =>
        provisionTeacherMock(
            payload
        ),
}));

function teacher({
    id = 'teacher-1',
    name = 'معلم الرياضيات',
    email = 'teacher@example.test',
    status = 'active',
    provisioningState = 'completed',
    activeAssignments = 2,
}: {
    id?: string;
    name?: string;
    email?: string;
    status?: 'active' | 'disabled';
    provisioningState?:
        | 'untracked'
        | 'pending_setup'
        | 'completed';
    activeAssignments?: number;
} = {}) {
    return {
        user_id: id,
        name,
        email,
        role: 'teacher' as const,
        status,
        provisioning: {
            state:
                provisioningState,
            provisioned_by_user_id:
                'admin-1',
            setup_completed_at:
                provisioningState
                === 'completed'
                    ? '2026-09-27T00:00:00Z'
                    : null,
            created_at:
                '2026-09-27T00:00:00Z',
        },
        assignment_counts: {
            active:
                activeAssignments,
            inactive: 1,
            total:
                activeAssignments + 1,
        },
        created_at:
            '2026-09-27T00:00:00Z',
    };
}

function directoryPage(
    items: ReturnType<typeof teacher>[],
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

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((resolvePromise) => {
        resolve = resolvePromise;
    });

    return { promise, resolve };
}

function renderPage(
    initialEntry = '/admin/teachers',
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
        <MemoryRouter
            initialEntries={[initialEntry]}
        >
            <QueryClientProvider
                client={client}
            >
                <AdminTeachersPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

describe('AdminTeachersPage', () => {
    beforeEach(() => {
        fetchAdminTeachersMock
            .mockReset();

        fetchAdminTeacherMock
            .mockReset();

        provisionTeacherMock
            .mockReset();

        fetchAdminTeachersMock
            .mockResolvedValue(
                directoryPage([
                    teacher(),
                ]),
            );

        fetchAdminTeacherMock
            .mockImplementation(
                (teacherUserId: string) =>
                    Promise.resolve(
                        teacher({
                            id:
                                teacherUserId,
                        })
                    ),
            );
    });

    it('renders teacher identity, provisioning state, and raw assignment counts', async () => {
        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name:
                        'إدارة المعلمين',
                },
            ),
        ).toBeInTheDocument();

        expect(
            await screen.findByText(
                'معلم الرياضيات'
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'عرض المعلم معلم الرياضيات',
            }),
        );

        expect(
            screen.getByText(
                'اكتمل إعداد الحساب'
            ),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(
                fetchAdminTeacherMock
            ).toHaveBeenCalledWith(
                'teacher-1'
            );
        });

        expect(
            await screen.findByText(
                /حالات TeacherSubjectAssignment الخام/
            ),
        ).toBeInTheDocument();
    });

    it('provisions teacher using name and email only and reports sent setup link', async () => {
        const newTeacher =
            teacher({
                id: 'teacher-new',
                name: 'معلم جديد',
                email:
                    'new.teacher@example.test',
                status: 'disabled',
                provisioningState:
                    'pending_setup',
                activeAssignments: 0,
            });

        provisionTeacherMock
            .mockResolvedValue({
                teacher: {
                    id:
                        newTeacher.user_id,
                    name:
                        newTeacher.name,
                    email:
                        newTeacher.email,
                    role:
                        'teacher',
                    status:
                        'disabled',
                },
                setup: {
                    delivery:
                        'sent',
                },
            });

        fetchAdminTeachersMock
            .mockResolvedValueOnce(
                directoryPage([
                    teacher(),
                ]),
            )
            .mockResolvedValue(
                directoryPage([
                    teacher(),
                    newTeacher,
                ]),
            );

        fetchAdminTeacherMock
            .mockImplementation(
                (id: string) =>
                    Promise.resolve(
                        id ===
                        newTeacher.user_id
                            ? newTeacher
                            : teacher()
                    ),
            );

        renderPage();

        await screen.findByText(
            'معلم الرياضيات'
        );

        fireEvent.change(
            screen.getByLabelText(
                'اسم المعلم'
            ),
            {
                target: {
                    value:
                        '  معلم جديد  ',
                },
            },
        );

        fireEvent.change(
            screen.getByLabelText(
                'البريد الإلكتروني'
            ),
            {
                target: {
                    value:
                        ' new.teacher@example.test ',
                },
            },
        );

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name:
                        'إنشاء حساب المعلم',
                },
            ),
        );

        await waitFor(() => {
            expect(
                provisionTeacherMock
            ).toHaveBeenCalledWith({
                name: 'معلم جديد',
                email:
                    'new.teacher@example.test',
            });
        });

        expect(
            await screen.findByText(
                /تم إنشاء حساب المعلم بحالة غير نشطة وإرسال رابط إعداد كلمة المرور/
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByLabelText(
                /كلمة المرور/
            ),
        ).not.toBeInTheDocument();
    });

    it('keeps failed setup delivery explicitly fail closed', async () => {
        provisionTeacherMock
            .mockResolvedValue({
                teacher: {
                    id: 'teacher-new',
                    name: 'معلم جديد',
                    email:
                        'new.teacher@example.test',
                    role: 'teacher',
                    status:
                        'disabled',
                },
                setup: {
                    delivery:
                        'failed',
                },
            });

        fetchAdminTeachersMock
            .mockResolvedValue(
                directoryPage([
                    teacher(),
                    teacher({
                    id: 'teacher-new',
                    name: 'معلم جديد',
                    email:
                        'new.teacher@example.test',
                    status:
                        'disabled',
                    provisioningState:
                        'pending_setup',
                    activeAssignments: 0,
                    }),
                ]),
            );

        renderPage();

        await screen.findByText(
            'معلم الرياضيات'
        );

        fireEvent.change(
            screen.getByLabelText(
                'اسم المعلم'
            ),
            {
                target: {
                    value: 'معلم جديد',
                },
            },
        );

        fireEvent.change(
            screen.getByLabelText(
                'البريد الإلكتروني'
            ),
            {
                target: {
                    value:
                        'new.teacher@example.test',
                },
            },
        );

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name:
                        'إنشاء حساب المعلم',
                },
            ),
        );

        expect(
            await screen.findByText(
                /تعذر إرسال رابط الإعداد/
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /لم يتم تفعيل الحساب/
            ),
        ).toBeInTheDocument();
    });

    it('submits server-side search without changing provisioning authority', async () => {
        const physicsTeacher = teacher({
            id: 'teacher-physics',
            name: 'معلم الفيزياء',
            email: 'physics@example.test',
        });
        fetchAdminTeachersMock.mockImplementation(
            (parameters: { search: string }) =>
                Promise.resolve(
                    directoryPage(
                        parameters.search
                            ? [physicsTeacher]
                            : [teacher(), physicsTeacher],
                    ),
                ),
        );

        renderPage();

        await screen.findByText(
            'معلم الرياضيات'
        );

        expect(
            screen.getByText(
                'معلم الفيزياء'
            ),
        ).toBeInTheDocument();

        fireEvent.change(
            screen.getByRole(
                'searchbox'
            ),
            {
                target: {
                    value: 'فيزياء',
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
                fetchAdminTeachersMock,
            ).toHaveBeenLastCalledWith(
                expect.objectContaining({
                    page: 1,
                    perPage: 20,
                    search: 'فيزياء',
                    status: null,
                }),
                expect.any(AbortSignal),
            );
        });

        expect(
            await screen.findByText(
                'معلم الفيزياء'
            ),
        ).toBeInTheDocument();
    });

    it('limits an oversized URL search before the first directory request', async () => {
        const effectiveSearch =
            'أ'.repeat(120);
        const params = new URLSearchParams({
            page: '3',
            search:
                `  ${effectiveSearch}أ  `,
        });

        renderPage(
            `/admin/teachers?${params.toString()}`,
        );

        await waitFor(() => {
            expect(
                fetchAdminTeachersMock,
            ).toHaveBeenCalledTimes(1);
            expect(
                fetchAdminTeachersMock,
            ).toHaveBeenNthCalledWith(
                1,
                expect.objectContaining({
                    page: 3,
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

    it('applies status and pagination on the server while retaining exact selected identity', async () => {
        const disabledTeacher = teacher({
            id: 'teacher-disabled',
            name: 'معلم معطّل',
            status: 'disabled',
        });
        fetchAdminTeachersMock.mockImplementation(
            (parameters: { page: number; status: string | null }) =>
                Promise.resolve(
                    parameters.status === 'disabled'
                        ? directoryPage(
                            [disabledTeacher],
                            parameters.page,
                            2,
                        )
                        : directoryPage([teacher()], 1, 2),
                ),
        );

        renderPage();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'عرض المعلم معلم الرياضيات',
            }),
        );
        expect(
            await screen.findByText(/حالات TeacherSubjectAssignment الخام/),
        ).toBeInTheDocument();

        fireEvent.change(
            screen.getByLabelText('حالة الحساب'),
            { target: { value: 'disabled' } },
        );

        expect(
            await screen.findByRole('button', {
                name: 'عرض المعلم معلم معطّل',
            }),
        ).toBeInTheDocument();
        expect(fetchAdminTeacherMock).toHaveBeenLastCalledWith(
            'teacher-1',
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'التالي' }),
        );

        await waitFor(() => {
            expect(fetchAdminTeachersMock).toHaveBeenLastCalledWith(
                expect.objectContaining({
                    page: 2,
                    status: 'disabled',
                }),
                expect.any(AbortSignal),
            );
        });
    });

    it('aborts and isolates an older search response', async () => {
        const oldRequest = deferred<ReturnType<typeof directoryPage>>();
        const newRequest = deferred<ReturnType<typeof directoryPage>>();
        const oldRequestState: {
            signal?: AbortSignal;
        } = {};
        fetchAdminTeachersMock.mockImplementation(
            (
                parameters: { search: string },
                signal: AbortSignal,
            ) => {
                if (parameters.search === 'قديم') {
                    oldRequestState.signal = signal;
                    return oldRequest.promise;
                }
                if (parameters.search === 'حديث') {
                    return newRequest.promise;
                }

                return Promise.resolve(directoryPage([teacher()]));
            },
        );

        renderPage();
        await screen.findByText('معلم الرياضيات');

        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'قديم' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'بحث' }));
        await waitFor(() => {
            expect(oldRequestState.signal).toBeDefined();
        });

        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'حديث' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'بحث' }));

        await act(async () => {
            newRequest.resolve(directoryPage([
                teacher({ id: 'teacher-new', name: 'المعلم الحديث' }),
            ]));
        });
        expect(await screen.findByText('المعلم الحديث')).toBeInTheDocument();
        expect(oldRequestState.signal?.aborted).toBe(true);

        await act(async () => {
            oldRequest.resolve(directoryPage([
                teacher({ id: 'teacher-old', name: 'المعلم القديم' }),
            ]));
        });
        expect(screen.getByText('المعلم الحديث')).toBeInTheDocument();
        expect(screen.queryByText('المعلم القديم')).not.toBeInTheDocument();
    });
});

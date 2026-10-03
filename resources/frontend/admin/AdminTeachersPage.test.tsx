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
    fetchAdminTeachers: () =>
        fetchAdminTeachersMock(),
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

function renderPage() {
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
            <AdminTeachersPage />
        </QueryClientProvider>,
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
            .mockResolvedValue([
                teacher(),
            ]);

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
            screen.getByText(
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
            .mockResolvedValueOnce([
                teacher(),
            ])
            .mockResolvedValue([
                teacher(),
                newTeacher,
            ]);

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
            .mockResolvedValue([
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
            ]);

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

    it('filters teachers locally without provisioning authority changes', async () => {
        fetchAdminTeachersMock
            .mockResolvedValue([
                teacher(),
                teacher({
                    id: 'teacher-physics',
                    name: 'معلم الفيزياء',
                    email:
                        'physics@example.test',
                }),
            ]);

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

        expect(
            screen.queryByText(
                'معلم الرياضيات'
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText(
                'معلم الفيزياء'
            ),
        ).toBeInTheDocument();
    });
});

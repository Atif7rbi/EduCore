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
    TeacherAssignmentsPanel,
} from './TeacherAssignmentsPanel';

const fetchTeacherAssignmentsMock =
    vi.fn();

const assignTeacherSubjectMock =
    vi.fn();

const deactivateTeacherAssignmentMock =
    vi.fn();

const reactivateTeacherAssignmentMock =
    vi.fn();

const fetchAdminCanonicalSubjectsMock =
    vi.fn();

vi.mock('./api', () => ({
    adminCanonicalSubjectsKey: () => [
        'admin',
        'operations',
        'subjects',
    ],
    fetchAdminCanonicalSubjects: () =>
        fetchAdminCanonicalSubjectsMock(),
    adminTeachersKey: () => [
        'admin',
        'operations',
        'teachers',
    ],
    adminTeacherKey: (
        id: string,
    ) => [
        'admin',
        'operations',
        'teachers',
        id,
    ],
    teacherAssignmentsKey: (
        id: string,
    ) => [
        'admin',
        'operations',
        'teachers',
        id,
        'subject-assignments',
    ],
    fetchTeacherAssignments: (
        id: string,
    ) =>
        fetchTeacherAssignmentsMock(id),
    assignTeacherSubject: (
        id: string,
        payload: unknown,
    ) =>
        assignTeacherSubjectMock(
            id,
            payload,
        ),
    deactivateTeacherAssignment: (
        id: string,
        payload: unknown,
    ) =>
        deactivateTeacherAssignmentMock(
            id,
            payload,
        ),
    reactivateTeacherAssignment: (
        id: string,
        payload: unknown,
    ) =>
        reactivateTeacherAssignmentMock(
            id,
            payload,
        ),
}));

const operationUuid =
    '11111111-1111-4111-8111-111111111111';

function teacher(
    status:
        | 'active'
        | 'disabled' = 'active',
) {
    return {
        user_id: 'teacher-1',
        name: 'معلم الرياضيات',
        email:
            'teacher@example.test',
        role: 'teacher' as const,
        status,
        provisioning: {
            state:
                'completed' as const,
            provisioned_by_user_id:
                'admin-1',
            setup_completed_at: null,
            created_at: null,
        },
        assignment_counts: {
            active: 1,
            inactive: 1,
            total: 2,
        },
        created_at: null,
    };
}

function subject({
    id,
    code,
    name,
    status = 'active',
}: {
    id: string;
    code: string;
    name: string;
    status?: 'active' | 'inactive';
}) {
    return {
        id,
        code,
        name,
        icon_key: null,
        thumbnail_key: null,
        sort_order: 10,
        status,
        curricula_count: 0,
        created_at: null,
        updated_at: null,
    };
}

function assignment({
    id,
    subjectId,
    subjectCode,
    subjectName,
    status,
    subjectStatus = 'active',
}: {
    id: string;
    subjectId: string;
    subjectCode: string;
    subjectName: string;
    status: 'active' | 'inactive';
    subjectStatus?: 'active' | 'inactive';
}) {
    return {
        id,
        teacher_user_id:
            'teacher-1',
        subject: {
            id: subjectId,
            code: subjectCode,
            name: subjectName,
            status:
                subjectStatus,
        },
        status,
        created_at: null,
        updated_at: null,
    };
}

function renderPanel(
    teacherStatus:
        | 'active'
        | 'disabled' = 'active',
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
            <TeacherAssignmentsPanel
                teacher={
                    teacher(
                        teacherStatus
                    )
                }
            />
        </QueryClientProvider>,
    );
}

describe(
    'TeacherAssignmentsPanel',
    () => {
        beforeEach(() => {
            vi.stubGlobal(
                'crypto',
                {
                    randomUUID: () =>
                        operationUuid,
                },
            );

            fetchTeacherAssignmentsMock
                .mockReset();

            assignTeacherSubjectMock
                .mockReset();

            deactivateTeacherAssignmentMock
                .mockReset();

            reactivateTeacherAssignmentMock
                .mockReset();

            fetchAdminCanonicalSubjectsMock
                .mockReset();

            assignTeacherSubjectMock
                .mockResolvedValue({});

            deactivateTeacherAssignmentMock
                .mockResolvedValue({});

            reactivateTeacherAssignmentMock
                .mockResolvedValue({});
        });

        it('offers only active canonical subjects not already represented by an assignment', async () => {
            fetchTeacherAssignmentsMock
                .mockResolvedValue([
                    assignment({
                        id: 'assignment-math',
                        subjectId:
                            'subject-math',
                        subjectCode:
                            'mathematics',
                        subjectName:
                            'الرياضيات',
                        status:
                            'active',
                    }),
                ]);

            fetchAdminCanonicalSubjectsMock
                .mockResolvedValue([
                    subject({
                        id:
                            'subject-math',
                        code:
                            'mathematics',
                        name:
                            'الرياضيات',
                    }),
                    subject({
                        id:
                            'subject-physics',
                        code:
                            'physics',
                        name:
                            'الفيزياء',
                    }),
                    subject({
                        id:
                            'subject-chemistry',
                        code:
                            'chemistry',
                        name:
                            'الكيمياء',
                        status:
                            'inactive',
                    }),
                ]);

            renderPanel();

            expect(
                await screen.findByText(
                    'إسنادات المواد'
                ),
            ).toBeInTheDocument();

            const select =
                screen.getByRole(
                    'combobox',
                    {
                        name: 'المادة',
                    },
                );

            expect(select).toHaveTextContent(
                'الفيزياء'
            );

            expect(select).not
                .toHaveTextContent(
                    'الرياضيات'
                );

            expect(select).not
                .toHaveTextContent(
                    'الكيمياء'
                );

            expect(
                screen.queryByLabelText(
                    'اسم المادة'
                ),
            ).not.toBeInTheDocument();
        });

        it('assigns canonical subject with fixed operation provenance', async () => {
            fetchTeacherAssignmentsMock
                .mockResolvedValue([]);

            fetchAdminCanonicalSubjectsMock
                .mockResolvedValue([
                    subject({
                        id:
                            'subject-physics',
                        code:
                            'physics',
                        name:
                            'الفيزياء',
                    }),
                ]);

            renderPanel();

            await screen.findByText(
                'إسنادات المواد'
            );

            fireEvent.change(
                screen.getByLabelText(
                    'سبب العملية'
                ),
                {
                    target: {
                        value:
                            'إسناد تشغيلي.',
                    },
                },
            );

            fireEvent.change(
                screen.getByRole(
                    'combobox',
                    {
                        name: 'المادة',
                    },
                ),
                {
                    target: {
                        value:
                            'subject-physics',
                    },
                },
            );

            fireEvent.click(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'إسناد المادة',
                    },
                ),
            );

            await waitFor(() => {
                expect(
                    assignTeacherSubjectMock
                ).toHaveBeenCalledWith(
                    'teacher-1',
                    {
                        subject_id:
                            'subject-physics',
                        operation_id:
                            operationUuid,
                        reason:
                            'إسناد تشغيلي.',
                    },
                );
            });
        });

        it('allows deactivation for disabled teacher but blocks reactivation', async () => {
            fetchTeacherAssignmentsMock
                .mockResolvedValue([
                    assignment({
                        id:
                            'assignment-active',
                        subjectId:
                            'subject-math',
                        subjectCode:
                            'mathematics',
                        subjectName:
                            'الرياضيات',
                        status:
                            'active',
                    }),
                    assignment({
                        id:
                            'assignment-inactive',
                        subjectId:
                            'subject-physics',
                        subjectCode:
                            'physics',
                        subjectName:
                            'الفيزياء',
                        status:
                            'inactive',
                    }),
                ]);

            fetchAdminCanonicalSubjectsMock
                .mockResolvedValue([]);

            renderPanel('disabled');

            await screen.findByText(
                /حساب المعلم غير نشط/
            );

            fireEvent.change(
                screen.getByLabelText(
                    'سبب العملية'
                ),
                {
                    target: {
                        value:
                            'تنظيف إداري.',
                    },
                },
            );

            const deactivate =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'إلغاء الإسناد',
                    },
                );

            expect(
                deactivate
            ).toBeEnabled();

            const reactivate =
                screen.getByRole(
                    'button',
                    {
                        name:
                            'إعادة التفعيل',
                    },
                );

            expect(
                reactivate
            ).toBeDisabled();

            fireEvent.click(
                deactivate
            );

            await waitFor(() => {
                expect(
                    deactivateTeacherAssignmentMock
                ).toHaveBeenCalledWith(
                    'assignment-active',
                    {
                        operation_id:
                            operationUuid,
                        reason:
                            'تنظيف إداري.',
                    },
                );
            });

            expect(
                reactivateTeacherAssignmentMock
            ).not.toHaveBeenCalled();
        });

        it('blocks reactivation when the canonical subject is inactive', async () => {
            fetchTeacherAssignmentsMock
                .mockResolvedValue([
                    assignment({
                        id:
                            'assignment-physics',
                        subjectId:
                            'subject-physics',
                        subjectCode:
                            'physics',
                        subjectName:
                            'الفيزياء',
                        status:
                            'inactive',
                        subjectStatus:
                            'inactive',
                    }),
                ]);

            fetchAdminCanonicalSubjectsMock
                .mockResolvedValue([]);

            renderPanel();

            await screen.findByText(
                'الفيزياء'
            );

            fireEvent.change(
                screen.getByLabelText(
                    'سبب العملية'
                ),
                {
                    target: {
                        value:
                            'محاولة إعادة تفعيل.',
                    },
                },
            );

            expect(
                screen.getByRole(
                    'button',
                    {
                        name:
                            'إعادة التفعيل',
                    },
                ),
            ).toBeDisabled();
        });
    },
);

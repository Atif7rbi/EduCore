import {
    act,
} from 'react';
import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    cleanup,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    RouterProvider,
} from 'react-router-dom';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    router,
} from './router';

interface MockAuthState {
    status: 'authenticated';
    user: {
        id: string;
        name: string;
        email: string;
        role: 'student' | 'teacher' | 'admin';
        status: string;
        learner_profile_id: string | null;
    };
}

let authState: MockAuthState;

vi.mock('../auth/AuthProvider', () => ({
    useAuth: () => ({
        ...authState,
        error: null,
        sessionIssue: null,
        login: vi.fn(),
        logout: vi.fn(),
        refresh: vi.fn(),
    }),
}));

vi.mock('../learner/LessonPage', () => ({
    LessonPage: () => (
        <h1>Canonical lesson</h1>
    ),
}));

vi.mock('../teacher/TeacherContextSelector', () => ({
    TeacherContextSelector: () => (
        <div data-testid="teacher-context-selector" />
    ),
}));

vi.mock('../teacher/enrollmentApi', async () => {
    const actual =
        await vi.importActual<
            typeof import('../teacher/enrollmentApi')
        >('../teacher/enrollmentApi');

    return {
        ...actual,
        decideTeacherEnrollment: vi.fn(),
        fetchTeacherEnrollments: vi.fn().mockResolvedValue({
            items: [],
            pagination: {
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 0,
            },
        }),
    };
});

vi.mock('../teacher/api', async () => {
    const actual =
        await vi.importActual<
            typeof import('../teacher/api')
        >('../teacher/api');

    return {
        ...actual,
        fetchTeacherAssignments: vi.fn().mockResolvedValue([]),
        fetchTeacherCurricula: vi.fn().mockResolvedValue([]),
        fetchTeacherCurriculumVersions: vi.fn().mockResolvedValue([]),
    };
});

vi.mock('../teacher/monitoringApi', async () => {
    const actual =
        await vi.importActual<
            typeof import('../teacher/monitoringApi')
        >('../teacher/monitoringApi');

    return {
        ...actual,
        resolveTeacherMonitoringEnrollment: vi.fn().mockResolvedValue({
            id: 'enrollment-one',
            student: {
                user_id: 'student-one',
                name: 'Student',
            },
            teacher_subject_assignment: {
                id: 'assignment-one',
                status: 'active',
                subject: {
                    id: 'subject-one',
                    code: 'mathematics',
                    name: 'Mathematics',
                },
            },
            status: 'active',
            created_at: null,
            accepted_at: null,
            updated_at: null,
        }),
    };
});

async function renderAt(
    destination: string,
) {
    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
        },
    });

    render(
        <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
        </QueryClientProvider>,
    );

    await act(async () => {
        await router.navigate(destination);
    });
}

describe('teacher router foundation', () => {
    beforeEach(() => {
        authState = {
            status: 'authenticated',
            user: {
                id: 'teacher-1',
                name: 'Teacher',
                email: 'teacher@example.com',
                role: 'teacher',
                status: 'active',
                learner_profile_id: null,
            },
        };
    });

    afterEach(async () => {
        cleanup();

        await router.navigate('/');
    });

    it('redirects the teacher index to the workspace foundation', async () => {
        await renderAt('/portal/teacher');

        await waitFor(() => {
            expect(
                router.state.location.pathname,
            ).toBe('/portal/teacher/workspace');
        });

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'تأليف المحتوى',
            }),
        ).toBeInTheDocument();
    });

    it('renders a teacher scoped deep link through the context foundation', async () => {
        await renderAt(
            '/portal/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
        );

        expect(
            screen.getByTestId(
                'teacher-context-selector',
            ),
        ).toBeInTheDocument();
    });

    it('mounts teacher enrollment requests and active students under the teacher guard', async () => {
        await renderAt('/portal/teacher/enrollments');

        expect(
            await screen.findByRole('heading', {
                level: 1,
                name: 'طلبات الالتحاق',
            }),
        ).toBeInTheDocument();

        await act(async () => {
            await router.navigate('/portal/teacher/students');
        });

        expect(
            await screen.findByRole('heading', {
                level: 1,
                name: 'طلابي',
            }),
        ).toBeInTheDocument();
    });

    it('mounts monitoring overview and detail under the Teacher guard', async () => {
        await renderAt('/portal/teacher/monitoring');

        expect(
            await screen.findByRole('heading', {
                level: 1,
                name: 'متابعة الطلاب',
            }),
        ).toBeInTheDocument();

        await act(async () => {
            await router.navigate(
                '/portal/teacher/monitoring/enrollments/enrollment-one',
            );
        });

        await waitFor(() => {
            expect(screen.getByRole('heading', {
                level: 1,
                name: 'تفاصيل متابعة الطالب',
            })).toBeInTheDocument();
        });
    });

    it('keeps student accounts outside teacher routes', async () => {
        authState = {
            ...authState,
            user: {
                ...authState.user,
                role: 'student',
            },
        };

        await renderAt('/portal/teacher/monitoring');

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'غير مصرح لك بالوصول',
            }),
        ).toBeInTheDocument();
    });

    it('keeps admin accounts outside teacher routes', async () => {
        authState = {
            ...authState,
            user: {
                ...authState.user,
                role: 'admin',
            },
        };

        await renderAt(
            '/portal/teacher/monitoring/enrollments/enrollment-one?assignment_id=assignment-one',
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'غير مصرح لك بالوصول',
            }),
        ).toBeInTheDocument();
    });

    it('renders the canonical Student portal for a Student account', async () => {
        authState = {
            ...authState,
            user: {
                ...authState.user,
                role: 'student',
                learner_profile_id: 'learner-1',
            },
        };

        await renderAt('/portal/student');

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'مرحبًا بك في EduCore',
            }),
        ).toBeInTheDocument();
    });

    it.each([
        'teacher',
        'admin',
    ] as const)('keeps %s accounts outside Student routes', async (role) => {
        authState = {
            ...authState,
            user: {
                ...authState.user,
                role,
            },
        };

        await renderAt('/portal/student');

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'غير مصرح لك بالوصول',
            }),
        ).toBeInTheDocument();
    });

    it('applies the Student role guard after a legacy app redirect', async () => {
        await renderAt('/app');

        await waitFor(() => {
            expect(
                router.state.location.pathname,
            ).toBe('/forbidden');
        });

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'غير مصرح لك بالوصول',
            }),
        ).toBeInTheDocument();
    });

    it('normalizes a legacy Student deep link with replace while preserving URL context', async () => {
        authState = {
            ...authState,
            user: {
                ...authState.user,
                role: 'student',
                learner_profile_id: 'learner-1',
            },
        };

        await renderAt(
            '/app/lessons/lesson-a?mode=review#answer',
        );

        await waitFor(() => {
            expect(
                router.state.location.pathname,
            ).toBe(
                '/portal/student/lessons/lesson-a',
            );
        });

        expect(router.state.location.search)
            .toBe('?mode=review');
        expect(router.state.location.hash)
            .toBe('#answer');
        expect(router.state.historyAction)
            .toBe('REPLACE');
        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Canonical lesson',
            }),
        ).toBeInTheDocument();
    });
});

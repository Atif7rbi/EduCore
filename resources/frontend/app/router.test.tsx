import {
    act,
} from 'react';
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

async function renderAt(
    destination: string,
) {
    render(
        <RouterProvider router={router} />,
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
        await renderAt('/teacher');

        await waitFor(() => {
            expect(
                router.state.location.pathname,
            ).toBe('/teacher/workspace');
        });

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'تأليف المحتوى',
            }),
        ).toBeInTheDocument();
    });

    it('renders a teacher scoped deep link without context retrieval', async () => {
        await renderAt(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a?section=practice',
        );

        expect(
            screen.getByText(
                'سيُفتح قسم practice عند اكتمال مساحة التأليف.',
            ),
        ).toBeInTheDocument();
    });

    it('keeps student and admin accounts outside teacher routes', async () => {
        authState = {
            ...authState,
            user: {
                ...authState.user,
                role: 'student',
            },
        };

        await renderAt('/teacher/workspace');

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

        await renderAt('/teacher/workspace');

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'غير مصرح لك بالوصول',
            }),
        ).toBeInTheDocument();
    });
});

import {
    render,
    screen,
} from '@testing-library/react';
import {
    MemoryRouter,
    Route,
    Routes,
} from 'react-router-dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    RequireAuth,
    RequireRole,
} from './RouteGuards';

interface MockAuthState {
    status:
        | 'loading'
        | 'authenticated'
        | 'unauthenticated'
        | 'error';
    user: {
        id: string;
        name: string;
        email: string;
        role:
            | 'student'
            | 'teacher'
            | 'admin';
        status: string;
        learner_profile_id: string | null;
    } | null;
    error: Error | null;
}

let authState: MockAuthState;

vi.mock('./AuthProvider', () => ({
    useAuth: () => ({
        ...authState,
        login: vi.fn(),
        logout: vi.fn(),
        refresh: vi.fn(),
    }),
}));

function renderAuthRoute(
    initialEntry = '/app',
) {
    render(
        <MemoryRouter
            initialEntries={[initialEntry]}
        >
            <Routes>
                <Route
                    path="/login"
                    element={
                        <div>
                            Login Destination
                        </div>
                    }
                />

                <Route
                    path="/app"
                    element={
                        <RequireAuth>
                            <div>
                                Protected App
                            </div>
                        </RequireAuth>
                    }
                />
            </Routes>
        </MemoryRouter>,
    );
}

function renderAdminRoute() {
    render(
        <MemoryRouter
            initialEntries={['/admin']}
        >
            <Routes>
                <Route
                    path="/login"
                    element={
                        <div>
                            Login Destination
                        </div>
                    }
                />

                <Route
                    path="/forbidden"
                    element={
                        <div>
                            Forbidden Destination
                        </div>
                    }
                />

                <Route
                    path="/admin"
                    element={
                        <RequireRole
                            allowedRoles={[
                                'admin',
                            ]}
                        >
                            <div>
                                Admin Area
                            </div>
                        </RequireRole>
                    }
                />
            </Routes>
        </MemoryRouter>,
    );
}

describe('route guards', () => {
    beforeEach(() => {
        authState = {
            status: 'unauthenticated',
            user: null,
            error: null,
        };
    });

    it('redirects anonymous users to login', () => {
        renderAuthRoute();

        expect(
            screen.getByText(
                'Login Destination',
            ),
        ).toBeInTheDocument();
    });

    it('renders protected content for authenticated users', () => {
        authState = {
            status: 'authenticated',
            user: {
                id: 'student-1',
                name: 'Student',
                email: 'student@example.com',
                role: 'student',
                status: 'active',
                learner_profile_id: 'learner-1',
            },
            error: null,
        };

        renderAuthRoute();

        expect(
            screen.getByText(
                'Protected App',
            ),
        ).toBeInTheDocument();
    });

    it('shows loading state during auth bootstrap', () => {
        authState = {
            status: 'loading',
            user: null,
            error: null,
        };

        renderAuthRoute();

        expect(
            screen.getByRole('status'),
        ).toHaveTextContent(
            'جارٍ التحقق من صلاحية الوصول...',
        );
    });

    it('shows gate error when auth bootstrap fails', () => {
        authState = {
            status: 'error',
            user: null,
            error: new Error(
                'Service unavailable.',
            ),
        };

        renderAuthRoute();

        expect(
            screen.getByRole('alert'),
        ).toHaveTextContent(
            'تعذر التحقق من صلاحية الوصول.',
        );
    });

    it('allows admin users into admin routes', () => {
        authState = {
            status: 'authenticated',
            user: {
                id: 'admin-1',
                name: 'Admin',
                email: 'admin@example.com',
                role: 'admin',
                status: 'active',
                learner_profile_id: null,
            },
            error: null,
        };

        renderAdminRoute();

        expect(
            screen.getByText(
                'Admin Area',
            ),
        ).toBeInTheDocument();
    });

    it('blocks student users from admin routes', () => {
        authState = {
            status: 'authenticated',
            user: {
                id: 'student-1',
                name: 'Student',
                email: 'student@example.com',
                role: 'student',
                status: 'active',
                learner_profile_id: 'learner-1',
            },
            error: null,
        };

        renderAdminRoute();

        expect(
            screen.getByText(
                'Forbidden Destination',
            ),
        ).toBeInTheDocument();
    });

    it('blocks teacher users from admin routes', () => {
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
            error: null,
        };

        renderAdminRoute();

        expect(
            screen.getByText(
                'Forbidden Destination',
            ),
        ).toBeInTheDocument();
    });

    it('redirects anonymous users away from admin routes', () => {
        renderAdminRoute();

        expect(
            screen.getByText(
                'Login Destination',
            ),
        ).toBeInTheDocument();
    });

});

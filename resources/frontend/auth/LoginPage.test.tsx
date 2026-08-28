import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
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
    EduCoreApiError,
} from '../api/errors';

import {
    LoginPage,
} from './LoginPage';

const loginMock = vi.fn();
const refreshMock = vi.fn();
const navigateMock = vi.fn();

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
        role: 'student' | 'teacher' | 'admin';
        status: string;
        learner_profile_id: string | null;
    } | null;
    error: Error | null;
}

let authState: MockAuthState = {
    status: 'unauthenticated',
    user: null,
    error: null,
};

vi.mock('./AuthProvider', () => ({
    useAuth: () => ({
        ...authState,
        login: loginMock,
        refresh: refreshMock,
        logout: vi.fn(),
    }),
}));

vi.mock('react-router-dom', async () => {
    const actual =
        await vi.importActual<
            typeof import('react-router-dom')
        >('react-router-dom');

    return {
        ...actual,
        useNavigate: () => navigateMock,
    };
});

function renderPage(
    from?: string,
) {
    render(
        <MemoryRouter
            initialEntries={[
                {
                    pathname: '/login',
                    state:
                        from
                            ? { from }
                            : null,
                },
            ]}
        >
            <LoginPage />
        </MemoryRouter>,
    );
}

describe('LoginPage', () => {
    beforeEach(() => {
        loginMock.mockReset();
        refreshMock.mockReset();
        navigateMock.mockReset();

        authState = {
            status: 'unauthenticated',
            user: null,
            error: null,
        };
    });

    it('submits credentials and redirects learner users', async () => {
        loginMock.mockResolvedValueOnce({
            id: 'user-1',
            name: 'Learner',
            email: 'learner@example.com',
            role: 'student',
            status: 'active',
            learner_profile_id: 'learner-1',
        });

        renderPage();

        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'البريد الإلكتروني',
            }),
            {
                target: {
                    value: 'learner@example.com',
                },
            },
        );

        fireEvent.change(
            screen.getByLabelText(
                'كلمة المرور',
            ),
            {
                target: {
                    value: 'secret',
                },
            },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(loginMock).toHaveBeenCalledWith({
                email: 'learner@example.com',
                password: 'secret',
            });
        });

        expect(navigateMock).toHaveBeenCalledWith(
            '/app',
            {
                replace: true,
            },
        );

        expect(navigateMock).toHaveBeenCalledTimes(1);
    });

    it('redirects admin users to admin shell', async () => {
        loginMock.mockResolvedValueOnce({
            id: 'admin-1',
            name: 'Admin',
            email: 'admin@example.com',
            role: 'admin',
            status: 'active',
            learner_profile_id: null,
        });

        renderPage();

        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'البريد الإلكتروني',
            }),
            {
                target: {
                    value: 'admin@example.com',
                },
            },
        );

        fireEvent.change(
            screen.getByLabelText(
                'كلمة المرور',
            ),
            {
                target: {
                    value: 'secret',
                },
            },
        );

        fireEvent.submit(
            screen
                .getByRole('button', {
                    name: 'تسجيل الدخول',
                })
                .closest('form')!,
        );

        await waitFor(() => {
            expect(navigateMock).toHaveBeenCalledWith(
                '/admin',
                {
                    replace: true,
                },
            );
        });

        expect(navigateMock).toHaveBeenCalledTimes(1);
    });

    it('renders validation details from the API', async () => {
        loginMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'validation_error',
                message: 'البيانات المدخلة غير صالحة.',
                status: 422,
                details: {
                    email: [
                        'البريد الإلكتروني مطلوب.',
                    ],
                    password: [
                        'كلمة المرور مطلوبة.',
                    ],
                },
                requestId: 'request-422',
            }),
        );

        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByText(
                    'البريد الإلكتروني مطلوب.',
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByText(
                'كلمة المرور مطلوبة.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /request-422/,
            ),
        ).toBeInTheDocument();
    });

    it('shows session bootstrap loading state', () => {
        authState = {
            status: 'loading',
            user: null,
            error: null,
        };

        renderPage();

        expect(
            screen.getByRole('status'),
        ).toHaveTextContent(
            'جارٍ التحقق من حالة الجلسة...',
        );
    });

    it('offers retry after bootstrap failure', () => {
        authState = {
            status: 'error',
            user: null,
            error: new Error('Service unavailable.'),
        };

        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إعادة المحاولة',
            }),
        );

        expect(refreshMock).toHaveBeenCalledOnce();
    });

    it('shows invalid credentials returned as 401', async () => {
        loginMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'invalid_credentials',
                message: 'بيانات تسجيل الدخول غير صحيحة.',
                status: 401,
                requestId: 'request-401',
            }),
        );

        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByRole('alert'),
            ).toHaveTextContent(
                'بيانات تسجيل الدخول غير صحيحة.',
            );
        });

        expect(
            screen.getByText(/request-401/),
        ).toBeInTheDocument();
    });

    it('shows csrf session failure returned as 419', async () => {
        loginMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'csrf_token_mismatch',
                message: 'انتهت صلاحية الجلسة. أعد المحاولة.',
                status: 419,
                requestId: 'request-419',
            }),
        );

        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByRole('alert'),
            ).toHaveTextContent(
                'انتهت صلاحية الجلسة. أعد المحاولة.',
            );
        });

        expect(
            screen.getByText(/request-419/),
        ).toBeInTheDocument();
    });

    it('redirects an already authenticated user during bootstrap', async () => {
        authState = {
            status: 'authenticated',
            user: {
                id: 'admin-2',
                name: 'Admin',
                email: 'admin@example.com',
                role: 'admin',
                status: 'active',
                learner_profile_id: null,
            },
            error: null,
        };

        renderPage();

        await waitFor(() => {
            expect(navigateMock).toHaveBeenCalledWith(
                '/admin',
                {
                    replace: true,
                },
            );
        });

        expect(loginMock).not.toHaveBeenCalled();
    });


    it('returns authenticated users to a requested app route', async () => {
        loginMock.mockResolvedValueOnce({
            id: 'user-2',
            name: 'Learner',
            email: 'learner@example.com',
            role: 'student',
            status: 'active',
            learner_profile_id: 'learner-2',
        });

        renderPage('/app/lessons/123');

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(navigateMock).toHaveBeenCalledWith(
                '/app/lessons/123',
                {
                    replace: true,
                },
            );
        });
    });

    it('returns admin users to a requested admin route', async () => {
        loginMock.mockResolvedValueOnce({
            id: 'admin-3',
            name: 'Admin',
            email: 'admin@example.com',
            role: 'admin',
            status: 'active',
            learner_profile_id: null,
        });

        renderPage('/admin/curricula');

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(navigateMock).toHaveBeenCalledWith(
                '/admin/curricula',
                {
                    replace: true,
                },
            );
        });
    });

    it('does not return a student user to an admin route', async () => {
        loginMock.mockResolvedValueOnce({
            id: 'student-3',
            name: 'Student',
            email: 'student@example.com',
            role: 'student',
            status: 'active',
            learner_profile_id: 'learner-3',
        });

        renderPage('/admin/curricula');

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(navigateMock).toHaveBeenCalledWith(
                '/app',
                {
                    replace: true,
                },
            );
        });
    });


    it('preserves query and hash on an app return route', async () => {
        loginMock.mockResolvedValueOnce({
            id: 'user-4',
            name: 'Learner',
            email: 'learner@example.com',
            role: 'student',
            status: 'active',
            learner_profile_id: 'learner-4',
        });

        renderPage(
            '/app?page=2#progress',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        );

        await waitFor(() => {
            expect(navigateMock).toHaveBeenCalledWith(
                '/app?page=2#progress',
                {
                    replace: true,
                },
            );
        });
    });

});

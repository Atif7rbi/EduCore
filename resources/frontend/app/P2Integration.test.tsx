import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
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
    EduCoreApiError,
} from '../api/errors';
import {
    emitSessionFailure,
} from '../api/sessionEvents';
import {
    AuthProvider,
} from '../auth/AuthProvider';
import {
    LoginPage,
} from '../auth/LoginPage';
import {
    RequireAuth,
    RequireRole,
} from '../auth/RouteGuards';

import {
    AdminProductShell,
    LearnerProductShell,
} from './App';

interface TestRequestConfig {
    method?: string;
    url?: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (
        config: TestRequestConfig,
    ) => apiRequestMock(config),
}));

function userPayload(
    role: 'student' | 'teacher' | 'admin',
    name = 'مستخدم EduCore',
) {
    return {
        user: {
            id: `user-${role}`,
            name,
            email: `${role}@example.com`,
            role,
            status: 'active',
            learner_profile_id:
                role === 'student'
                    ? 'learner-1'
                    : null,
        },
    };
}

function apiError(
    status: number,
    requestId: string,
) {
    return new EduCoreApiError({
        code:
            status === 401
                ? 'unauthenticated'
                : 'internal_error',
        message:
            status === 401
                ? 'Unauthenticated.'
                : 'Service unavailable.',
        status,
        requestId,
    });
}

function IntegrationRoutes() {
    return (
        <Routes>
            <Route
                path="/login"
                element={<LoginPage />}
            />

            <Route
                path="/app"
                element={
                    <RequireAuth>
                        <LearnerProductShell />
                    </RequireAuth>
                }
            >
                <Route
                    index
                    element={
                        <h1>
                            Learner Home Integration
                        </h1>
                    }
                />

                <Route
                    path="practice"
                    element={
                        <h1>
                            Practice Integration
                        </h1>
                    }
                />
            </Route>

            <Route
                path="/admin"
                element={
                    <RequireRole
                        allowedRoles={[
                            'admin',
                        ]}
                    >
                        <AdminProductShell />
                    </RequireRole>
                }
            >
                <Route
                    index
                    element={
                        <h1>
                            Admin Home Integration
                        </h1>
                    }
                />
            </Route>

            <Route
                path="/forbidden"
                element={
                    <RequireAuth>
                        <h1>
                            Forbidden Integration
                        </h1>
                    </RequireAuth>
                }
            />
        </Routes>
    );
}

function renderIntegration(
    initialEntry: string,
) {
    render(
        <AuthProvider>
            <MemoryRouter
                initialEntries={[
                    initialEntry,
                ]}
            >
                <IntegrationRoutes />
            </MemoryRouter>
        </AuthProvider>,
    );
}

describe('P2 authentication and product-shell integration', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('returns an anonymous learner to the requested protected route after login', async () => {
        apiRequestMock.mockImplementation(
            async (
                config: TestRequestConfig,
            ) => {
                if (
                    config.method === 'GET' &&
                    config.url === '/auth/me'
                ) {
                    throw apiError(
                        401,
                        'bootstrap-401',
                    );
                }

                if (
                    config.method === 'POST' &&
                    config.url === '/auth/login'
                ) {
                    return userPayload(
                        'student',
                        'أحمد المتعلم',
                    );
                }

                throw new Error(
                    `Unexpected request: ${config.method} ${config.url}`,
                );
            },
        );

        renderIntegration(
            '/app/practice?mode=guided#question-2',
        );

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        level: 1,
                        name: 'تسجيل الدخول',
                    },
                ),
            ).toBeInTheDocument();
        });

        fireEvent.change(
            screen.getByRole(
                'textbox',
                {
                    name: 'البريد الإلكتروني',
                },
            ),
            {
                target: {
                    value:
                        'student@example.com',
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
            screen.getByRole(
                'button',
                {
                    name: 'تسجيل الدخول',
                },
            ),
        );

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Practice Integration',
                    },
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByText(
                'أحمد المتعلم',
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole(
                'link',
                {
                    name: 'الممارسة',
                },
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'المناهج',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/app/curriculum',
        );
    });

    it('moves an authenticated learner to login after runtime session expiry', async () => {
        apiRequestMock.mockResolvedValueOnce(
            userPayload(
                'student',
                'سارة المتعلمة',
            ),
        );

        renderIntegration(
            '/app/practice?mode=timed#question-3',
        );

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Practice Integration',
                    },
                ),
            ).toBeInTheDocument();
        });

        act(() => {
            emitSessionFailure({
                kind: 'expired',
                requestId:
                    'integration-401',
            });
        });

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        level: 1,
                        name: 'تسجيل الدخول',
                    },
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByRole('status'),
        ).toHaveTextContent(
            'انتهت صلاحية جلستك.',
        );

        expect(
            screen.getByText(
                /integration-401/,
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByText(
                'Practice Integration',
            ),
        ).not.toBeInTheDocument();
    });

    it('keeps the admin shell role-gated and recovers from csrf session loss', async () => {
        apiRequestMock.mockResolvedValueOnce(
            userPayload(
                'admin',
                'مدير EduCore',
            ),
        );

        renderIntegration(
            '/admin',
        );

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Admin Home Integration',
                    },
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByText(
                'مدير EduCore',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'المحتوى',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/admin/content',
        );

        expect(
            screen.queryByRole(
                'link',
                {
                    name: 'بنك الأسئلة',
                },
            ),
        ).not.toBeInTheDocument();

        act(() => {
            emitSessionFailure({
                kind: 'csrf',
                requestId:
                    'integration-419',
            });
        });

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        level: 1,
                        name: 'تسجيل الدخول',
                    },
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByRole('status'),
        ).toHaveTextContent(
            'انتهت صلاحية حماية الجلسة.',
        );

        expect(
            screen.getByText(
                /integration-419/,
            ),
        ).toBeInTheDocument();
    });

    it('does not expose the admin shell to an authenticated student', async () => {
        apiRequestMock.mockResolvedValueOnce(
            userPayload(
                'student',
                'طالب EduCore',
            ),
        );

        renderIntegration(
            '/admin',
        );

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Forbidden Integration',
                    },
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.queryByText(
                'Admin Home Integration',
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByRole(
                'link',
                {
                    name: 'بنك الأسئلة',
                },
            ),
        ).not.toBeInTheDocument();
    });

    it('recovers a protected route from bootstrap failure without leaking product content', async () => {
        let authMeCalls = 0;

        apiRequestMock.mockImplementation(
            async (
                config: TestRequestConfig,
            ) => {
                if (
                    config.method === 'GET' &&
                    config.url === '/auth/me'
                ) {
                    authMeCalls += 1;

                    if (authMeCalls === 1) {
                        throw apiError(
                            500,
                            'bootstrap-500',
                        );
                    }

                    return userPayload(
                        'student',
                        'متعلم مستعاد',
                    );
                }

                throw new Error(
                    `Unexpected request: ${config.method} ${config.url}`,
                );
            },
        );

        renderIntegration('/app');

        await waitFor(() => {
            expect(
                screen.getByText(
                    'تعذر التحقق من صلاحية الوصول.',
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.queryByText(
                'Learner Home Integration',
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText(
                /bootstrap-500/,
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name: 'إعادة المحاولة',
                },
            ),
        );

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'heading',
                    {
                        name:
                            'Learner Home Integration',
                    },
                ),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByText(
                'متعلم مستعاد',
            ),
        ).toBeInTheDocument();

        expect(authMeCalls).toBe(2);
    });
});

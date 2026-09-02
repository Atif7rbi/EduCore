import {
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
    Navigate,
    Route,
    Routes,
    useLocation,
} from 'react-router-dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    AdminProductShell,
    LearnerProductShell,
} from './App';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (
        config: unknown,
    ) => apiRequestMock(config),
}));

vi.mock('../auth/AuthProvider', () => ({
    useAuth: () => ({
        status: 'authenticated',
        user: {
            id: 'user-1',
            name: 'مستخدم EduCore',
            email: 'user@example.com',
            role: 'admin',
            status: 'active',
            learner_profile_id:
                'learner-profile-1',
        },
        error: null,
        refresh: vi.fn(),
        login: vi.fn(),
        logout: vi.fn(),
    }),
}));

function queryClient() {
    return new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
            mutations: {
                retry: false,
            },
        },
    });
}

function LocationProbe() {
    const location = useLocation();

    return (
        <div data-testid="location">
            {location.pathname}
        </div>
    );
}

function renderRoutes(
    initialEntry: string,
) {
    render(
        <QueryClientProvider
            client={queryClient()}
        >
            <MemoryRouter
                initialEntries={[
                    initialEntry,
                ]}
            >
                <Routes>
                    <Route
                        path="/app"
                        element={
                            <LearnerProductShell />
                        }
                    >
                        <Route
                            index
                            element={
                                <h1>
                                    Learner Home
                                </h1>
                            }
                        />

                        <Route
                            path="curriculum"
                            element={
                                <h1>
                                    Curriculum Surface
                                </h1>
                            }
                        />

                        <Route
                            path="exams"
                            element={
                                <h1>
                                    Exams Surface
                                </h1>
                            }
                        />

                        <Route
                            path="results"
                            element={
                                <h1>
                                    Results Surface
                                </h1>
                            }
                        />

                        <Route
                            path="progress"
                            element={
                                <h1>
                                    Progress Surface
                                </h1>
                            }
                        />

                        <Route
                            path="practice"
                            element={
                                <Navigate
                                    replace
                                    to="/app/curriculum"
                                />
                            }
                        />
                    </Route>

                    <Route
                        path="/admin"
                        element={
                            <AdminProductShell />
                        }
                    >
                        <Route
                            index
                            element={
                                <h1>
                                    Admin Home
                                </h1>
                            }
                        />

                        <Route
                            path="curricula"
                            element={
                                <h1>
                                    Admin Curricula
                                </h1>
                            }
                        />

                        <Route
                            path="content"
                            element={
                                <h1>
                                    Admin Content
                                </h1>
                            }
                        />

                        <Route
                            path="assessment-items"
                            element={
                                <Navigate
                                    replace
                                    to="/admin/content"
                                />
                            }
                        />

                        <Route
                            path="exam-templates"
                            element={
                                <Navigate
                                    replace
                                    to="/admin/content"
                                />
                            }
                        />
                    </Route>

                    <Route
                        path="*"
                        element={
                            <LocationProbe />
                        }
                    />
                </Routes>

                <LocationProbe />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe(
    'P6 MVP product integration',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'exposes only real learner MVP navigation surfaces',
            () => {
                renderRoutes('/app');

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

                expect(
                    screen.getByRole(
                        'link',
                        {
                            name: 'الاختبارات',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '/app/exams',
                );

                expect(
                    screen.getByRole(
                        'link',
                        {
                            name: 'النتائج',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '/app/results',
                );

                expect(
                    screen.getByRole(
                        'link',
                        {
                            name: 'التقدم',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '/app/progress',
                );

                expect(
                    screen.queryByRole(
                        'link',
                        {
                            name: 'الممارسة',
                        },
                    ),
                ).not.toBeInTheDocument();
            },
        );

        it(
            'exposes the unified admin MVP navigation surfaces',
            () => {
                renderRoutes('/admin');

                expect(
                    screen.getByRole(
                        'link',
                        {
                            name: 'المناهج',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '/admin/curricula',
                );

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

                expect(
                    screen.queryByRole(
                        'link',
                        {
                            name:
                                'قوالب الاختبارات',
                        },
                    ),
                ).not.toBeInTheDocument();
            },
        );

        it.each([
            [
                '/app/practice',
                '/app/curriculum',
                'Curriculum Surface',
            ],
            [
                '/admin/assessment-items',
                '/admin/content',
                'Admin Content',
            ],
            [
                '/admin/exam-templates',
                '/admin/content',
                'Admin Content',
            ],
        ])(
            'redirects %s to its real MVP surface',
            async (
                initialEntry,
                expectedPath,
                expectedHeading,
            ) => {
                renderRoutes(initialEntry);

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                expectedHeading,
                        },
                    ),
                ).toBeInTheDocument();

                await waitFor(() => {
                    expect(
                        screen.getByTestId(
                            'location',
                        ),
                    ).toHaveTextContent(
                        expectedPath,
                    );
                });
            },
        );
    },
);

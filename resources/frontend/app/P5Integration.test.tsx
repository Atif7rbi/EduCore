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
    ProgressPage,
} from '../learner/ProgressPage';

interface TestRequest {
    method: string;
    url: string;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (
        config: TestRequest,
    ) => apiRequestMock(config),
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

function renderProgressRoute() {
    render(
        <QueryClientProvider
            client={queryClient()}
        >
            <MemoryRouter
                initialEntries={[
                    '/app/progress',
                ]}
            >
                <Routes>
                    <Route
                        path="/app/progress"
                        element={
                            <ProgressPage />
                        }
                    />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

function progressFixture() {
    return {
        learning: {
            started_lessons_count: 8,
            completed_lessons_count: 5,
        },
        assessment: {
            attempts_total: 12,
            submitted_attempts: 7,
            abandoned_attempts: 2,
            in_progress_attempts: 3,
        },
    };
}

function scopesFixture() {
    return [
        {
            id: 'scope-active',
            label: 'التحليل الحالي',
            description:
                'النطاق التحليلي الحالي',
            status: 'active',
            definition_schema_version: 2,
        },
        {
            id: 'scope-history',
            label: 'التحليل السابق',
            description:
                'نطاق تحليلي تاريخي',
            status: 'retired',
            definition_schema_version: 1,
        },
    ];
}

function activeAnalyticsFixture() {
    return {
        evidence_scope: {
            id: 'scope-active',
            label: 'التحليل الحالي',
            status: 'active',
            definition_schema_version: 2,
        },
        temporal_boundary: 'lifetime',
        skills: [
            {
                skill: {
                    id: 'skill-ratios',
                    name: 'النسب',
                    description:
                        'الاستدلال باستخدام النسب',
                },
                single_primary: {
                    correct_count: 6,
                    answered_count: 8,
                    accuracy: 0.75,
                },
                supporting: {
                    positive_count: 3,
                    exposure_count: 5,
                },
                last_rebuilt_at:
                    '2026-09-01T00:00:00Z',
            },
        ],
    };
}

function historicalAnalyticsFixture() {
    return {
        evidence_scope: {
            id: 'scope-history',
            label: 'التحليل السابق',
            status: 'retired',
            definition_schema_version: 1,
        },
        temporal_boundary: 'lifetime',
        skills: [
            {
                skill: {
                    id: 'skill-algebra',
                    name: 'الجبر',
                    description:
                        'أداء تاريخي في الجبر',
                },
                single_primary: {
                    correct_count: 2,
                    answered_count: 4,
                    accuracy: 0.5,
                },
                supporting: {
                    positive_count: 1,
                    exposure_count: 3,
                },
                last_rebuilt_at:
                    '2026-08-20T00:00:00Z',
            },
        ],
    };
}

describe(
    'P5 progress and analytics integration',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'loads progress and active skill analytics then switches to historical analytics',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/progress/overview'
                        ) {
                            return Promise.resolve(
                                progressFixture(),
                            );
                        }

                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve(
                                scopesFixture(),
                            );
                        }

                        if (
                            url
                            === '/api/analytics/skills?evidence_scope_id=scope-active'
                        ) {
                            return Promise.resolve(
                                activeAnalyticsFixture(),
                            );
                        }

                        if (
                            url
                            === '/api/analytics/skills?evidence_scope_id=scope-history'
                        ) {
                            return Promise.resolve(
                                historicalAnalyticsFixture(),
                            );
                        }

                        throw new Error(
                            `unexpected URL: ${url}`,
                        );
                    },
                );

                renderProgressRoute();

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'التقدم والتحليلات',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByLabelText(
                        'ملخص تقدم الدروس',
                    ),
                ).toHaveTextContent(
                    '8دروس بدأت',
                );

                expect(
                    screen.getByLabelText(
                        'ملخص تقدم الدروس',
                    ),
                ).toHaveTextContent(
                    '5دروس مكتملة',
                );

                const attempts =
                    screen.getByLabelText(
                        'ملخص المحاولات',
                    );

                expect(
                    attempts,
                ).toHaveTextContent(
                    '12إجمالي المحاولات',
                );

                expect(
                    attempts,
                ).toHaveTextContent(
                    '7مسلّمة',
                );

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name: 'النسب',
                        },
                    ),
                ).toBeInTheDocument();

                const selector =
                    screen.getByLabelText(
                        'نطاق التحليل',
                    );

                expect(
                    selector,
                ).toHaveValue(
                    'scope-active',
                );

                fireEvent.change(
                    selector,
                    {
                        target: {
                            value:
                                'scope-history',
                        },
                    },
                );

                expect(
                    await screen.findByText(
                        'هذا نطاق تحليلي تاريخي ومتاح للقراءة.',
                    ),
                ).toBeInTheDocument();

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name: 'الجبر',
                        },
                    ),
                ).toBeInTheDocument();

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'GET',
                        url:
                            '/api/analytics/skills?evidence_scope_id=scope-history',
                    });
                });
            },
        );

        it(
            'keeps progress useful when no analytics scopes exist',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/progress/overview'
                        ) {
                            return Promise.resolve(
                                progressFixture(),
                            );
                        }

                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve(
                                [],
                            );
                        }

                        throw new Error(
                            `unexpected URL: ${url}`,
                        );
                    },
                );

                renderProgressRoute();

                expect(
                    await screen.findByLabelText(
                        'ملخص تقدم الدروس',
                    ),
                ).toHaveTextContent(
                    '5دروس مكتملة',
                );

                expect(
                    await screen.findByText(
                        'لا توجد نطاقات تحليلية متاحة حاليًا.',
                    ),
                ).toBeInTheDocument();

                expect(
                    apiRequestMock.mock.calls.some(
                        ([request]) =>
                            (
                                request as TestRequest
                            ).url.startsWith(
                                '/api/analytics/skills',
                            ),
                    ),
                ).toBe(false);
            },
        );

        it(
            'isolates analytics failure from progress and recovers the scope inventory',
            async () => {
                let scopeRequests = 0;

                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/progress/overview'
                        ) {
                            return Promise.resolve(
                                progressFixture(),
                            );
                        }

                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            scopeRequests += 1;

                            if (
                                scopeRequests
                                === 1
                            ) {
                                return Promise.reject(
                                    new Error(
                                        'scope-inventory-failed',
                                    ),
                                );
                            }

                            return Promise.resolve(
                                [],
                            );
                        }

                        throw new Error(
                            `unexpected URL: ${url}`,
                        );
                    },
                );

                renderProgressRoute();

                expect(
                    await screen.findByLabelText(
                        'ملخص المحاولات',
                    ),
                ).toHaveTextContent(
                    '12إجمالي المحاولات',
                );

                expect(
                    await screen.findByText(
                        'تعذر تحميل نطاقات التحليل.',
                    ),
                ).toBeInTheDocument();

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إعادة المحاولة',
                        },
                    ),
                );

                expect(
                    await screen.findByText(
                        'لا توجد نطاقات تحليلية متاحة حاليًا.',
                    ),
                ).toBeInTheDocument();

                expect(
                    scopeRequests,
                ).toBe(2);
            },
        );
    },
);

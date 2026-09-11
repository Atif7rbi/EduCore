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
    ProgressPage,
} from './ProgressPage';

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

function renderPage() {
    const client =
        new QueryClient({
            defaultOptions: {
                queries: {
                    retry: false,
                },
            },
        });

    render(
        <QueryClientProvider client={client}>
            <ProgressPage />
        </QueryClientProvider>,
    );
}

describe(
    'ProgressPage',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'renders the exact progress overview counts from the backend',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/progress/overview'
                        ) {
                            return Promise.resolve({
                                learning: {
                                    started_lessons_count: 7,
                                    completed_lessons_count: 4,
                                },
                                assessment: {
                                    attempts_total: 9,
                                    submitted_attempts: 5,
                                    abandoned_attempts: 1,
                                    in_progress_attempts: 3,
                                },
                            });
                        }

                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve([]);
                        }

                        throw new Error(
                            `unexpected URL: ${url}`,
                        );
                    },
                );

                renderPage();

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
                    '7دروس بدأت',
                );

                expect(
                    screen.getByLabelText(
                        'ملخص تقدم الدروس',
                    ),
                ).toHaveTextContent(
                    '4دروس مكتملة',
                );

                const attempts =
                    screen.getByLabelText(
                        'ملخص المحاولات',
                    );

                expect(
                    attempts,
                ).toHaveTextContent(
                    '9إجمالي المحاولات',
                );

                expect(
                    attempts,
                ).toHaveTextContent(
                    '5مسلّمة',
                );

                expect(
                    attempts,
                ).toHaveTextContent(
                    '3قيد التقدم',
                );

                expect(
                    attempts,
                ).toHaveTextContent(
                    '1منتهية دون تسليم',
                );

                expect(
                    apiRequestMock,
                ).toHaveBeenCalledWith({
                    method: 'GET',
                    url:
                        '/api/progress/overview',
                });
            },
        );

        it(
            'renders explicit zero counts for an empty learner',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/progress/overview'
                        ) {
                            return Promise.resolve({
                                learning: {
                                    started_lessons_count: 0,
                                    completed_lessons_count: 0,
                                },
                                assessment: {
                                    attempts_total: 0,
                                    submitted_attempts: 0,
                                    abandoned_attempts: 0,
                                    in_progress_attempts: 0,
                                },
                            });
                        }

                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve([]);
                        }

                        throw new Error(
                            `unexpected URL: ${url}`,
                        );
                    },
                );

                renderPage();

                await screen.findByRole(
                    'heading',
                    {
                        name:
                            'التقدم والتحليلات',
                    },
                );

                const metrics =
                    screen.getAllByText(
                        '0',
                    );

                expect(
                    metrics,
                ).toHaveLength(6);
            },
        );

        it(
            'does not invent mastery scores or derived percentages',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/progress/overview'
                        ) {
                            return Promise.resolve({
                                learning: {
                                    started_lessons_count: 8,
                                    completed_lessons_count: 4,
                                },
                                assessment: {
                                    attempts_total: 10,
                                    submitted_attempts: 7,
                                    abandoned_attempts: 1,
                                    in_progress_attempts: 2,
                                },
                            });
                        }

                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve([]);
                        }

                        throw new Error(
                            `unexpected URL: ${url}`,
                        );
                    },
                );

                renderPage();

                await screen.findByRole(
                    'heading',
                    {
                        name:
                            'التقدم والتحليلات',
                    },
                );

                expect(
                    screen.queryByText(
                        /إتقان|mastery/i,
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByText(
                        /%/,
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByText(
                        /متوسط.*درجة|درجة.*متوسط/,
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );

        it(
            'shows a recoverable error and retries the overview request',
            async () => {
                let requests = 0;

                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve([]);
                        }

                        if (
                            url
                            !== '/api/progress/overview'
                        ) {
                            throw new Error(
                                `unexpected URL: ${url}`,
                            );
                        }

                        requests += 1;

                        if (requests === 1) {
                            return Promise.reject(
                                new Error(
                                    'progress-request-failed',
                                ),
                            );
                        }

                        return Promise.resolve({
                            learning: {
                                started_lessons_count:
                                    1,
                                completed_lessons_count:
                                    1,
                            },
                            assessment: {
                                attempts_total:
                                    2,
                                submitted_attempts:
                                    1,
                                abandoned_attempts:
                                    0,
                                in_progress_attempts:
                                    1,
                            },
                        });
                    },
                );

                renderPage();

                expect(
                    await screen.findByText(
                        'تعذر تحميل ملخص التقدم.',
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

                await waitFor(() => {
                    expect(
                        requests,
                    ).toBe(2);
                });

                expect(
                    await screen.findByLabelText(
                        'ملخص تقدم الدروس',
                    ),
                ).toHaveTextContent(
                    '1دروس مكتملة',
                );
            },
        );
    },
);

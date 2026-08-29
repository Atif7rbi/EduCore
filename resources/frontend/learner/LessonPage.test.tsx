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
    EduCoreApiError,
} from '../api/errors';

import {
    LessonPage,
} from './LessonPage';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (...args: unknown[]) =>
        apiRequestMock(...args),
}));

function renderPage() {
    const queryClient = new QueryClient({
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
        <QueryClientProvider client={queryClient}>
            <MemoryRouter
                initialEntries={[
                    '/app/lessons/lesson-1',
                ]}
            >
                <Routes>
                    <Route
                        path="/app/lessons/:lessonId"
                        element={<LessonPage />}
                    />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

function lessonFixture() {
    return {
        id: 'lesson-1',
        curriculum_version_id:
            'version-1',
        title: 'درس النسب',
        description:
            'تعلم أساسيات النسب.',
        status: 'published',
        display_order: 1,
        published_revision: {
            id: 'revision-1',
            revision_number: 3,
            primary_topic_id:
                'topic-1',
            content_payload: {
                blocks: [
                    {
                        type: 'text',
                        value:
                            'النسبة تقارن بين مقدارين.',
                    },
                    {
                        type: 'text',
                        value:
                            'يمكن تبسيط النسبة بالقسمة.',
                    },
                ],
            },
            content_schema_version: 1,
            released_at:
                '2026-08-29T00:00:00Z',
        },
        practice_activities: [
            {
                id: 'practice-1',
                name: 'تدريب النسب',
                description:
                    'أسئلة تدريبية.',
                status: 'active',
            },
        ],
    };
}

function progressFixture(
    status:
        | 'in_progress'
        | 'completed',
) {
    return {
        id: 'progress-1',
        lesson_revision_id:
            'revision-1',
        status,
        started_at:
            '2026-08-29T00:00:00Z',
        completed_at:
            status === 'completed'
                ? '2026-08-29T00:10:00Z'
                : null,
    };
}

function installDefaultRequests(
    progress: ReturnType<
        typeof progressFixture
    > | null = null,
) {
    apiRequestMock.mockImplementation(
        ({
            method,
            url,
        }: {
            method: string;
            url: string;
        }) => {
            if (
                method === 'GET'
                && url
                    === '/api/lessons/lesson-1'
            ) {
                return Promise.resolve(
                    lessonFixture(),
                );
            }

            if (
                method === 'GET'
                && url
                    === '/api/lessons/lesson-1/progress'
            ) {
                return Promise.resolve(
                    progress,
                );
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe('LessonPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('renders the published revision and not-started progress state', async () => {
        installDefaultRequests(null);

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'درس النسب',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'النسبة تقارن بين مقدارين.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'يمكن تبسيط النسبة بالقسمة.',
            ),
        ).toBeInTheDocument();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'لم يبدأ',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole(
                'button',
                {
                    name: 'ابدأ الدرس',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name:
                    /تدريب النسب.*فتح الممارسة/s,
            }),
        ).toHaveAttribute(
            'href',
            '/app/practice/practice-1',
        );

        expect(
            screen.getByRole('link', {
                name:
                    'العودة إلى المنهج',
            }),
        ).toHaveAttribute(
            'href',
            '/app/curriculum/version-1',
        );
    });

    it('starts lesson progress and updates the visible state', async () => {
        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: {
                method: string;
                url: string;
            }) => {
                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1'
                ) {
                    return Promise.resolve(
                        lessonFixture(),
                    );
                }

                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1/progress'
                ) {
                    return Promise.resolve(
                        null,
                    );
                }

                if (
                    method === 'POST'
                    && url
                        === '/api/lessons/lesson-1/progress'
                ) {
                    return Promise.resolve(
                        progressFixture(
                            'in_progress',
                        ),
                    );
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPage();

        fireEvent.click(
            await screen.findByRole(
                'button',
                {
                    name: 'ابدأ الدرس',
                },
            ),
        );

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'قيد التقدم',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole(
                'button',
                {
                    name: 'إكمال الدرس',
                },
            ),
        ).toBeInTheDocument();

        expect(
            apiRequestMock,
        ).toHaveBeenCalledWith({
            method: 'POST',
            url:
                '/api/lessons/lesson-1/progress',
            data: {},
        });
    });

    it('completes in-progress lesson and shows completed state', async () => {
        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: {
                method: string;
                url: string;
            }) => {
                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1'
                ) {
                    return Promise.resolve(
                        lessonFixture(),
                    );
                }

                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1/progress'
                ) {
                    return Promise.resolve(
                        progressFixture(
                            'in_progress',
                        ),
                    );
                }

                if (
                    method === 'POST'
                    && url
                        === '/api/lessons/lesson-1/complete'
                ) {
                    return Promise.resolve(
                        progressFixture(
                            'completed',
                        ),
                    );
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPage();

        fireEvent.click(
            await screen.findByRole(
                'button',
                {
                    name: 'إكمال الدرس',
                },
            ),
        );

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'مكتمل',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'تم تسجيل إكمال النسخة المنشورة الحالية من الدرس.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole(
                'button',
                {
                    name: 'إكمال الدرس',
                },
            ),
        ).not.toBeInTheDocument();
    });

    it('keeps lesson readable when progress loading fails and can retry', async () => {
        let progressRequests = 0;

        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: {
                method: string;
                url: string;
            }) => {
                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1'
                ) {
                    return Promise.resolve(
                        lessonFixture(),
                    );
                }

                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1/progress'
                ) {
                    progressRequests += 1;

                    if (
                        progressRequests === 1
                    ) {
                        return Promise.reject(
                            new EduCoreApiError({
                                code:
                                    'internal_error',
                                message:
                                    'Failure.',
                                status: 500,
                                requestId:
                                    'progress-request-1',
                            }),
                        );
                    }

                    return Promise.resolve(
                        progressFixture(
                            'in_progress',
                        ),
                    );
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'درس النسب',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'النسبة تقارن بين مقدارين.',
            ),
        ).toBeInTheDocument();

        expect(
            await screen.findByText(
                /progress-request-1/,
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

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'قيد التقدم',
                },
            ),
        ).toBeInTheDocument();

        expect(
            progressRequests,
        ).toBe(2);
    });

    it('does not invent rendering for unsupported payloads', async () => {
        const baseLesson =
            lessonFixture();

        const lesson = {
            ...baseLesson,
            published_revision: {
                ...baseLesson
                    .published_revision,
                content_payload: {
                    widgets: [
                        {
                            kind:
                                'unknown',
                        },
                    ],
                },
            },
        };

        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: {
                method: string;
                url: string;
            }) => {
                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1'
                ) {
                    return Promise.resolve(
                        lesson,
                    );
                }

                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1/progress'
                ) {
                    return Promise.resolve(
                        null,
                    );
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPage();

        expect(
            await screen.findByText(
                'محتوى هذا الدرس يستخدم تنسيقًا لم يتم عرضه بعد في واجهة المتعلم.',
            ),
        ).toBeInTheDocument();
    });

    it('shows request id and retries a failed lesson request', async () => {
        let lessonRequests = 0;

        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: {
                method: string;
                url: string;
            }) => {
                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1'
                ) {
                    lessonRequests += 1;

                    if (
                        lessonRequests === 1
                    ) {
                        return Promise.reject(
                            new EduCoreApiError({
                                code:
                                    'internal_error',
                                message:
                                    'Failure.',
                                status: 500,
                                requestId:
                                    'lesson-request-1',
                            }),
                        );
                    }

                    return Promise.resolve(
                        lessonFixture(),
                    );
                }

                if (
                    method === 'GET'
                    && url
                        === '/api/lessons/lesson-1/progress'
                ) {
                    return Promise.resolve(
                        null,
                    );
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPage();

        expect(
            await screen.findByText(
                /lesson-request-1/,
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
                lessonRequests,
            ).toBe(2);
        });

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'درس النسب',
                },
            ),
        ).toBeInTheDocument();
    });
});

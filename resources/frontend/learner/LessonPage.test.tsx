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
        curriculum_version_id: 'version-1',
        title: 'درس النسب',
        description:
            'تعلم أساسيات النسب.',
        status: 'published',
        display_order: 1,
        published_revision: {
            id: 'revision-1',
            revision_number: 3,
            primary_topic_id: 'topic-1',
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

describe('LessonPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('renders the published revision content', async () => {
        apiRequestMock.mockResolvedValueOnce(
            lessonFixture(),
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
            screen.getByText(
                'يمكن تبسيط النسبة بالقسمة.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'تدريب النسب',
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
                name: 'العودة إلى المنهج',
            }),
        ).toHaveAttribute(
            'href',
            '/app/curriculum/version-1',
        );
    });

    it('does not invent rendering for unsupported payloads', async () => {
        const baseLesson = lessonFixture();

        const lesson = {
            ...baseLesson,
            published_revision: {
                ...baseLesson.published_revision,
                content_payload: {
                    widgets: [
                        {
                            kind: 'unknown',
                        },
                    ],
                },
            },
        };

        apiRequestMock.mockResolvedValueOnce(
            lesson,
        );

        renderPage();

        expect(
            await screen.findByText(
                'محتوى هذا الدرس يستخدم تنسيقًا لم يتم عرضه بعد في واجهة المتعلم.',
            ),
        ).toBeInTheDocument();
    });

    it('shows request id and retries a failed lesson request', async () => {
        apiRequestMock
            .mockRejectedValueOnce(
                new EduCoreApiError({
                    code: 'internal_error',
                    message: 'Failure.',
                    status: 500,
                    requestId:
                        'lesson-request-1',
                }),
            )
            .mockResolvedValueOnce(
                lessonFixture(),
            );

        renderPage();

        expect(
            await screen.findByRole(
                'alert',
            ),
        ).toHaveTextContent(
            'lesson-request-1',
        );

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
                apiRequestMock,
            ).toHaveBeenCalledTimes(2);
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

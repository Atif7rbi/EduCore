import {
    fireEvent,
    render,
    screen,
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
    ResultsPage,
} from './ResultsPage';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (...args: unknown[]) =>
        apiRequestMock(...args),
}));

function renderPage() {
    const client =
        new QueryClient({
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
        <QueryClientProvider client={client}>
            <MemoryRouter
                initialEntries={[
                    '/app/results',
                ]}
            >
                <Routes>
                    <Route
                        path="/app/results"
                        element={<ResultsPage />}
                    />

                    <Route
                        path="/app/attempts/:attemptId"
                        element={
                            <div>
                                attempt target
                            </div>
                        }
                    />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('ResultsPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('renders exam and practice history using backend order', async () => {
        apiRequestMock.mockResolvedValueOnce([
            {
                id: 'practice-attempt',
                exam_generation_id: null,
                practice_activity_id:
                    'practice-1',
                curriculum_version_id:
                    'version-1',
                status: 'submitted',
                started_at:
                    '2026-08-30T12:00:00Z',
                finalized_at:
                    '2026-08-30T12:10:00Z',
                summary: {
                    answered: 2,
                    correct: 1,
                    incorrect: 1,
                    unanswered: 0,
                    total: 2,
                },
            },
            {
                id: 'exam-attempt',
                exam_generation_id:
                    'generation-1',
                practice_activity_id:
                    null,
                curriculum_version_id:
                    'version-1',
                status: 'in_progress',
                started_at:
                    '2026-08-29T12:00:00Z',
                finalized_at: null,
            },
        ]);

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name:
                        'النتائج والمحاولات',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'ممارسة',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'اختبار',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole(
                'link',
                {
                    name:
                        'استئناف المحاولة',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/app/attempts/exam-attempt',
        );

        expect(
            screen.getByRole(
                'link',
                {
                    name:
                        'عرض النتيجة',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/app/attempts/practice-attempt',
        );

        const cards =
            screen.getAllByTestId(
                /^attempt-/,
            );

        expect(cards).toHaveLength(2);

        expect(cards[0]).toHaveAttribute(
            'data-testid',
            'attempt-practice-attempt',
        );

        expect(cards[1]).toHaveAttribute(
            'data-testid',
            'attempt-exam-attempt',
        );
    });

    it('renders effective summary only when supplied by backend', async () => {
        apiRequestMock.mockResolvedValueOnce([
            {
                id: 'submitted-attempt',
                exam_generation_id:
                    'generation-1',
                practice_activity_id:
                    null,
                curriculum_version_id:
                    'version-1',
                status: 'submitted',
                started_at:
                    '2026-08-30T12:00:00Z',
                finalized_at:
                    '2026-08-30T12:10:00Z',
                summary: {
                    answered: 3,
                    correct: 2,
                    incorrect: 1,
                    unanswered: 1,
                    total: 4,
                },
            },
        ]);

        renderPage();

        expect(
            await screen.findByText(
                'صحيحة',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'غير صحيحة',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'بدون إجابة',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'الإجمالي',
            ),
        ).toBeInTheDocument();
    });

    it('does not invent summary for in-progress attempt', async () => {
        apiRequestMock.mockResolvedValueOnce([
            {
                id: 'attempt-1',
                exam_generation_id:
                    'generation-1',
                practice_activity_id:
                    null,
                curriculum_version_id:
                    'version-1',
                status: 'in_progress',
                started_at:
                    '2026-08-30T12:00:00Z',
                finalized_at: null,
            },
        ]);

        renderPage();

        expect(
            await screen.findByText(
                'قيد التقدم',
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByLabelText(
                'ملخص النتيجة',
            ),
        ).not.toBeInTheDocument();
    });

    it('renders empty history state', async () => {
        apiRequestMock.mockResolvedValueOnce(
            [],
        );

        renderPage();

        expect(
            await screen.findByText(
                'لا توجد محاولات مسجلة حتى الآن.',
            ),
        ).toBeInTheDocument();
    });

    it('renders error state and retries history request', async () => {
        apiRequestMock
            .mockRejectedValueOnce(
                new Error(
                    'history unavailable',
                ),
            )
            .mockResolvedValueOnce([]);

        renderPage();

        expect(
            await screen.findByText(
                'تعذر تحميل سجل المحاولات.',
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
                'لا توجد محاولات مسجلة حتى الآن.',
            ),
        ).toBeInTheDocument();

        expect(
            apiRequestMock,
        ).toHaveBeenCalledTimes(2);
    });
});

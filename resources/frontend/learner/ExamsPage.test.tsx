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
    ExamsPage,
} from './ExamsPage';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (...args: unknown[]) =>
        apiRequestMock(...args),
}));

function generationFixture(
    currentAttempt:
        | {
            id: string;
            status:
                | 'in_progress'
                | 'submitted';
            started_at: string | null;
            finalized_at: string | null;
        }
        | null = null,
) {
    return {
        id: 'generation-1',
        curriculum_version_id:
            'version-1',
        exam_template_version_id:
            'template-version-1',
        template: {
            id: 'template-1',
            name: 'اختبار النسب',
            description:
                'اختبار قصير على أساسيات النسب.',
        },
        template_version: {
            id: 'template-version-1',
            version_number: 2,
            label: 'الإصدار الثاني',
        },
        generated_at:
            '2026-08-30T00:00:00Z',
        item_count: 10,
        current_attempt:
            currentAttempt,
    };
}

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
                    '/app/exams',
                ]}
            >
                <Routes>
                    <Route
                        path="/app/exams"
                        element={<ExamsPage />}
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

describe('ExamsPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('renders eligible exam generation', async () => {
        apiRequestMock.mockResolvedValueOnce([
            generationFixture(),
        ]);

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'اختبار النسب',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                '10 سؤال',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole(
                'button',
                {
                    name: 'ابدأ الاختبار',
                },
            ),
        ).toBeInTheDocument();
    });

    it('starts exam and navigates to created attempt', async () => {
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
                        === '/api/exam-generations'
                ) {
                    return Promise.resolve([
                        generationFixture(),
                    ]);
                }

                if (
                    method === 'POST'
                    && url
                        === '/api/exam-generations/generation-1/attempts'
                ) {
                    return Promise.resolve({
                        id: 'attempt-1',
                        exam_generation_id:
                            'generation-1',
                        practice_activity_id:
                            null,
                        curriculum_version_id:
                            'version-1',
                        status:
                            'in_progress',
                        started_at:
                            '2026-08-30T00:00:00Z',
                    });
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
                    name:
                        'ابدأ الاختبار',
                },
            ),
        );

        expect(
            await screen.findByText(
                'attempt target',
            ),
        ).toBeInTheDocument();

        expect(
            apiRequestMock,
        ).toHaveBeenCalledWith({
            method: 'POST',
            url:
                '/api/exam-generations/generation-1/attempts',
            data: {},
        });
    });

    it('offers resume instead of creating a second attempt', async () => {
        apiRequestMock.mockResolvedValueOnce([
            generationFixture({
                id: 'attempt-1',
                status:
                    'in_progress',
                started_at:
                    '2026-08-30T00:00:00Z',
                finalized_at: null,
            }),
        ]);

        renderPage();

        expect(
            await screen.findByRole(
                'link',
                {
                    name:
                        'استئناف الاختبار',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/app/attempts/attempt-1',
        );

        expect(
            screen.queryByRole(
                'button',
                {
                    name:
                        'ابدأ الاختبار',
                },
            ),
        ).not.toBeInTheDocument();
    });

    it('opens finalized exam result instead of creating another attempt', async () => {
        apiRequestMock.mockResolvedValueOnce([
            generationFixture({
                id: 'attempt-1',
                status: 'submitted',
                started_at:
                    '2026-08-30T00:00:00Z',
                finalized_at:
                    '2026-08-30T00:10:00Z',
            }),
        ]);

        renderPage();

        expect(
            await screen.findByRole(
                'link',
                {
                    name: 'عرض النتيجة',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/app/attempts/attempt-1',
        );

        expect(
            screen.queryByRole(
                'button',
                {
                    name:
                        'ابدأ الاختبار',
                },
            ),
        ).not.toBeInTheDocument();
    });
});

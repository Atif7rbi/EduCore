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
    AttemptPage,
} from './AttemptPage';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (...args: unknown[]) =>
        apiRequestMock(...args),
}));

function inProgressAttempt() {
    return {
        id: 'attempt-1',
        exam_generation_id: null,
        practice_activity_id:
            'practice-1',
        curriculum_version_id:
            'version-1',
        status: 'in_progress',
        started_at:
            '2026-08-29T00:00:00Z',
        finalized_at: null,
        items: [
            {
                id: 'attempt-item-1',
                assessment_item_revision_id:
                    'revision-1',
                assessment_item_id:
                    'item-1',
                presentation_position: 0,
                presented_payload: {
                    stem: '8 + 7 = ؟',
                    options: [
                        13,
                        14,
                        15,
                        16,
                    ],
                },
                presented_schema_version: 1,
                response: {
                    id: 'response-1',
                    response_payload: null,
                    answer_change_count: 0,
                    time_spent_ms: 0,
                },
            },
        ],
    };
}

function submittedAttempt() {
    return {
        ...inProgressAttempt(),
        status: 'submitted',
        finalized_at:
            '2026-08-29T00:01:00Z',
        items: [
            {
                ...inProgressAttempt().items[0],
                response: {
                    id: 'response-1',
                    response_payload: {
                        selected_option: 2,
                    },
                    answer_change_count: 0,
                    time_spent_ms: 1500,
                },
                result: {
                    original_is_correct:
                        true,
                    effective_is_correct:
                        true,
                    correction_number:
                        null,
                },
            },
        ],
        summary: {
            answered: 1,
            correct: 1,
            incorrect: 0,
            unanswered: 0,
            total: 1,
        },
    };
}

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
                    '/app/attempts/attempt-1',
                ]}
            >
                <Routes>
                    <Route
                        path="/app/attempts/:attemptId"
                        element={<AttemptPage />}
                    />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('AttemptPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('renders multiple choice without scoring truth', async () => {
        apiRequestMock.mockResolvedValueOnce(
            inProgressAttempt(),
        );

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: '8 + 7 = ؟',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole(
                'radio',
                {
                    name: '15',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByText(
                /صحيحة/,
            ),
        ).not.toBeInTheDocument();
    });

    it('saves selected answer and finalizes last item', async () => {
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
                ) {
                    const getCount =
                        apiRequestMock.mock.calls
                            .filter(
                                ([config]) =>
                                    config.method
                                    === 'GET',
                            )
                            .length;

                    return Promise.resolve(
                        getCount === 1
                            ? inProgressAttempt()
                            : submittedAttempt(),
                    );
                }

                if (
                    method === 'PUT'
                ) {
                    return Promise.resolve({
                        id: 'response-1',
                        attempt_item_id:
                            'attempt-item-1',
                        response_payload: {
                            selected_option: 2,
                        },
                        answer_change_count: 0,
                        time_spent_ms: 10,
                    });
                }

                if (
                    method === 'POST'
                    && url.endsWith(
                        '/finalize',
                    )
                ) {
                    return Promise.resolve({
                        id: 'attempt-1',
                        status: 'submitted',
                        finalized_at:
                            '2026-08-29T00:01:00Z',
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
                'radio',
                {
                    name: '15',
                },
            ),
        );

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name:
                        'إنهاء الممارسة',
                },
            ),
        );

        expect(
            await screen.findByRole(
                'heading',
                {
                    name:
                        'اكتملت الممارسة',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'الإجابة صحيحة',
            ),
        ).toBeInTheDocument();

        const putCall =
            apiRequestMock.mock.calls
                .map(([config]) => config)
                .find(
                    (config) =>
                        config.method === 'PUT',
                );

        expect(putCall).toMatchObject({
            method: 'PUT',
            url:
                '/api/attempt-items/attempt-item-1/response',
            data: {
                response_payload: {
                    selected_option: 2,
                },
            },
        });

        expect(
            apiRequestMock.mock.calls
                .map(([config]) => config)
                .some(
                    (config) =>
                        config.method === 'POST'
                        && config.url
                        === '/api/attempts/attempt-1/finalize',
                ),
        ).toBe(true);

        await waitFor(() => {
            expect(
                screen.getByText(
                    'صحيحة',
                ).parentElement,
            ).toHaveTextContent(
                '1',
            );
        });
    });

    it('saves the current answer before navigating to the previous item', async () => {
        const firstAttempt = {
            ...inProgressAttempt(),
            items: [
                {
                    ...inProgressAttempt().items[0],
                    presented_payload: {
                        stem: 'السؤال الأول',
                        options: [
                            'أ',
                            'ب',
                        ],
                    },
                },
                {
                    ...inProgressAttempt().items[0],
                    id: 'attempt-item-2',
                    assessment_item_revision_id:
                        'revision-2',
                    assessment_item_id:
                        'item-2',
                    presentation_position: 1,
                    presented_payload: {
                        stem: 'السؤال الثاني',
                        options: [
                            'ج',
                            'د',
                        ],
                    },
                    response: {
                        id: 'response-2',
                        response_payload: null,
                        answer_change_count: 0,
                        time_spent_ms: 0,
                    },
                },
            ],
        };

        apiRequestMock.mockImplementation(
            ({
                method,
            }: {
                method: string;
            }) => {
                if (method === 'GET') {
                    return Promise.resolve(
                        firstAttempt,
                    );
                }

                if (method === 'PUT') {
                    return Promise.resolve({
                        id: 'response',
                        response_payload: {
                            selected_option: 0,
                        },
                        answer_change_count: 0,
                        time_spent_ms: 10,
                    });
                }

                throw new Error(
                    `Unexpected method ${method}`,
                );
            },
        );

        renderPage();

        fireEvent.click(
            await screen.findByRole(
                'radio',
                {
                    name: 'أ',
                },
            ),
        );

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name: 'حفظ والتالي',
                },
            ),
        );

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'السؤال الثاني',
                },
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole(
                'radio',
                {
                    name: 'د',
                },
            ),
        );

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name: 'السابق',
                },
            ),
        );

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'السؤال الأول',
                },
            ),
        ).toBeInTheDocument();

        expect(
            apiRequestMock.mock.calls
                .map(([config]) => config)
                .some(
                    (config) =>
                        config.method === 'PUT'
                        && config.url
                        === '/api/attempt-items/attempt-item-2/response'
                        && config.data
                            ?.response_payload
                            ?.selected_option
                        === 1,
                ),
        ).toBe(true);
    });

    it('shows safe state for unsupported question payload', async () => {
        const baseAttempt =
            inProgressAttempt();

        const attempt = {
            ...baseAttempt,
            items: [
                {
                    ...baseAttempt.items[0],
                    presented_payload: {
                        widgets: [],
                    },
                },
            ],
        };

        apiRequestMock.mockResolvedValueOnce(
            attempt,
        );

        renderPage();

        expect(
            await screen.findByText(
                'صيغة هذا السؤال غير مدعومة في واجهة المتعلم الحالية.',
            ),
        ).toBeInTheDocument();
    });

    it('renders submitted result summary', async () => {
        apiRequestMock.mockResolvedValueOnce(
            submittedAttempt(),
        );

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name:
                        'اكتملت الممارسة',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'الإجابة صحيحة',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'صحيحة',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'الإجمالي',
            ),
        ).toBeInTheDocument();
    });
});

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
    PracticeActivityPage,
} from './PracticeActivityPage';

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
                    '/app/practice/practice-1',
                ]}
            >
                <Routes>
                    <Route
                        path="/app/practice/:practiceActivityId"
                        element={
                            <PracticeActivityPage />
                        }
                    />

                    <Route
                        path="/app/attempts/:attemptId"
                        element={
                            <div>
                                Attempt destination
                            </div>
                        }
                    />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('PracticeActivityPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('loads activity and starts an attempt', async () => {
        apiRequestMock
            .mockResolvedValueOnce({
                id: 'practice-1',
                curriculum_version_id:
                    'version-1',
                lesson_id: 'lesson-1',
                name: 'تدريب النسب',
                description:
                    'تدريب قصير.',
                status: 'active',
                items: [
                    {
                        id: 'membership-1',
                        assessment_item_revision_id:
                            'revision-1',
                        assessment_item_id:
                            'item-1',
                        display_order: 0,
                    },
                ],
            })
            .mockResolvedValueOnce({
                id: 'attempt-1',
                practice_activity_id:
                    'practice-1',
                curriculum_version_id:
                    'version-1',
                status: 'in_progress',
                started_at:
                    '2026-08-29T00:00:00Z',
                items: [],
            });

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'تدريب النسب',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /1 من الأسئلة/,
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name: 'ابدأ الممارسة',
                },
            ),
        );

        expect(
            await screen.findByText(
                'Attempt destination',
            ),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(
                apiRequestMock,
            ).toHaveBeenCalledTimes(2);
        });

        expect(
            apiRequestMock,
        ).toHaveBeenNthCalledWith(
            2,
            {
                method: 'POST',
                url:
                    '/api/practice-activities/practice-1/attempts',
                data: {},
            },
        );
    });
});

import {
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
    CurriculumVersionPage,
} from './CurriculumVersionPage';

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
                    '/app/curriculum/version-1',
                ]}
            >
                <Routes>
                    <Route
                        path="/app/curriculum/:curriculumVersionId"
                        element={
                            <CurriculumVersionPage />
                        }
                    />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('CurriculumVersionPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('loads the version and published lessons', async () => {
        apiRequestMock.mockImplementation(
            ({ url }: { url: string }) => {
                if (
                    url
                    === '/api/curriculum-versions/version-1'
                ) {
                    return Promise.resolve({
                        id: 'version-1',
                        curriculum_id: 'curriculum-1',
                        version_number: 2,
                        label: 'الإصدار الثاني',
                        status: 'published',
                        topics: [
                            {
                                id: 'topic-1',
                                name: 'النسب',
                                display_order: 1,
                            },
                            {
                                id: 'topic-2',
                                name: 'الجبر',
                                display_order: 2,
                            },
                        ],
                    });
                }

                return Promise.resolve([
                    {
                        id: 'lesson-1',
                        curriculum_version_id:
                            'version-1',
                        title: 'درس النسب',
                        description:
                            'مقدمة في النسب.',
                        status: 'published',
                        display_order: 1,
                        published_revision_id:
                            'revision-1',
                    },
                ]);
            },
        );

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'الإصدار الثاني',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText('النسب'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('الجبر'),
        ).toBeInTheDocument();

        const lessonLink =
            screen.getByRole('link', {
                name:
                    /درس النسب.*مقدمة في النسب.*فتح الدرس/s,
            });

        expect(lessonLink).toHaveAttribute(
            'href',
            '/app/lessons/lesson-1',
        );

        expect(
            apiRequestMock,
        ).toHaveBeenCalledTimes(2);
    });

    it('shows empty lesson state', async () => {
        apiRequestMock.mockImplementation(
            ({ url }: { url: string }) => {
                if (
                    url.endsWith('/lessons')
                ) {
                    return Promise.resolve([]);
                }

                return Promise.resolve({
                    id: 'version-1',
                    curriculum_id: 'curriculum-1',
                    version_number: 1,
                    label: 'v1',
                    status: 'published',
                    topics: [],
                });
            },
        );

        renderPage();

        expect(
            await screen.findByText(
                'لا توجد دروس منشورة في هذا الإصدار حاليًا.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'لا توجد موضوعات منشورة في هذا الإصدار.',
            ),
        ).toBeInTheDocument();
    });
});

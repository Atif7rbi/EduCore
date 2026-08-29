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
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    EduCoreApiError,
} from '../api/errors';

import {
    CurriculumDiscoveryPage,
} from './CurriculumDiscoveryPage';

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
            <CurriculumDiscoveryPage />
        </QueryClientProvider>,
    );
}

describe('CurriculumDiscoveryPage', () => {
    it('shows loading state while curricula are loading', () => {
        apiRequestMock.mockReset();
        apiRequestMock.mockImplementation(
            () => new Promise(() => undefined),
        );

        renderPage();

        expect(
            screen.getByLabelText(
                'جار تحميل المناهج',
            ),
        ).toHaveAttribute(
            'aria-busy',
            'true',
        );
    });

    it('renders the empty state', async () => {
        apiRequestMock.mockReset();
        apiRequestMock.mockResolvedValueOnce([]);

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name:
                        'لا توجد مناهج منشورة حاليًا',
                },
            ),
        ).toBeInTheDocument();
    });

    it('renders curricula and every published version', async () => {
        apiRequestMock.mockReset();
        apiRequestMock.mockResolvedValueOnce([
            {
                subject: {
                    id: 'subject-1',
                    name: 'القدرات الكمية',
                },
                curriculum: {
                    id: 'curriculum-1',
                    name: 'المنهج الكمي',
                },
                published_versions: [
                    {
                        id: 'version-1',
                        version_number: 1,
                        label: 'الإصدار الأول',
                    },
                    {
                        id: 'version-2',
                        version_number: 2,
                        label: 'الإصدار الثاني',
                    },
                ],
            },
        ]);

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name: 'المنهج الكمي',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'القدرات الكمية',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'الإصدار الأول',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'الإصدار الثاني',
            ),
        ).toBeInTheDocument();

        expect(
            apiRequestMock,
        ).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/curricula',
        });
    });

    it('renders request id and retries after an api error', async () => {
        apiRequestMock.mockReset();

        apiRequestMock
            .mockRejectedValueOnce(
                new EduCoreApiError({
                    code: 'internal_error',
                    message: 'Failure.',
                    status: 500,
                    requestId: 'p31-request',
                }),
            )
            .mockResolvedValueOnce([]);

        renderPage();

        expect(
            await screen.findByRole('alert'),
        ).toHaveTextContent(
            'تعذر تحميل المناهج.',
        );

        expect(
            screen.getByText(
                /p31-request/,
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

        await waitFor(() => {
            expect(
                apiRequestMock,
            ).toHaveBeenCalledTimes(2);
        });

        expect(
            await screen.findByRole(
                'heading',
                {
                    name:
                        'لا توجد مناهج منشورة حاليًا',
                },
            ),
        ).toBeInTheDocument();
    });
});

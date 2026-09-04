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
    AdminCurriculaPage,
} from './AdminCurriculaPage';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (config: RequestConfig) =>
        apiRequestMock(config),
}));

function renderPage() {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <AdminCurriculaPage />
        </QueryClientProvider>,
    );
}

function installInventory() {
    apiRequestMock.mockImplementation(
        ({ method, url }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/subjects'
            ) {
                return Promise.resolve([
                    {
                        id: 'subject-1',
                        name: 'القدرات العامة',
                        created_at: null,
                        updated_at: null,
                    },
                ]);
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/subjects/subject-1/curricula'
            ) {
                return Promise.resolve([
                    {
                        id: 'curriculum-1',
                        subject_id: 'subject-1',
                        name: 'القسم الكمي',
                        created_at: null,
                        updated_at: null,
                    },
                ]);
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe('AdminCurriculaPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('shows educator-facing material and curriculum management only', async () => {
        installInventory();
        renderPage();

        expect(
            await screen.findByRole('heading', {
                name: 'إدارة المناهج',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'نظّم المواد والمناهج التي ستبني عليها الدروس والأسئلة والتدريبات.',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                name: 'المواد',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                name: 'المناهج',
            }),
        ).toBeInTheDocument();

        expect(
            screen.queryByText('إصدارات المنهج'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Admin Studio'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/دورة حياة النشر/),
        ).not.toBeInTheDocument();
    });

    it('loads existing material and curriculum names', async () => {
        installInventory();
        renderPage();

        expect(
            await screen.findByText('القدرات العامة'),
        ).toBeInTheDocument();
        expect(
            await screen.findByText('القسم الكمي'),
        ).toBeInTheDocument();
    });

    it('creates a curriculum and prepares its initial working draft internally', async () => {
        apiRequestMock.mockImplementation(
            ({ method, url, data }: RequestConfig) => {
                if (
                    method === 'GET'
                    && url === '/api/admin/subjects'
                ) {
                    return Promise.resolve([
                        {
                            id: 'subject-1',
                            name: 'القدرات العامة',
                            created_at: null,
                            updated_at: null,
                        },
                    ]);
                }

                if (
                    method === 'GET'
                    && url
                        === '/api/admin/subjects/subject-1/curricula'
                ) {
                    return Promise.resolve([]);
                }

                if (
                    method === 'POST'
                    && url
                        === '/api/admin/subjects/subject-1/curricula'
                ) {
                    expect(data).toEqual({
                        name: 'القسم اللفظي',
                    });

                    return Promise.resolve({
                        id: 'curriculum-2',
                        subject_id: 'subject-1',
                        name: 'القسم اللفظي',
                        created_at: null,
                        updated_at: null,
                    });
                }

                if (
                    method === 'POST'
                    && url
                        === '/api/admin/curricula/curriculum-2/versions'
                ) {
                    expect(data).toEqual({
                        version_number: 1,
                        label: 'مسودة العمل',
                    });

                    return Promise.resolve({
                        id: 'version-1',
                        curriculum_id: 'curriculum-2',
                        version_number: 1,
                        label: 'مسودة العمل',
                        status: 'draft',
                    });
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPage();

        fireEvent.change(
            await screen.findByLabelText('اسم المنهج'),
            {
                target: { value: 'القسم اللفظي' },
            },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إضافة منهج',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url:
                    '/api/admin/curricula/curriculum-2/versions',
                data: {
                    version_number: 1,
                    label: 'مسودة العمل',
                },
            });
        });
    });

    it('keeps subject and curriculum editing in plain user language', async () => {
        installInventory();
        renderPage();

        await screen.findByText('القسم الكمي');

        const editButtons = screen.getAllByRole(
            'button',
            { name: 'تعديل' },
        );

        expect(editButtons.length).toBe(2);
        expect(
            screen.queryByRole('button', {
                name: 'نشر',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'تقاعد',
            }),
        ).not.toBeInTheDocument();
    });
});

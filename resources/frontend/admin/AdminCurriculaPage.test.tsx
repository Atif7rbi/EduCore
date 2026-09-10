import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
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

function curriculum(index: number) {
    return {
        id: `curriculum-${index}`,
        subject_id: 'subject-1',
        name: `منهج ${String(index).padStart(2, '0')}`,
        created_at: null,
        updated_at: null,
    };
}

function installInventory(
    curricula = [
        {
            id: 'curriculum-1',
            subject_id: 'subject-1',
            name: 'القسم الكمي',
            created_at: null,
            updated_at: null,
        },
    ],
) {
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
                return Promise.resolve(curricula);
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

    it('filters materials locally without another API request', async () => {
        apiRequestMock.mockImplementation(
            ({ method, url }: RequestConfig) => {
                if (
                    method === 'GET'
                    && url === '/api/admin/subjects'
                ) {
                    return Promise.resolve([
                        {
                            id: 'subject-1',
                            name: 'مادة كمية',
                            created_at: null,
                            updated_at: null,
                        },
                        {
                            id: 'subject-2',
                            name: 'مادة لفظية',
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

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPage();

        expect(
            await screen.findByText('مادة كمية'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('مادة لفظية'),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledTimes(2);
        });

        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'بحث في المواد',
            }),
            {
                target: { value: 'لفظية' },
            },
        );

        const materialsList =
            document.querySelector(
                '[aria-label="قائمة المواد"]',
            );

        expect(materialsList).not.toBeNull();

        expect(
            within(
                materialsList as HTMLElement,
            ).queryByText('مادة كمية'),
        ).not.toBeInTheDocument();

        expect(
            within(
                materialsList as HTMLElement,
            ).getByText('مادة لفظية'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('المادة الحالية'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('مادة كمية'),
        ).toBeInTheDocument();

        expect(apiRequestMock).toHaveBeenCalledTimes(2);
    });

    it('filters curricula locally without another API request', async () => {
        installInventory([
            {
                id: 'curriculum-1',
                subject_id: 'subject-1',
                name: 'القسم الكمي',
                created_at: null,
                updated_at: null,
            },
            {
                id: 'curriculum-2',
                subject_id: 'subject-1',
                name: 'القسم اللفظي',
                created_at: null,
                updated_at: null,
            },
        ]);

        renderPage();

        await screen.findByText('القسم الكمي');
        await screen.findByText('القسم اللفظي');

        expect(apiRequestMock).toHaveBeenCalledTimes(2);

        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'بحث في المناهج',
            }),
            {
                target: { value: 'لفظي' },
            },
        );

        expect(
            screen.queryByText('القسم الكمي'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('القسم اللفظي'),
        ).toBeInTheDocument();

        expect(apiRequestMock).toHaveBeenCalledTimes(2);
    });

    it('paginates curricula locally at twenty items per page', async () => {
        installInventory(
            Array.from(
                { length: 25 },
                (_, index) => curriculum(index + 1),
            ),
        );

        renderPage();

        expect(
            await screen.findByText('منهج 01'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('منهج 20'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('منهج 21'),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText('صفحة 1 من 2'),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'التالي',
            }),
        );

        expect(
            screen.getByText('منهج 21'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('منهج 25'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('منهج 01'),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText('صفحة 2 من 2'),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'السابق',
            }),
        );

        expect(
            screen.getByText('منهج 01'),
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

        const addCurriculum = await screen.findByRole(
            'button',
            {
                name: '+ إضافة منهج',
            },
        );

        await waitFor(() => {
            expect(addCurriculum).toBeEnabled();
        });

        fireEvent.click(addCurriculum);

        fireEvent.change(
            screen.getByLabelText('اسم المنهج'),
            {
                target: { value: 'القسم اللفظي' },
            },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إنشاء المنهج',
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

    it('keeps creation forms compact until requested', async () => {
        installInventory();
        renderPage();

        await screen.findByText('القدرات العامة');

        expect(
            screen.queryByLabelText('اسم المادة'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('اسم المنهج'),
        ).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: '+ إضافة مادة',
            }),
        );

        expect(
            screen.getByLabelText('اسم المادة'),
        ).toBeInTheDocument();

        const addCurriculum = screen.getByRole(
            'button',
            {
                name: '+ إضافة منهج',
            },
        );

        await waitFor(() => {
            expect(addCurriculum).toBeEnabled();
        });

        fireEvent.click(addCurriculum);

        expect(
            screen.getByLabelText('اسم المنهج'),
        ).toBeInTheDocument();
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

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
    apiRequest: (
        config: RequestConfig,
    ) => apiRequestMock(config),
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
            <AdminCurriculaPage />
        </QueryClientProvider>,
    );
}

function installInventory() {
    apiRequestMock.mockImplementation(
        ({
            method,
            url,
        }: RequestConfig) => {
            if (
                method === 'GET'
                && url
                    === '/api/admin/subjects'
            ) {
                return Promise.resolve([
                    {
                        id: 'subject-1',
                        name:
                            'القدرات الكمية',
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
                        id:
                            'curriculum-1',
                        subject_id:
                            'subject-1',
                        name:
                            'المنهج الكمي',
                        created_at: null,
                        updated_at: null,
                    },
                ]);
            }

            if (
                method === 'POST'
                && url
                    === '/api/curriculum-versions/version-1/retire'
            ) {
                return Promise.resolve({
                    id: 'version-1',
                    curriculum_id:
                        'curriculum-1',
                    version_number: 1,
                    label:
                        'الإصدار الأول',
                    status: 'retired',
                });
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/curricula/curriculum-1/versions'
            ) {
                return Promise.resolve([
                    {
                        id: 'version-1',
                        curriculum_id:
                            'curriculum-1',
                        version_number: 1,
                        label:
                            'الإصدار الأول',
                        status:
                            'published',
                    },
                    {
                        id: 'version-2',
                        curriculum_id:
                            'curriculum-1',
                        version_number: 2,
                        label:
                            'الإصدار الثاني',
                        status:
                            'draft',
                    },
                    {
                        id: 'version-3',
                        curriculum_id:
                            'curriculum-1',
                        version_number: 3,
                        label:
                            'إصدار قديم',
                        status:
                            'retired',
                    },
                ]);
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe(
    'AdminCurriculaPage',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'loads subjects curricula and versions through scoped inventory',
            async () => {
                installInventory();

                renderPage();

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'إدارة المناهج',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    await screen.findByText(
                        'القدرات الكمية',
                    ),
                ).toBeInTheDocument();

                expect(
                    await screen.findByText(
                        'المنهج الكمي',
                    ),
                ).toBeInTheDocument();

                expect(
                    await screen.findByText(
                        'الإصدار الأول',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'الإصدار الثاني',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'إصدار قديم',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'exposes lifecycle actions only for valid states',
            async () => {
                installInventory();

                renderPage();

                await screen.findByText(
                    'الإصدار الأول',
                );

                expect(
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'نشر',
                        },
                    ),
                ).toHaveLength(1);

                expect(
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'تقاعد',
                        },
                    ),
                ).toHaveLength(1);

                expect(
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'تعديل',
                        },
                    ).length,
                ).toBeGreaterThanOrEqual(
                    3,
                );

                expect(
                    screen.getByText(
                        'للقراءة فقط',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a curriculum version as a draft without sending status',
            async () => {
                installInventory();

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                        data,
                    }: RequestConfig) => {
                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/curricula/curriculum-1/versions'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                version_number:
                                    4,
                                label:
                                    'الإصدار الرابع',
                            });

                            return Promise.resolve({
                                id:
                                    'version-4',
                                curriculum_id:
                                    'curriculum-1',
                                version_number:
                                    4,
                                label:
                                    'الإصدار الرابع',
                                status:
                                    'draft',
                            });
                        }

                        if (
                            method === 'GET'
                        ) {
                            if (
                                url
                                    === '/api/admin/subjects'
                            ) {
                                return Promise.resolve([
                                    {
                                        id:
                                            'subject-1',
                                        name:
                                            'القدرات الكمية',
                                        created_at:
                                            null,
                                        updated_at:
                                            null,
                                    },
                                ]);
                            }

                            if (
                                url
                                    === '/api/admin/subjects/subject-1/curricula'
                            ) {
                                return Promise.resolve([
                                    {
                                        id:
                                            'curriculum-1',
                                        subject_id:
                                            'subject-1',
                                        name:
                                            'المنهج الكمي',
                                        created_at:
                                            null,
                                        updated_at:
                                            null,
                                    },
                                ]);
                            }

                            if (
                                url
                                    === '/api/admin/curricula/curriculum-1/versions'
                            ) {
                                return Promise.resolve(
                                    [],
                                );
                            }
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPage();

                const number =
                    await screen.findByLabelText(
                        'رقم الإصدار',
                    );

                fireEvent.change(
                    number,
                    {
                        target: {
                            value: '4',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'اسم الإصدار',
                    ),
                    {
                        target: {
                            value:
                                'الإصدار الرابع',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنشاء مسودة',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'POST',
                        url:
                            '/api/admin/curricula/curriculum-1/versions',
                        data: {
                            version_number:
                                4,
                            label:
                                'الإصدار الرابع',
                        },
                    });
                });
            },
        );

        it(
            'publishes a draft using lifecycle endpoint',
            async () => {
                installInventory();

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'POST'
                            && url
                                === '/api/curriculum-versions/version-2/publish'
                        ) {
                            return Promise.resolve({
                                id:
                                    'version-2',
                                curriculum_id:
                                    'curriculum-1',
                                version_number:
                                    2,
                                label:
                                    'الإصدار الثاني',
                                status:
                                    'published',
                            });
                        }

                        if (
                            method === 'GET'
                        ) {
                            if (
                                url
                                    === '/api/admin/subjects'
                            ) {
                                return Promise.resolve([
                                    {
                                        id:
                                            'subject-1',
                                        name:
                                            'القدرات الكمية',
                                        created_at:
                                            null,
                                        updated_at:
                                            null,
                                    },
                                ]);
                            }

                            if (
                                url
                                    === '/api/admin/subjects/subject-1/curricula'
                            ) {
                                return Promise.resolve([
                                    {
                                        id:
                                            'curriculum-1',
                                        subject_id:
                                            'subject-1',
                                        name:
                                            'المنهج الكمي',
                                        created_at:
                                            null,
                                        updated_at:
                                            null,
                                    },
                                ]);
                            }

                            if (
                                url
                                    === '/api/admin/curricula/curriculum-1/versions'
                            ) {
                                return Promise.resolve([
                                    {
                                        id:
                                            'version-2',
                                        curriculum_id:
                                            'curriculum-1',
                                        version_number:
                                            2,
                                        label:
                                            'الإصدار الثاني',
                                        status:
                                            'draft',
                                    },
                                ]);
                            }
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
                            name: 'نشر',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'POST',
                        url:
                            '/api/curriculum-versions/version-2/publish',
                        data: {},
                    });
                });
            },
        );

        it(
            'retires a published version using lifecycle endpoint',
            async () => {
                installInventory();

                renderPage();

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name: 'تقاعد',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'POST',
                        url:
                            '/api/curriculum-versions/version-1/retire',
                        data: {},
                    });
                });
            },
        );

        it(
            'creates and renames a subject through the admin contract',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                        data,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'subject-1',
                                    name:
                                        'القدرات الكمية',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects/subject-1/curricula'
                        ) {
                            return Promise.resolve(
                                [],
                            );
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/subjects'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                name:
                                    'القدرات اللفظية',
                            });

                            return Promise.resolve({
                                id:
                                    'subject-2',
                                name:
                                    'القدرات اللفظية',
                                created_at:
                                    null,
                                updated_at:
                                    null,
                            });
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects/subject-2/curricula'
                        ) {
                            return Promise.resolve(
                                [],
                            );
                        }

                        if (
                            method === 'PUT'
                            && url
                                === '/api/admin/subjects/subject-1'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                name:
                                    'القدرات الكمية المطورة',
                            });

                            return Promise.resolve({
                                id:
                                    'subject-1',
                                name:
                                    'القدرات الكمية المطورة',
                                created_at:
                                    null,
                                updated_at:
                                    null,
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPage();

                fireEvent.change(
                    await screen.findByLabelText(
                        'اسم المادة',
                    ),
                    {
                        target: {
                            value:
                                'القدرات اللفظية',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إضافة مادة',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'POST',
                        url:
                            '/api/admin/subjects',
                        data: {
                            name:
                                'القدرات اللفظية',
                        },
                    });
                });

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'تعديل',
                        },
                    ),
                );

                const editInput =
                    screen.getByLabelText(
                        'تعديل اسم المادة',
                    );

                fireEvent.change(
                    editInput,
                    {
                        target: {
                            value:
                                'القدرات الكمية المطورة',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'حفظ',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'PUT',
                        url:
                            '/api/admin/subjects/subject-1',
                        data: {
                            name:
                                'القدرات الكمية المطورة',
                        },
                    });
                });
            },
        );

        it(
            'creates and renames a curriculum within the selected subject',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                        data,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'subject-1',
                                    name:
                                        'القدرات الكمية',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
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
                                    id:
                                        'curriculum-1',
                                    subject_id:
                                        'subject-1',
                                    name:
                                        'المنهج الكمي',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && (
                                url
                                    === '/api/admin/curricula/curriculum-1/versions'
                                || url
                                    === '/api/admin/curricula/curriculum-2/versions'
                            )
                        ) {
                            return Promise.resolve(
                                [],
                            );
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/subjects/subject-1/curricula'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                name:
                                    'منهج تجريبي',
                            });

                            return Promise.resolve({
                                id:
                                    'curriculum-2',
                                subject_id:
                                    'subject-1',
                                name:
                                    'منهج تجريبي',
                                created_at:
                                    null,
                                updated_at:
                                    null,
                            });
                        }

                        if (
                            method === 'PUT'
                            && url
                                === '/api/admin/curricula/curriculum-1'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                name:
                                    'المنهج الكمي المطور',
                            });

                            return Promise.resolve({
                                id:
                                    'curriculum-1',
                                subject_id:
                                    'subject-1',
                                name:
                                    'المنهج الكمي المطور',
                                created_at:
                                    null,
                                updated_at:
                                    null,
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPage();

                fireEvent.change(
                    await screen.findByLabelText(
                        'اسم المنهج',
                    ),
                    {
                        target: {
                            value:
                                'منهج تجريبي',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إضافة منهج',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'POST',
                        url:
                            '/api/admin/subjects/subject-1/curricula',
                        data: {
                            name:
                                'منهج تجريبي',
                        },
                    });
                });

                const editButtons =
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'تعديل',
                        },
                    );

                fireEvent.click(
                    editButtons[
                        editButtons.length - 1
                    ],
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل اسم المنهج',
                    ),
                    {
                        target: {
                            value:
                                'المنهج الكمي المطور',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'حفظ',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'PUT',
                        url:
                            '/api/admin/curricula/curriculum-1',
                        data: {
                            name:
                                'المنهج الكمي المطور',
                        },
                    });
                });
            },
        );

        it(
            'edits only a draft curriculum version through the admin endpoint',
            async () => {
                installInventory();

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                        data,
                    }: RequestConfig) => {
                        if (
                            method === 'PUT'
                            && url
                                === '/api/admin/curriculum-versions/version-2'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                version_number:
                                    4,
                                label:
                                    'مسودة محدثة',
                            });

                            return Promise.resolve({
                                id:
                                    'version-2',
                                curriculum_id:
                                    'curriculum-1',
                                version_number:
                                    4,
                                label:
                                    'مسودة محدثة',
                                status:
                                    'draft',
                            });
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'subject-1',
                                    name:
                                        'القدرات الكمية',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
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
                                    id:
                                        'curriculum-1',
                                    subject_id:
                                        'subject-1',
                                    name:
                                        'المنهج الكمي',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curricula/curriculum-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'version-1',
                                    curriculum_id:
                                        'curriculum-1',
                                    version_number:
                                        1,
                                    label:
                                        'الإصدار الأول',
                                    status:
                                        'published',
                                },
                                {
                                    id:
                                        'version-2',
                                    curriculum_id:
                                        'curriculum-1',
                                    version_number:
                                        2,
                                    label:
                                        'الإصدار الثاني',
                                    status:
                                        'draft',
                                },
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPage();

                await screen.findByText(
                    'الإصدار الثاني',
                );

                const editButtons =
                    screen.getAllByRole(
                        'button',
                        {
                            name: 'تعديل',
                        },
                    );

                fireEvent.click(
                    editButtons[
                        editButtons.length - 1
                    ],
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل رقم الإصدار',
                    ),
                    {
                        target: {
                            value: '4',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل اسم الإصدار',
                    ),
                    {
                        target: {
                            value:
                                'مسودة محدثة',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'حفظ',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'PUT',
                        url:
                            '/api/admin/curriculum-versions/version-2',
                        data: {
                            version_number:
                                4,
                            label:
                                'مسودة محدثة',
                        },
                    });
                });
            },
        );
    },
);

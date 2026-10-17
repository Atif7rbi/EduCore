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
    EduCoreApiError,
} from '../api/errors';
import {
    TeacherTopicsPanel,
} from './TeacherTopicsPanel';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

const context = {
    assignmentId: 'assignment-a',
    curriculumId: 'curriculum-a',
    curriculumVersionId: 'version-a',
    versionStatus: 'draft' as const,
};

function renderPanel(
    versionStatus: 'draft' | 'published' | 'retired' = 'draft',
) {
    const client = new QueryClient({
        defaultOptions: {
            mutations: {
                retry: false,
            },
            queries: {
                retry: false,
            },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <TeacherTopicsPanel
                authenticatedUserId="teacher-a"
                context={{
                    ...context,
                    versionStatus,
                }}
                onContextUnavailable={() => {}}
                onDirtyChange={() => {}}
                onLifecycleConflict={async () => {}}
            />
        </QueryClientProvider>,
    );
}

describe('TeacherTopicsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('shows only exact-version topics and creates a draft topic', async () => {
        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: RequestConfig) => {
                if (method === 'GET') {
                    return Promise.resolve([
                        {
                            curriculum_version_id: 'version-a',
                            display_order: 2,
                            id: 'topic-a',
                            name: 'النسب',
                        },
                        {
                            curriculum_version_id: 'version-a',
                            display_order: 0,
                            id: 'topic-b',
                            name: 'الكسور',
                        },
                        {
                            curriculum_version_id: 'foreign-version',
                            display_order: 1,
                            id: 'topic-foreign',
                            name: 'مخفي',
                        },
                    ]);
                }

                if (method === 'POST') {
                    return Promise.resolve({
                        curriculum_version_id: 'version-a',
                        display_order: 0,
                        id: 'topic-new',
                        name: 'الكسور',
                    });
                }

                throw new Error(
                    'Unexpected request '
                    + method
                    + ' '
                    + url,
                );
            },
        );

        renderPanel();

        expect(
            await screen.findByText('النسب'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('مخفي'),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByRole('strong').map(
                (item) => item.textContent,
            ),
        ).toEqual([
            'النسب',
            'الكسور',
        ]);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إضافة موضوع',
            }),
        );
        fireEvent.change(
            screen.getByLabelText('اسم الموضوع'),
            {
                target: {
                    value: 'الكسور',
                },
            },
        );
        fireEvent.change(
            screen.getByLabelText('ترتيب عرض الموضوع'),
            {
                target: {
                    value: '0',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ الموضوع',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: {
                        display_order: 0,
                        name: 'الكسور',
                    },
                    method: 'POST',
                    url:
                        '/api/teacher/subject-assignments/'
                        + 'assignment-a/curricula/curriculum-a/'
                        + 'versions/version-a/topics',
                }),
            );
        });
    });

    it('keeps published topics readable without mutation controls', async () => {
        apiRequestMock.mockResolvedValue([
            {
                curriculum_version_id: 'version-a',
                display_order: 1,
                id: 'topic-a',
                name: 'النسب',
            },
        ]);

        renderPanel('published');

        expect(
            await screen.findByText('هذا الإصدار للقراءة فقط.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'إضافة موضوع',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'تعديل',
            }),
        ).not.toBeInTheDocument();
    });

    it('keeps 422 field errors and draft input visible', async () => {
        const validationError = new EduCoreApiError({
            code: 'validation_failed',
            details: {
                name: ['اسم الموضوع مطلوب.'],
            },
            message: 'تعذر التحقق من البيانات.',
            requestId: 'request-a',
            status: 422,
        });
        apiRequestMock.mockImplementation(
            ({
                method,
            }: RequestConfig) => {
                if (method === 'GET') {
                    return Promise.resolve([]);
                }

                if (method === 'POST') {
                    return Promise.reject(validationError);
                }

                throw new Error('Unexpected request');
            },
        );

        renderPanel();
        await screen.findByText('لا توجد موضوعات في هذا الإصدار.');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'إضافة موضوع',
            }),
        );
        fireEvent.change(
            screen.getByLabelText('اسم الموضوع'),
            {
                target: {
                    value: 'إدخال محتفظ به',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ الموضوع',
            }),
        );

        expect(
            await screen.findByText('اسم الموضوع مطلوب.'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('اسم الموضوع'),
        ).toHaveValue('إدخال محتفظ به');
    });

    it('preserves an edit after a lifecycle conflict and refreshes topics', async () => {
        const conflict = new EduCoreApiError({
            code: 'curriculum_version_not_draft',
            message: 'الإصدار لم يعد مسودة.',
            requestId: 'request-b',
            status: 409,
        });
        apiRequestMock.mockImplementation(
            ({
                method,
            }: RequestConfig) => {
                if (method === 'GET') {
                    return Promise.resolve([
                        {
                            curriculum_version_id: 'version-a',
                            display_order: 1,
                            id: 'topic-a',
                            name: 'النسب',
                        },
                    ]);
                }

                if (method === 'PUT') {
                    return Promise.reject(conflict);
                }

                throw new Error('Unexpected request');
            },
        );

        renderPanel();
        await screen.findByText('النسب');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'تعديل',
            }),
        );
        fireEvent.change(
            screen.getByLabelText('تعديل اسم الموضوع'),
            {
                target: {
                    value: 'النسب المحدثة',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ',
            }),
        );

        expect(
            await screen.findByText('الإصدار لم يعد مسودة.'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('تعديل اسم الموضوع'),
        ).toHaveValue('النسب المحدثة');
        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    method: 'GET',
                }),
            );
            expect(
                apiRequestMock.mock.calls.filter(
                    ([config]) => config.method === 'GET',
                ).length,
            ).toBeGreaterThan(1);
        });
    });

    it.each([
        403,
        404,
    ])(
        'fails closed when the exact version read becomes unavailable with %i',
        async (status) => {
        const onContextUnavailable = vi.fn();
        const client = new QueryClient({
            defaultOptions: {
                mutations: {
                    retry: false,
                },
                queries: {
                    retry: false,
                },
            },
        });
        apiRequestMock.mockRejectedValue(
            new EduCoreApiError({
                code: 'not_found',
                message: 'غير متاح.',
                requestId: 'request-c',
                status,
            }),
        );

        render(
            <QueryClientProvider client={client}>
                <TeacherTopicsPanel
                    authenticatedUserId="teacher-a"
                    context={context}
                    onContextUnavailable={onContextUnavailable}
                    onDirtyChange={() => {}}
                    onLifecycleConflict={async () => {}}
                />
            </QueryClientProvider>,
        );

        await waitFor(() => {
            expect(onContextUnavailable).toHaveBeenCalledTimes(1);
        });
    });


    it.each([
        'published',
        'retired',
    ] as const)(
        'removes structural controls after a conflict refresh resolves %s',
        async (nextStatus) => {
            const conflict = new EduCoreApiError({
                code: 'curriculum_version_not_draft',
                message: 'الإصدار لم يعد مسودة.',
                requestId: 'request-lifecycle',
                status: 409,
            });
            const onLifecycleConflict = vi.fn(async () => {});
            const client = new QueryClient({
                defaultOptions: {
                    mutations: {
                        retry: false,
                    },
                    queries: {
                        retry: false,
                    },
                },
            });
            apiRequestMock.mockImplementation(
                ({
                    method,
                }: RequestConfig) => {
                    if (method === 'GET') {
                        return Promise.resolve([
                            {
                                curriculum_version_id: 'version-a',
                                display_order: 1,
                                id: 'topic-a',
                                name: 'النسب',
                            },
                        ]);
                    }

                    if (method === 'PUT') {
                        return Promise.reject(conflict);
                    }

                    throw new Error('Unexpected request');
                },
            );

            const view = render(
                <QueryClientProvider client={client}>
                    <TeacherTopicsPanel
                        authenticatedUserId="teacher-a"
                        context={context}
                        onContextUnavailable={() => {}}
                        onDirtyChange={() => {}}
                        onLifecycleConflict={onLifecycleConflict}
                    />
                </QueryClientProvider>,
            );

            await screen.findByText('النسب');
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'تعديل',
                }),
            );
            fireEvent.change(
                screen.getByLabelText('تعديل اسم الموضوع'),
                {
                    target: {
                        value: 'النسب المحدثة',
                    },
                },
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'حفظ',
                }),
            );

            await waitFor(() => {
                expect(onLifecycleConflict).toHaveBeenCalledTimes(1);
            });

            view.rerender(
                <QueryClientProvider client={client}>
                    <TeacherTopicsPanel
                        authenticatedUserId="teacher-a"
                        context={{
                            ...context,
                            versionStatus: nextStatus,
                        }}
                        onContextUnavailable={() => {}}
                        onDirtyChange={() => {}}
                        onLifecycleConflict={onLifecycleConflict}
                    />
                </QueryClientProvider>,
            );

            expect(
                await screen.findByText('هذا الإصدار للقراءة فقط.'),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: 'تعديل',
                }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: 'إضافة موضوع',
                }),
            ).not.toBeInTheDocument();
        },
    );

});

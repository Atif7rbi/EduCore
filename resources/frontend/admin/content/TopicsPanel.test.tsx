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
    TopicsPanel,
} from './TopicsPanel';

import type {
    CurriculumVersion,
} from './types';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../../api/client', () => ({
    apiRequest: (
        config: RequestConfig,
    ) => apiRequestMock(config),
}));

const draftVersion:
CurriculumVersion = {
    id: 'version-1',
    curriculum_id:
        'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

function renderPanel(
    version:
        CurriculumVersion =
            draftVersion,
) {
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
            <TopicsPanel
                version={version}
            />
        </QueryClientProvider>,
    );
}

describe(
    'TopicsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists topics for the selected curriculum version',
            async () => {
                apiRequestMock.mockResolvedValueOnce([
                    {
                        id: 'topic-1',
                        curriculum_version_id:
                            'version-1',
                        name:
                            'النسب',
                        display_order:
                            2,
                        created_at:
                            null,
                        updated_at:
                            null,
                    },
                ]);

                renderPanel();

                expect(
                    await screen.findByText(
                        'النسب',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'ترتيب الظهور: 2',
                    ),
                ).toBeInTheDocument();

                expect(
                    apiRequestMock,
                ).toHaveBeenCalledWith({
                    method: 'GET',
                    url:
                        '/api/admin/curriculum-versions/version-1/topics',
                });
            },
        );

        it(
            'creates a topic in a draft curriculum version',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        id: 'topic-1',
                        curriculum_version_id:
                            'version-1',
                        name:
                            'النسب',
                        display_order:
                            3,
                        created_at:
                            null,
                        updated_at:
                            null,
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'topic-1',
                            curriculum_version_id:
                                'version-1',
                            name:
                                'النسب',
                            display_order:
                                3,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد Topics في هذا الإصدار.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'اسم الموضوع الجديد',
                    ),
                    {
                        target: {
                            value: 'النسب',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'ترتيب الموضوع الجديد',
                    ),
                    {
                        target: {
                            value: '3',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إضافة Topic',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/admin/curriculum-versions/version-1/topics',
                        data: {
                            name:
                                'النسب',
                            display_order:
                                3,
                        },
                    });
                });

                expect(
                    await screen.findByText(
                        'النسب',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'updates a topic in a draft curriculum version',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'topic-1',
                            curriculum_version_id:
                                'version-1',
                            name:
                                'النسب',
                            display_order:
                                1,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce({
                        id: 'topic-1',
                        curriculum_version_id:
                            'version-1',
                        name:
                            'النسب والتناسب',
                        display_order:
                            4,
                        created_at:
                            null,
                        updated_at:
                            null,
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'topic-1',
                            curriculum_version_id:
                                'version-1',
                            name:
                                'النسب والتناسب',
                            display_order:
                                4,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'النسب',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name: 'تعديل',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل اسم الموضوع',
                    ),
                    {
                        target: {
                            value:
                                'النسب والتناسب',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل ترتيب الموضوع',
                    ),
                    {
                        target: {
                            value: '4',
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
                        method:
                            'PUT',
                        url:
                            '/api/admin/topics/topic-1',
                        data: {
                            name:
                                'النسب والتناسب',
                            display_order:
                                4,
                        },
                    });
                });

                expect(
                    await screen.findByText(
                        'النسب والتناسب',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'keeps published curriculum topics read only',
            async () => {
                apiRequestMock.mockResolvedValueOnce([
                    {
                        id: 'topic-1',
                        curriculum_version_id:
                            'version-1',
                        name:
                            'النسب',
                        display_order:
                            1,
                        created_at:
                            null,
                        updated_at:
                            null,
                    },
                ]);

                renderPanel({
                    ...draftVersion,
                    status:
                        'published',
                });

                expect(
                    await screen.findByText(
                        'النسب',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'هذه النسخة للقراءة فقط؛ لا يمكن إضافة أو تعديل Topics.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إضافة Topic',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name: 'تعديل',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );
    },
);

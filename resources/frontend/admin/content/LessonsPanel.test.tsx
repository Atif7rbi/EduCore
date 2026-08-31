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
    LessonsPanel,
} from './LessonsPanel';

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
            <LessonsPanel
                version={version}
            />
        </QueryClientProvider>,
    );
}

describe(
    'LessonsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists lessons for the selected curriculum version',
            async () => {
                apiRequestMock.mockResolvedValueOnce([
                    {
                        id: 'lesson-1',
                        curriculum_version_id:
                            'version-1',
                        title:
                            'النسب والتناسب',
                        description:
                            'مقدمة في النسب.',
                        status:
                            'draft',
                        display_order:
                            2,
                        published_revision_id:
                            null,
                        created_at:
                            null,
                        updated_at:
                            null,
                    },
                ]);

                renderPanel();

                expect(
                    await screen.findByText(
                        'النسب والتناسب',
                    ),
                ).toBeInTheDocument();

                expect(
                    apiRequestMock,
                ).toHaveBeenCalledWith({
                    method: 'GET',
                    url:
                        '/api/admin/curriculum-versions/version-1/lessons',
                });
            },
        );

        it(
            'creates a draft lesson',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        id: 'lesson-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            title:
                                'النسب',
                            description:
                                null,
                            status:
                                'draft',
                            display_order:
                                3,
                            published_revision_id:
                                null,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد دروس في هذا الإصدار.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'عنوان الدرس الجديد',
                    ),
                    {
                        target: {
                            value: 'النسب',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'ترتيب الدرس الجديد',
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
                                'إضافة Lesson',
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
                            '/api/admin/curriculum-versions/version-1/lessons',
                        data: {
                            title:
                                'النسب',
                            description:
                                null,
                            display_order:
                                3,
                        },
                    });
                });
            },
        );

        it(
            'updates a draft lesson',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            title:
                                'النسب',
                            description:
                                null,
                            status:
                                'draft',
                            display_order:
                                1,
                            published_revision_id:
                                null,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce({
                        id: 'lesson-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            title:
                                'النسب والتناسب',
                            description:
                                'شرح محدث.',
                            status:
                                'draft',
                            display_order:
                                4,
                            published_revision_id:
                                null,
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
                        'تعديل عنوان الدرس',
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
                        'تعديل وصف الدرس',
                    ),
                    {
                        target: {
                            value:
                                'شرح محدث.',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل ترتيب الدرس',
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
                            '/api/admin/lessons/lesson-1',
                        data: {
                            title:
                                'النسب والتناسب',
                            description:
                                'شرح محدث.',
                            display_order:
                                4,
                        },
                    });
                });
            },
        );

        it(
            'keeps published curriculum lessons read only',
            async () => {
                apiRequestMock.mockResolvedValueOnce([
                    {
                        id: 'lesson-1',
                        curriculum_version_id:
                            'version-1',
                        title: 'النسب',
                        description:
                            null,
                        status:
                            'published',
                        display_order:
                            1,
                        published_revision_id:
                            'revision-1',
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
                        'هذه النسخة للقراءة فقط؛ لا يمكن إنشاء أو تعديل الدروس.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إضافة Lesson',
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

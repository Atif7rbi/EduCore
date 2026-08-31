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
    SkillsPanel,
} from './SkillsPanel';

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

function renderPanel() {
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
            <SkillsPanel />
        </QueryClientProvider>,
    );
}

describe(
    'SkillsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists global skills',
            async () => {
                apiRequestMock.mockResolvedValueOnce([
                    {
                        id: 'skill-1',
                        name:
                            'الاستدلال النسبي',
                        description:
                            'حل العلاقات النسبية.',
                        created_at:
                            null,
                        updated_at:
                            null,
                    },
                ]);

                renderPanel();

                expect(
                    await screen.findByText(
                        'الاستدلال النسبي',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'حل العلاقات النسبية.',
                    ),
                ).toBeInTheDocument();

                expect(
                    apiRequestMock,
                ).toHaveBeenCalledWith({
                    method: 'GET',
                    url:
                        '/api/admin/skills',
                });
            },
        );

        it(
            'creates a global skill',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        id: 'skill-1',
                        name:
                            'الاستدلال النسبي',
                        description:
                            null,
                        created_at:
                            null,
                        updated_at:
                            null,
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'skill-1',
                            name:
                                'الاستدلال النسبي',
                            description:
                                null,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد Skills حتى الآن.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'اسم المهارة الجديدة',
                    ),
                    {
                        target: {
                            value:
                                'الاستدلال النسبي',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إضافة Skill',
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
                            '/api/admin/skills',
                        data: {
                            name:
                                'الاستدلال النسبي',
                            description:
                                null,
                        },
                    });
                });

                expect(
                    await screen.findByText(
                        'الاستدلال النسبي',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a skill with description',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        id: 'skill-1',
                        name:
                            'حل المسائل',
                        description:
                            'استخدام استراتيجيات متعددة.',
                        created_at:
                            null,
                        updated_at:
                            null,
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'skill-1',
                            name:
                                'حل المسائل',
                            description:
                                'استخدام استراتيجيات متعددة.',
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد Skills حتى الآن.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'اسم المهارة الجديدة',
                    ),
                    {
                        target: {
                            value:
                                'حل المسائل',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'وصف المهارة الجديدة',
                    ),
                    {
                        target: {
                            value:
                                'استخدام استراتيجيات متعددة.',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إضافة Skill',
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
                            '/api/admin/skills',
                        data: {
                            name:
                                'حل المسائل',
                            description:
                                'استخدام استراتيجيات متعددة.',
                        },
                    });
                });
            },
        );

        it(
            'updates a global skill',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'skill-1',
                            name:
                                'النسب',
                            description:
                                null,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce({
                        id: 'skill-1',
                        name:
                            'النسب والتناسب',
                        description:
                            'تحليل العلاقات التناسبية.',
                        created_at:
                            null,
                        updated_at:
                            null,
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'skill-1',
                            name:
                                'النسب والتناسب',
                            description:
                                'تحليل العلاقات التناسبية.',
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
                        'تعديل اسم المهارة',
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
                        'تعديل وصف المهارة',
                    ),
                    {
                        target: {
                            value:
                                'تحليل العلاقات التناسبية.',
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
                            '/api/admin/skills/skill-1',
                        data: {
                            name:
                                'النسب والتناسب',
                            description:
                                'تحليل العلاقات التناسبية.',
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
    },
);

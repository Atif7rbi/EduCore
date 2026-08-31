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
    SkillPlacementsPanel,
} from './SkillPlacementsPanel';

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
            <SkillPlacementsPanel
                version={version}
            />
        </QueryClientProvider>,
    );
}

function emptyContext() {
    apiRequestMock
        .mockResolvedValueOnce([])
        .mockResolvedValueOnce([
            {
                id: 'skill-1',
                name:
                    'الاستدلال النسبي',
                description: null,
                created_at: null,
                updated_at: null,
            },
        ])
        .mockResolvedValueOnce([
            {
                id: 'topic-1',
                curriculum_version_id:
                    'version-1',
                name: 'النسب',
                display_order: 1,
                created_at: null,
                updated_at: null,
            },
        ]);
}

describe(
    'SkillPlacementsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists placements and home topics',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'placement-1',
                            skill_id:
                                'skill-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            home_topics: [
                                {
                                    id:
                                        'home-1',
                                    placement_id:
                                        'placement-1',
                                    topic_id:
                                        'topic-1',
                                    curriculum_version_id:
                                        'version-1',
                                    topic: {
                                        id:
                                            'topic-1',
                                        name:
                                            'النسب',
                                    },
                                    created_at:
                                        null,
                                },
                            ],
                            created_at:
                                null,
                        },
                    ])
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
                    ])
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
                    ]);

                renderPanel();

                expect(
                    await screen.findByText(
                        'الاستدلال النسبي',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'النسب',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a skill placement',
            async () => {
                emptyContext();

                apiRequestMock
                    .mockResolvedValueOnce({
                        id: 'placement-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'placement-1',
                            skill_id:
                                'skill-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            home_topics:
                                [],
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد مهارات مرتبطة بهذا الإصدار.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'المهارة المراد ربطها',
                    ),
                    {
                        target: {
                            value:
                                'skill-1',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'ربط Skill',
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
                            '/api/admin/curriculum-versions/version-1/skill-placements',
                        data: {
                            skill_id:
                                'skill-1',
                        },
                    });
                });
            },
        );

        it(
            'adds a home topic',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'placement-1',
                            skill_id:
                                'skill-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            home_topics:
                                [],
                            created_at:
                                null,
                        },
                    ])
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
                    ])
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
                        id: 'home-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'placement-1',
                            skill_id:
                                'skill-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            home_topics: [
                                {
                                    id:
                                        'home-1',
                                    placement_id:
                                        'placement-1',
                                    topic_id:
                                        'topic-1',
                                    curriculum_version_id:
                                        'version-1',
                                    topic: {
                                        id:
                                            'topic-1',
                                        name:
                                            'النسب',
                                    },
                                    created_at:
                                        null,
                                },
                            ],
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                const selector =
                    await screen.findByLabelText(
                        'Home Topic للمهارة الاستدلال النسبي',
                    );

                fireEvent.change(
                    selector,
                    {
                        target: {
                            value:
                                'topic-1',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إضافة Home Topic',
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
                            '/api/admin/skill-placements/placement-1/home-topics',
                        data: {
                            topic_id:
                                'topic-1',
                        },
                    });
                });
            },
        );

        it(
            'keeps published placements read only',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'placement-1',
                            skill_id:
                                'skill-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            home_topics:
                                [],
                            created_at:
                                null,
                        },
                    ])
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
                    ])
                    .mockResolvedValueOnce([]);

                renderPanel({
                    ...draftVersion,
                    status:
                        'published',
                });

                expect(
                    await screen.findByText(
                        'الاستدلال النسبي',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'هذه النسخة للقراءة فقط؛ لا يمكن تغيير Skill Placements أو Home Topics.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'ربط Skill',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إزالة الربط',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );
    },
);

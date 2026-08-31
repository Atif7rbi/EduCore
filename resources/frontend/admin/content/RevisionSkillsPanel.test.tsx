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
    RevisionSkillsPanel,
} from './RevisionSkillsPanel';

import type {
    CurriculumVersion,
    LessonRevision,
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

const version:
CurriculumVersion = {
    id: 'version-1',
    curriculum_id:
        'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const revision:
LessonRevision = {
    id: 'revision-1',
    lesson_id: 'lesson-1',
    curriculum_version_id:
        'version-1',
    revision_number: 1,
    primary_topic_id:
        'topic-1',
    content_payload: {
        blocks: [],
    },
    content_schema_version: 1,
    released_at: null,
    created_at: null,
};

function renderPanel(
    currentRevision:
        LessonRevision =
            revision,
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
            <RevisionSkillsPanel
                version={version}
                revision={
                    currentRevision
                }
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe(
    'RevisionSkillsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists classified revision skills',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'classification-1',
                            lesson_revision_id:
                                'revision-1',
                            skill_version_placement_id:
                                'placement-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            created_at:
                                null,
                        },
                    ])
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

                expect(
                    await screen.findByText(
                        'الاستدلال النسبي',
                    ),
                ).toBeInTheDocument();

                expect(
                    apiRequestMock,
                ).toHaveBeenCalledWith({
                    method: 'GET',
                    url:
                        '/api/admin/lesson-revisions/revision-1/skills',
                });
            },
        );

        it(
            'adds a skill placement classification',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
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
                    .mockResolvedValueOnce({
                        id:
                            'classification-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'classification-1',
                            lesson_revision_id:
                                'revision-1',
                            skill_version_placement_id:
                                'placement-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد مهارات مصنفة لهذه المراجعة.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'Skill Placement للمراجعة',
                    ),
                    {
                        target: {
                            value:
                                'placement-1',
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
                            '/api/admin/lesson-revisions/revision-1/skills',
                        data: {
                            skill_version_placement_id:
                                'placement-1',
                        },
                    });
                });
            },
        );

        it(
            'removes a revision skill classification',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'classification-1',
                            lesson_revision_id:
                                'revision-1',
                            skill_version_placement_id:
                                'placement-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            created_at:
                                null,
                        },
                    ])
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
                    .mockResolvedValueOnce({
                        id:
                            'classification-1',
                        deleted: true,
                    })
                    .mockResolvedValueOnce([]);

                renderPanel();

                const button =
                    await screen.findByRole(
                        'button',
                        {
                            name:
                                'إزالة التصنيف',
                        },
                    );

                fireEvent.click(
                    button,
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'DELETE',
                        url:
                            '/api/admin/lesson-revisions/revision-1/skills/classification-1',
                    });
                });
            },
        );

        it(
            'keeps released revision classifications read only',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'classification-1',
                            lesson_revision_id:
                                'revision-1',
                            skill_version_placement_id:
                                'placement-1',
                            curriculum_version_id:
                                'version-1',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'الاستدلال النسبي',
                            },
                            created_at:
                                null,
                        },
                    ])
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

                renderPanel({
                    ...revision,
                    released_at:
                        '2026-08-31T00:00:00Z',
                });

                expect(
                    await screen.findByText(
                        'الاستدلال النسبي',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'هذه المراجعة محررة؛ تصنيف المهارات أصبح للقراءة فقط.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إضافة Skill',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إزالة التصنيف',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );
    },
);

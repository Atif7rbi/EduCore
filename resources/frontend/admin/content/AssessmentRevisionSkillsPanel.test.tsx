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
    AssessmentRevisionSkillsPanel,
} from './AssessmentRevisionSkillsPanel';

import type {
    AssessmentItemRevision,
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
AssessmentItemRevision = {
    id: 'revision-1',
    assessment_item_id:
        'item-1',
    curriculum_version_id:
        'version-1',
    revision_number: 1,
    primary_topic_id: null,
    difficulty: 'medium',
    content_payload: [],
    content_schema_version: 1,
    scoring_payload: [],
    scoring_schema_version: 1,
    released_at: null,
    created_at: null,
};

function renderPanel(
    currentRevision:
        AssessmentItemRevision =
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
            <AssessmentRevisionSkillsPanel
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
    'AssessmentRevisionSkillsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists classified skills and roles',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'classification-1',
                            assessment_item_revision_id:
                                'revision-1',
                            skill_version_placement_id:
                                'placement-1',
                            curriculum_version_id:
                                'version-1',
                            role:
                                'primary',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'النسب',
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
                                    'النسب',
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
                        'النسب',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'الدور: أساسية',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'adds a supporting skill classification',
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
                                    'النسب',
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
                            assessment_item_revision_id:
                                'revision-1',
                            skill_version_placement_id:
                                'placement-1',
                            curriculum_version_id:
                                'version-1',
                            role:
                                'supporting',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'النسب',
                            },
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد مهارات مصنفة لهذه الـRevision.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'مهارة مراجعة عنصر التقييم',
                    ),
                    {
                        target: {
                            value:
                                'placement-1',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'دور مهارة عنصر التقييم',
                    ),
                    {
                        target: {
                            value:
                                'supporting',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إضافة التصنيف',
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
                            '/api/admin/assessment-item-revisions/revision-1/skills',
                        data: {
                            skill_version_placement_id:
                                'placement-1',
                            role:
                                'supporting',
                        },
                    });
                });
            },
        );

        it(
            'removes a skill classification',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'classification-1',
                            assessment_item_revision_id:
                                'revision-1',
                            skill_version_placement_id:
                                'placement-1',
                            curriculum_version_id:
                                'version-1',
                            role:
                                'primary',
                            skill: {
                                id:
                                    'skill-1',
                                name:
                                    'النسب',
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
                                    'النسب',
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
                        deleted:
                            true,
                    })
                    .mockResolvedValueOnce([]);

                renderPanel();

                await screen.findByText(
                    'الدور: أساسية',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إزالة التصنيف',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'DELETE',
                        url:
                            '/api/admin/assessment-item-revisions/revision-1/skills/classification-1',
                    });
                });
            },
        );

        it(
            'keeps released revision classification read only',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce([]);

                renderPanel({
                    ...revision,
                    released_at:
                        '2026-08-31T00:00:00Z',
                });

                expect(
                    await screen.findByText(
                        'لا توجد مهارات مصنفة لهذه الـRevision.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'تصنيف المهارات للقراءة فقط؛ CurriculumVersion يجب أن تكون draft والـRevision غير محررة.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إضافة التصنيف',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );
    },
);

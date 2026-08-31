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
    AssessmentItemRevisionsPanel,
} from './AssessmentItemRevisionsPanel';

import type {
    AssessmentItem,
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

const item:
AssessmentItem = {
    id: 'item-1',
    curriculum_version_id:
        'version-1',
    item_type:
        'multiple_choice',
    internal_label:
        'سؤال النسب',
    status: 'draft',
    published_revision_id: null,
    created_at: null,
    updated_at: null,
};

function renderPanel(
    currentItem:
        AssessmentItem =
            item,
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
            <AssessmentItemRevisionsPanel
                version={version}
                item={currentItem}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe(
    'AssessmentItemRevisionsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists assessment item revisions',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            assessment_item_id:
                                'item-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                null,
                            difficulty:
                                'medium',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            scoring_payload:
                                [],
                            scoring_schema_version:
                                1,
                            released_at:
                                null,
                            created_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce([]);

                renderPanel();

                expect(
                    await screen.findByText(
                        'Revision 1',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /الصعوبة: متوسط/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a schema-neutral assessment revision',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
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
                        id:
                            'revision-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            assessment_item_id:
                                'item-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            difficulty:
                                'hard',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            scoring_payload:
                                [],
                            scoring_schema_version:
                                1,
                            released_at:
                                null,
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد Revisions لهذا العنصر.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'رقم مراجعة عنصر التقييم',
                    ),
                    {
                        target: {
                            value: '1',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'الموضوع الرئيسي لعنصر التقييم',
                    ),
                    {
                        target: {
                            value:
                                'topic-1',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'صعوبة عنصر التقييم',
                    ),
                    {
                        target: {
                            value:
                                'hard',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنشاء Revision',
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
                            '/api/admin/assessment-items/item-1/revisions',
                        data: {
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            difficulty:
                                'hard',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            scoring_payload:
                                [],
                            scoring_schema_version:
                                1,
                        },
                    });
                });
            },
        );

        it(
            'allows null primary topic',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        id:
                            'revision-1',
                    })
                    .mockResolvedValueOnce([]);

                renderPanel();

                await screen.findByText(
                    'لا توجد Revisions لهذا العنصر.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'رقم مراجعة عنصر التقييم',
                    ),
                    {
                        target: {
                            value: '2',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنشاء Revision',
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
                            '/api/admin/assessment-items/item-1/revisions',
                        data: {
                            revision_number:
                                2,
                            primary_topic_id:
                                null,
                            difficulty:
                                'medium',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            scoring_payload:
                                [],
                            scoring_schema_version:
                                1,
                        },
                    });
                });
            },
        );

        it(
            'keeps revision authoring read only for non-draft assessment item',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce([]);

                renderPanel({
                    ...item,
                    status:
                        'published',
                });

                expect(
                    await screen.findByText(
                        'لا توجد Revisions لهذا العنصر.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'إنشاء Revisions متاح فقط لعنصر تقييم draft داخل CurriculumVersion draft.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إنشاء Revision',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );
    },
);

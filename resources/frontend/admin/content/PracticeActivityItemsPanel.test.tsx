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
    PracticeActivityItemsPanel,
} from './PracticeActivityItemsPanel';

import type {
    CurriculumVersion,
    PracticeActivity,
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

const activity:
PracticeActivity = {
    id: 'activity-1',
    curriculum_version_id:
        'version-1',
    lesson_id: null,
    name: 'تدريب النسب',
    description: null,
    status: 'archived',
    items_count: 0,
    created_at: null,
    updated_at: null,
};

const assessmentItem = {
    id: 'assessment-1',
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

const releasedRevision = {
    id: 'revision-released',
    assessment_item_id:
        'assessment-1',
    curriculum_version_id:
        'version-1',
    revision_number: 1,
    primary_topic_id: null,
    difficulty: 'easy',
    content_payload: [],
    content_schema_version: 1,
    scoring_payload: [],
    scoring_schema_version: 1,
    released_at:
        '2026-08-31T00:00:00Z',
    created_at: null,
};

const unreleasedRevision = {
    ...releasedRevision,
    id: 'revision-unreleased',
    revision_number: 2,
    released_at: null,
};

function renderPanel(
    currentActivity:
        PracticeActivity =
            activity,
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
            <PracticeActivityItemsPanel
                version={version}
                activity={
                    currentActivity
                }
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe(
    'PracticeActivityItemsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists membership in backend display order',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/practice-activities/activity-1/items'
                        ) {
                            return Promise.resolve([
                                {
                                    id: 'membership-1',
                                    practice_activity_id:
                                        'activity-1',
                                    assessment_item_revision_id:
                                        'revision-released',
                                    assessment_item_id:
                                        'assessment-1',
                                    curriculum_version_id:
                                        'version-1',
                                    display_order: 3,
                                    revision: {
                                        id:
                                            'revision-released',
                                        assessment_item_id:
                                            'assessment-1',
                                        revision_number:
                                            1,
                                        difficulty:
                                            'easy',
                                        released_at:
                                            '2026-08-31T00:00:00Z',
                                    },
                                    created_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/assessment-items'
                        ) {
                            return Promise.resolve([
                                assessmentItem,
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                expect(
                    await screen.findByText(
                        'Revision 1',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /الترتيب: 3/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'adds a revision using only revision id and display order',
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
                                === '/api/admin/practice-activities/activity-1/items'
                        ) {
                            return Promise.resolve([]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/assessment-items'
                        ) {
                            return Promise.resolve([
                                assessmentItem,
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/assessment-items/assessment-1/revisions'
                        ) {
                            return Promise.resolve([
                                releasedRevision,
                                unreleasedRevision,
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/practice-activities/activity-1/items'
                        ) {
                            expect(data).toEqual({
                                assessment_item_revision_id:
                                    'revision-unreleased',
                                display_order: 4,
                            });

                            return Promise.resolve({
                                id: 'membership-1',
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'لا توجد عناصر في مجموعة التدريب.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'عنصر تقييم لمجموعة التدريب',
                    ),
                    {
                        target: {
                            value:
                                'assessment-1',
                        },
                    },
                );

                expect(
                    await screen.findByRole(
                        'option',
                        {
                            name:
                                /Revision 2/,
                        },
                    ),
                ).toBeInTheDocument();

                fireEvent.change(
                    screen.getByLabelText(
                        'مراجعة عنصر التقييم لمجموعة التدريب',
                    ),
                    {
                        target: {
                            value:
                                'revision-unreleased',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'ترتيب عنصر مجموعة التدريب',
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
                            name:
                                'إضافة العنصر',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'POST',
                        url:
                            '/api/admin/practice-activities/activity-1/items',
                        data: {
                            assessment_item_revision_id:
                                'revision-unreleased',
                            display_order:
                                4,
                        },
                    });
                });
            },
        );

        it(
            'offers released revisions only for an active activity',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/practice-activities/activity-1/items'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'membership-1',
                                    practice_activity_id:
                                        'activity-1',
                                    assessment_item_revision_id:
                                        'revision-released',
                                    assessment_item_id:
                                        'assessment-1',
                                    curriculum_version_id:
                                        'version-1',
                                    display_order:
                                        0,
                                    revision: {
                                        id:
                                            'revision-released',
                                        assessment_item_id:
                                            'assessment-1',
                                        revision_number:
                                            1,
                                        difficulty:
                                            'easy',
                                        released_at:
                                            '2026-08-31T00:00:00Z',
                                    },
                                    created_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/assessment-items'
                        ) {
                            return Promise.resolve([
                                assessmentItem,
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/assessment-items/assessment-1/revisions'
                        ) {
                            return Promise.resolve([
                                releasedRevision,
                                unreleasedRevision,
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel({
                    ...activity,
                    status: 'active',
                    items_count: 1,
                });

                expect(
                    await screen.findByRole(
                        'option',
                        {
                            name:
                                'سؤال النسب',
                        },
                    ),
                ).toBeInTheDocument();

                fireEvent.change(
                    screen.getByLabelText(
                        'عنصر تقييم لمجموعة التدريب',
                    ),
                    {
                        target: {
                            value:
                                'assessment-1',
                        },
                    },
                );

                expect(
                    await screen.findByRole(
                        'option',
                        {
                            name:
                                /Revision 1/,
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'option',
                        {
                            name:
                                /Revision 2/,
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.getByText(
                        'المجموعة النشطة تقبل released revisions فقط.',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'prevents removing the last item from an active activity',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/practice-activities/activity-1/items'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'membership-1',
                                    practice_activity_id:
                                        'activity-1',
                                    assessment_item_revision_id:
                                        'revision-released',
                                    assessment_item_id:
                                        'assessment-1',
                                    curriculum_version_id:
                                        'version-1',
                                    display_order:
                                        0,
                                    revision: {
                                        id:
                                            'revision-released',
                                        assessment_item_id:
                                            'assessment-1',
                                        revision_number:
                                            1,
                                        difficulty:
                                            'easy',
                                        released_at:
                                            '2026-08-31T00:00:00Z',
                                    },
                                    created_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/assessment-items'
                        ) {
                            return Promise.resolve([
                                assessmentItem,
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel({
                    ...activity,
                    status: 'active',
                    items_count: 1,
                });

                const removeButton =
                    await screen.findByRole(
                        'button',
                        {
                            name:
                                'إزالة العنصر',
                        },
                    );

                expect(
                    removeButton,
                ).toBeDisabled();

                expect(
                    screen.getByText(
                        'لا يمكن إزالة آخر عنصر من Practice Activity نشطة.',
                    ),
                ).toBeInTheDocument();
            },
        );
    },
);

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
    apiRequest: (config: RequestConfig) =>
        apiRequestMock(config),
}));

const version: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const activity: PracticeActivity = {
    id: 'activity-1',
    curriculum_version_id: 'version-1',
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
    curriculum_version_id: 'version-1',
    item_type: 'multiple_choice',
    internal_label: 'سؤال النسب',
    status: 'draft',
    published_revision_id: null,
    created_at: null,
    updated_at: null,
};

const releasedRevision = {
    id: 'revision-released',
    assessment_item_id: 'assessment-1',
    curriculum_version_id: 'version-1',
    revision_number: 1,
    primary_topic_id: null,
    difficulty: 'easy',
    content_payload: [],
    content_schema_version: 1,
    scoring_payload: [],
    scoring_schema_version: 1,
    released_at: '2026-08-31T00:00:00Z',
    created_at: null,
};

const unreleasedRevision = {
    ...releasedRevision,
    id: 'revision-unreleased',
    revision_number: 2,
    released_at: null,
};

function renderPanel(
    currentActivity: PracticeActivity = activity,
) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <PracticeActivityItemsPanel
                version={version}
                activity={currentActivity}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

function installBaseMock({
    items = [],
    revisions = [
        releasedRevision,
        unreleasedRevision,
    ],
}: {
    items?: unknown[];
    revisions?: unknown[];
} = {}) {
    apiRequestMock.mockImplementation(
        ({ method, url, data }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/practice-activities/activity-1/items'
            ) {
                return Promise.resolve(items);
            }

            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/assessment-items'
            ) {
                return Promise.resolve([assessmentItem]);
            }

            if (
                method === 'GET'
                && url === '/api/admin/assessment-items/assessment-1/revisions'
            ) {
                return Promise.resolve(revisions);
            }

            if (
                method === 'POST'
                && url === '/api/admin/practice-activities/activity-1/items'
            ) {
                return Promise.resolve({
                    id: 'membership-new',
                    data,
                });
            }

            if (
                method === 'DELETE'
                && url === '/api/admin/practice-activities/activity-1/items/membership-1'
            ) {
                return Promise.resolve({ deleted: true });
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe('PracticeActivityItemsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('shows questions without revision or display-order terminology', async () => {
        installBaseMock({
            items: [
                {
                    id: 'membership-1',
                    practice_activity_id: 'activity-1',
                    assessment_item_revision_id: 'revision-released',
                    assessment_item_id: 'assessment-1',
                    curriculum_version_id: 'version-1',
                    display_order: 3,
                    revision: {
                        id: 'revision-released',
                        assessment_item_id: 'assessment-1',
                        revision_number: 1,
                        difficulty: 'easy',
                        released_at: '2026-08-31T00:00:00Z',
                    },
                    created_at: null,
                },
            ],
        });

        renderPanel();

        expect(
            await screen.findByText(/1\. سؤال النسب/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('مستوى الصعوبة: سهل'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Revision/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText(
                'ترتيب عنصر مجموعة التدريب',
            ),
        ).not.toBeInTheDocument();
    });

    it('adds the latest question content and assigns order automatically', async () => {
        installBaseMock();
        renderPanel();

        await screen.findByText(
            'لا توجد أسئلة في هذا التدريب حتى الآن.',
        );

        fireEvent.change(
            screen.getByLabelText(
                'السؤال المضاف إلى التدريب',
            ),
            {
                target: { value: 'assessment-1' },
            },
        );

        await waitFor(() => {
            expect(
                screen.getByRole('button', {
                    name: 'إضافة السؤال',
                }),
            ).not.toBeDisabled();
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إضافة السؤال',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/practice-activities/activity-1/items',
                data: {
                    assessment_item_revision_id:
                        'revision-unreleased',
                    display_order: 0,
                },
            });
        });
    });

    it('uses only approved question content for an active training', async () => {
        installBaseMock({ items: [] });

        renderPanel({
            ...activity,
            status: 'active',
            items_count: 2,
        });

        fireEvent.change(
            await screen.findByLabelText(
                'السؤال المضاف إلى التدريب',
            ),
            {
                target: { value: 'assessment-1' },
            },
        );

        await waitFor(() => {
            expect(
                screen.getByRole('button', {
                    name: 'إضافة السؤال',
                }),
            ).not.toBeDisabled();
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إضافة السؤال',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/practice-activities/activity-1/items',
                data: {
                    assessment_item_revision_id:
                        'revision-released',
                    display_order: 0,
                },
            });
        });
    });

    it('prevents removing the last question from an active training', async () => {
        installBaseMock({
            items: [
                {
                    id: 'membership-1',
                    practice_activity_id: 'activity-1',
                    assessment_item_revision_id: 'revision-released',
                    assessment_item_id: 'assessment-1',
                    curriculum_version_id: 'version-1',
                    display_order: 0,
                    revision: {
                        id: 'revision-released',
                        assessment_item_id: 'assessment-1',
                        revision_number: 1,
                        difficulty: 'easy',
                        released_at: '2026-08-31T00:00:00Z',
                    },
                    created_at: null,
                },
            ],
        });

        renderPanel({
            ...activity,
            status: 'active',
            items_count: 1,
        });

        const removeButton =
            await screen.findByRole('button', {
                name: 'إزالة السؤال',
            });

        expect(removeButton).toBeDisabled();
        expect(
            screen.getByText(
                'لا يمكن إزالة آخر سؤال من تدريب متاح للطلاب. أوقف الإتاحة أولًا إذا كنت تريد إفراغه.',
            ),
        ).toBeInTheDocument();
    });
});

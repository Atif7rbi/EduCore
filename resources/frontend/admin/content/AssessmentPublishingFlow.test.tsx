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

const version: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'مسودة العمل',
    status: 'draft',
};

const item: AssessmentItem = {
    id: 'item-1',
    curriculum_version_id: 'version-1',
    item_type: 'multiple_choice',
    internal_label: 'سؤال دورة النشر',
    status: 'draft',
    published_revision_id: null,
    created_at: null,
    updated_at: null,
};

function revision(released: boolean) {
    return {
        id: 'revision-1',
        assessment_item_id: 'item-1',
        curriculum_version_id: 'version-1',
        revision_number: 1,
        primary_topic_id: null,
        difficulty: 'medium',
        content_payload: {
            stem: 'ما أبسط صورة للنسبة 2:4؟',
            options: [
                '1:2',
                '2:3',
                '3:4',
                '4:5',
            ],
        },
        content_schema_version: 1,
        scoring_payload: {
            correct_option: 0,
        },
        scoring_schema_version: 1,
        released_at: released
            ? '2026-09-08T00:00:00Z'
            : null,
        created_at: null,
    };
}

function renderPanel() {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <AssessmentItemRevisionsPanel
                version={version}
                item={item}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe('Assessment publishing flow', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('releases question content then publishes the released revision', async () => {
        let released = false;

        apiRequestMock.mockImplementation(
            (config: RequestConfig) => {
                if (
                    config.method === 'GET'
                    && config.url
                        === '/api/admin/assessment-items/item-1/revisions'
                ) {
                    return Promise.resolve([
                        revision(released),
                    ]);
                }

                if (
                    config.method === 'GET'
                    && config.url
                        === '/api/admin/curriculum-versions/version-1/topics'
                ) {
                    return Promise.resolve([]);
                }

                if (
                    config.method === 'POST'
                    && config.url
                        === '/api/assessment-item-revisions/revision-1/release'
                ) {
                    released = true;

                    return Promise.resolve(
                        revision(true),
                    );
                }

                if (
                    config.method === 'POST'
                    && config.url
                        === '/api/assessment-items/item-1/publish'
                ) {
                    return Promise.resolve({
                        ...item,
                        status: 'published',
                        published_revision_id:
                            'revision-1',
                    });
                }

                return Promise.reject(
                    new Error(
                        `Unexpected request: ${config.method} ${config.url}`,
                    ),
                );
            },
        );

        renderPanel();

        expect(
            await screen.findByText(
                'ما أبسط صورة للنسبة 2:4؟',
            ),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'اعتماد محتوى السؤال',
            }),
        );

        await waitFor(() => {
            expect(
                apiRequestMock,
            ).toHaveBeenCalledWith({
                method: 'POST',
                url:
                    '/api/assessment-item-revisions/revision-1/release',
            });
        });

        expect(
            await screen.findByRole(
                'button',
                {
                    name: 'عرض المهارات',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole(
                'button',
                {
                    name: 'اعتماد محتوى السؤال',
                },
            ),
        ).not.toBeInTheDocument();

        const publishButton =
            await screen.findByRole(
                'button',
                {
                    name: 'نشر السؤال',
                },
            );

        fireEvent.click(publishButton);

        await waitFor(() => {
            expect(
                apiRequestMock,
            ).toHaveBeenCalledWith({
                method: 'POST',
                url:
                    '/api/assessment-items/item-1/publish',
                data: {
                    published_revision_id:
                        'revision-1',
                },
            });
        });
    });
});

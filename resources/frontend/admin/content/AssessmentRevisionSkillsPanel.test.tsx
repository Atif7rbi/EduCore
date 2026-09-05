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

const version: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const revision: AssessmentItemRevision = {
    id: 'revision-1',
    assessment_item_id: 'item-1',
    curriculum_version_id: 'version-1',
    revision_number: 1,
    primary_topic_id: null,
    difficulty: 'medium',
    content_payload: {
        stem: 'سؤال',
        options: ['أ', 'ب', 'ج', 'د'],
    },
    content_schema_version: 1,
    scoring_payload: {
        correct_option: 0,
    },
    scoring_schema_version: 1,
    released_at: null,
    created_at: null,
};

function renderPanel(
    currentRevision: AssessmentItemRevision =
        revision,
) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <AssessmentRevisionSkillsPanel
                version={version}
                revision={currentRevision}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe('AssessmentRevisionSkillsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('lists linked skills using Arabic role labels', async () => {
        apiRequestMock
            .mockResolvedValueOnce([
                {
                    id: 'classification-1',
                    assessment_item_revision_id:
                        'revision-1',
                    skill_version_placement_id:
                        'placement-1',
                    curriculum_version_id:
                        'version-1',
                    role: 'primary',
                    skill: {
                        id: 'skill-1',
                        name: 'النسب',
                    },
                    created_at: null,
                },
            ])
            .mockResolvedValueOnce([]);

        renderPanel();

        expect(
            await screen.findByText('النسب'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('أساسية'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                name: 'ربط المهارات بالسؤال',
            }),
        ).toBeInTheDocument();
    });

    it('links a supporting skill without technical terminology', async () => {
        apiRequestMock
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce([
                {
                    id: 'placement-1',
                    skill_id: 'skill-1',
                    curriculum_version_id:
                        'version-1',
                    skill: {
                        id: 'skill-1',
                        name: 'النسب',
                    },
                    home_topics: [],
                    created_at: null,
                },
            ])
            .mockResolvedValueOnce({
                id: 'classification-1',
            })
            .mockResolvedValueOnce([]);

        renderPanel();

        await screen.findByText(
            'لم يتم ربط مهارات بهذا السؤال حتى الآن.',
        );

        expect(
            screen.queryByText(/Revision/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Skill Placement/),
        ).not.toBeInTheDocument();

        fireEvent.change(
            screen.getByLabelText(
                'المهارة المرتبطة بالسؤال',
            ),
            {
                target: {
                    value: 'placement-1',
                },
            },
        );
        fireEvent.change(
            screen.getByLabelText(
                'أهمية المهارة في السؤال',
            ),
            {
                target: {
                    value: 'supporting',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'ربط المهارة',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/assessment-item-revisions/revision-1/skills',
                data: {
                    skill_version_placement_id:
                        'placement-1',
                    role: 'supporting',
                },
            });
        });
    });

    it('removes a linked skill', async () => {
        apiRequestMock
            .mockResolvedValueOnce([
                {
                    id: 'classification-1',
                    assessment_item_revision_id:
                        'revision-1',
                    skill_version_placement_id:
                        'placement-1',
                    curriculum_version_id:
                        'version-1',
                    role: 'primary',
                    skill: {
                        id: 'skill-1',
                        name: 'النسب',
                    },
                    created_at: null,
                },
            ])
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce({
                id: 'classification-1',
                deleted: true,
            })
            .mockResolvedValueOnce([]);

        renderPanel();

        await screen.findByText('أساسية');

        fireEvent.click(
            screen.getByRole('button', {
                name: 'إزالة الربط',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'DELETE',
                url: '/api/admin/assessment-item-revisions/revision-1/skills/classification-1',
            });
        });
    });

    it('keeps released question skill links read only', async () => {
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
                'لم يتم ربط مهارات بهذا السؤال حتى الآن.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'روابط المهارات للقراءة فقط بعد اعتماد محتوى السؤال أو إغلاق المنهج للتعديل.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'ربط المهارة',
            }),
        ).not.toBeInTheDocument();
    });
});

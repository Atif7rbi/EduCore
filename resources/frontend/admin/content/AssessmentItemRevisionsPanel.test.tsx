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
    label: 'الإصدار الأول',
    status: 'draft',
};

const item: AssessmentItem = {
    id: 'item-1',
    curriculum_version_id: 'version-1',
    item_type: 'multiple_choice',
    internal_label: 'سؤال النسب',
    status: 'draft',
    published_revision_id: null,
    created_at: null,
    updated_at: null,
};

function renderPanel(
    currentItem: AssessmentItem = item,
) {
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
                item={currentItem}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe('AssessmentItemRevisionsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('lists saved question content without technical revision or schema labels', async () => {
        apiRequestMock
            .mockResolvedValueOnce([
                {
                    id: 'revision-1',
                    assessment_item_id: 'item-1',
                    curriculum_version_id: 'version-1',
                    revision_number: 1,
                    primary_topic_id: null,
                    difficulty: 'medium',
                    content_payload: {
                        stem: 'ما النسبة المكافئة لـ 2:4؟',
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
                    released_at: null,
                    created_at: null,
                },
            ])
            .mockResolvedValueOnce([]);

        renderPanel();

        expect(
            await screen.findByText(
                'ما النسبة المكافئة لـ 2:4؟',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/الصعوبة: متوسط/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Revision/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Schema/),
        ).not.toBeInTheDocument();
    });

    it('creates the next multiple-choice question content using the learner runtime contract', async () => {
        apiRequestMock
            .mockResolvedValueOnce([
                {
                    id: 'revision-1',
                    assessment_item_id: 'item-1',
                    curriculum_version_id: 'version-1',
                    revision_number: 1,
                    primary_topic_id: null,
                    difficulty: 'medium',
                    content_payload: {
                        stem: 'سؤال سابق',
                        options: ['أ', 'ب', 'ج', 'د'],
                    },
                    content_schema_version: 1,
                    scoring_payload: {
                        correct_option: 0,
                    },
                    scoring_schema_version: 1,
                    released_at: null,
                    created_at: null,
                },
            ])
            .mockResolvedValueOnce([
                {
                    id: 'topic-1',
                    curriculum_version_id: 'version-1',
                    name: 'النسب',
                    display_order: 1,
                    created_at: null,
                    updated_at: null,
                },
            ])
            .mockResolvedValueOnce({
                id: 'revision-2',
            })
            .mockResolvedValueOnce([]);

        renderPanel();

        await screen.findByText('سؤال سابق');

        fireEvent.change(
            screen.getByLabelText('نص السؤال'),
            {
                target: {
                    value: 'ما أبسط صورة للنسبة 2:4؟',
                },
            },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الأول'),
            { target: { value: '1:2' } },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الثاني'),
            { target: { value: '2:3' } },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الثالث'),
            { target: { value: '3:4' } },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الرابع'),
            { target: { value: '4:5' } },
        );
        fireEvent.change(
            screen.getByLabelText('الإجابة الصحيحة'),
            { target: { value: '0' } },
        );
        fireEvent.change(
            screen.getByLabelText(
                'الوحدة الرئيسية للسؤال',
            ),
            { target: { value: 'topic-1' } },
        );
        fireEvent.change(
            screen.getByLabelText(
                'مستوى صعوبة السؤال',
            ),
            { target: { value: 'hard' } },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ محتوى السؤال',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/assessment-items/item-1/revisions',
                data: {
                    revision_number: 2,
                    primary_topic_id: 'topic-1',
                    difficulty: 'hard',
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
                },
            });
        });
    });

    it('supports no primary unit and hides revision and schema inputs', async () => {
        apiRequestMock
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce({
                id: 'revision-1',
            })
            .mockResolvedValueOnce([]);

        renderPanel();

        await screen.findByText(
            'لم تتم إضافة محتوى لهذا السؤال حتى الآن.',
        );

        expect(
            screen.queryByLabelText(
                'رقم مراجعة عنصر التقييم',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText(
                'إصدار مخطط محتوى عنصر التقييم',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText(
                'بيانات تصحيح عنصر التقييم',
            ),
        ).not.toBeInTheDocument();

        fireEvent.change(
            screen.getByLabelText('نص السؤال'),
            { target: { value: 'سؤال جديد' } },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الأول'),
            { target: { value: 'أ' } },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الثاني'),
            { target: { value: 'ب' } },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الثالث'),
            { target: { value: 'ج' } },
        );
        fireEvent.change(
            screen.getByLabelText('الخيار الرابع'),
            { target: { value: 'د' } },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ محتوى السؤال',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/assessment-items/item-1/revisions',
                data: {
                    revision_number: 1,
                    primary_topic_id: null,
                    difficulty: 'medium',
                    content_payload: {
                        stem: 'سؤال جديد',
                        options: ['أ', 'ب', 'ج', 'د'],
                    },
                    content_schema_version: 1,
                    scoring_payload: {
                        correct_option: 0,
                    },
                    scoring_schema_version: 1,
                },
            });
        });
    });

    it('keeps question content read only for non-draft assessment item', async () => {
        apiRequestMock
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce([]);

        renderPanel({
            ...item,
            status: 'published',
        });

        expect(
            await screen.findByText(
                'لم تتم إضافة محتوى لهذا السؤال حتى الآن.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'هذا السؤال للقراءة فقط ولا يمكن إضافة تعديلات جديدة إليه.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'حفظ محتوى السؤال',
            }),
        ).not.toBeInTheDocument();
    });
});

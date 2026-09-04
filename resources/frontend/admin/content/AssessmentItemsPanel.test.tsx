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
    AssessmentItemsPanel,
} from './AssessmentItemsPanel';

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

const draftVersion: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

function renderPanel(
    version: CurriculumVersion = draftVersion,
) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <AssessmentItemsPanel version={version} />
        </QueryClientProvider>,
    );
}

describe('AssessmentItemsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('lists questions for the selected curriculum version', async () => {
        apiRequestMock.mockResolvedValueOnce([
            {
                id: 'item-1',
                curriculum_version_id: 'version-1',
                item_type: 'multiple_choice',
                internal_label: 'سؤال النسب 1',
                status: 'draft',
                published_revision_id: null,
                created_at: null,
                updated_at: null,
            },
        ]);

        renderPanel();

        expect(
            await screen.findByText('سؤال النسب 1'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'بنك الأسئلة' }),
        ).toBeInTheDocument();

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/admin/curriculum-versions/version-1/assessment-items',
        });
    });

    it('creates a draft question', async () => {
        apiRequestMock
            .mockResolvedValueOnce([])
            .mockResolvedValueOnce({ id: 'item-1' })
            .mockResolvedValueOnce([
                {
                    id: 'item-1',
                    curriculum_version_id: 'version-1',
                    item_type: 'multiple_choice',
                    internal_label: null,
                    status: 'draft',
                    published_revision_id: null,
                    created_at: null,
                    updated_at: null,
                },
            ]);

        renderPanel();

        await screen.findByText(
            'لا توجد أسئلة في هذا المنهج حتى الآن.',
        );

        fireEvent.change(
            screen.getByLabelText('نوع السؤال الجديد'),
            { target: { value: 'multiple_choice' } },
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'إضافة سؤال' }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/assessment-items',
                data: {
                    item_type: 'multiple_choice',
                    internal_label: null,
                },
            });
        });
    });

    it('updates a draft question', async () => {
        apiRequestMock
            .mockResolvedValueOnce([
                {
                    id: 'item-1',
                    curriculum_version_id: 'version-1',
                    item_type: 'multiple_choice',
                    internal_label: 'سؤال قديم',
                    status: 'draft',
                    published_revision_id: null,
                    created_at: null,
                    updated_at: null,
                },
            ])
            .mockResolvedValueOnce({ id: 'item-1' })
            .mockResolvedValueOnce([
                {
                    id: 'item-1',
                    curriculum_version_id: 'version-1',
                    item_type: 'numeric',
                    internal_label: 'سؤال محدث',
                    status: 'draft',
                    published_revision_id: null,
                    created_at: null,
                    updated_at: null,
                },
            ]);

        renderPanel();
        await screen.findByText('سؤال قديم');

        fireEvent.click(
            screen.getByRole('button', { name: 'تعديل' }),
        );
        fireEvent.change(
            screen.getByLabelText('تعديل نوع السؤال'),
            { target: { value: 'numeric' } },
        );
        fireEvent.change(
            screen.getByLabelText('تعديل عنوان السؤال في البنك'),
            { target: { value: 'سؤال محدث' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'حفظ' }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'PUT',
                url: '/api/admin/assessment-items/item-1',
                data: {
                    item_type: 'numeric',
                    internal_label: 'سؤال محدث',
                },
            });
        });
    });

    it('keeps questions read only outside draft curriculum version', async () => {
        apiRequestMock.mockResolvedValueOnce([
            {
                id: 'item-1',
                curriculum_version_id: 'version-1',
                item_type: 'multiple_choice',
                internal_label: 'سؤال منشور',
                status: 'draft',
                published_revision_id: null,
                created_at: null,
                updated_at: null,
            },
        ]);

        renderPanel({
            ...draftVersion,
            status: 'published',
        });

        expect(
            await screen.findByText('سؤال منشور'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'هذا المنهج للقراءة فقط؛ لا يمكن إنشاء الأسئلة أو تعديلها.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'إضافة سؤال' }),
        ).not.toBeInTheDocument();
    });
});

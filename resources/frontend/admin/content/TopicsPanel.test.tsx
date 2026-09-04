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

import { TopicsPanel } from './TopicsPanel';
import type { CurriculumVersion } from './types';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();
vi.mock('../../api/client', () => ({
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

const version: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

function renderPanel(currentVersion: CurriculumVersion = version) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });
    render(
        <QueryClientProvider client={client}>
            <TopicsPanel version={currentVersion} />
        </QueryClientProvider>,
    );
}

describe('TopicsPanel', () => {
    beforeEach(() => apiRequestMock.mockReset());

    it('keeps topic creation focused and Arabic', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET') return Promise.resolve([]);
            if (method === 'POST' && url === '/api/admin/curriculum-versions/version-1/topics') {
                return Promise.resolve({ id: 'topic-1' });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لا توجد موضوعات في هذا الإصدار حتى الآن.');
        expect(screen.queryByLabelText('اسم الموضوع الجديد')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة موضوع' }));
        fireEvent.change(screen.getByLabelText('اسم الموضوع الجديد'), {
            target: { value: 'النسب والتناسب' },
        });
        fireEvent.change(screen.getByLabelText('ترتيب الموضوع الجديد'), {
            target: { value: '2' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ الموضوع' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/topics',
                data: {
                    name: 'النسب والتناسب',
                    display_order: 2,
                },
            });
        });
    });

    it('keeps non-draft versions read only', async () => {
        apiRequestMock.mockResolvedValue([]);
        renderPanel({ ...version, status: 'published' });

        expect(await screen.findByText('هذا الإصدار للقراءة فقط؛ لا يمكن إضافة الموضوعات أو تعديلها.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'إضافة موضوع' })).not.toBeInTheDocument();
    });
});

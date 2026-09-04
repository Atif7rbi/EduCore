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
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('creates a unit without exposing display order', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET') return Promise.resolve([]);
            if (method === 'POST' && url === '/api/admin/curriculum-versions/version-1/topics') {
                return Promise.resolve({ id: 'topic-1' });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لا توجد وحدات في هذا المنهج حتى الآن.');
        expect(screen.getByText('اسم الوحدة أو العنوان العام الذي تندرج تحته مجموعة من الدروس.')).toBeInTheDocument();
        expect(screen.queryByLabelText('اسم الوحدة الجديدة')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة وحدة' }));
        fireEvent.change(screen.getByLabelText('اسم الوحدة الجديدة'), {
            target: { value: 'النسب والتناسب' },
        });
        expect(screen.queryByText('ترتيب الظهور')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'حفظ الوحدة' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/topics',
                data: {
                    name: 'النسب والتناسب',
                    display_order: 1,
                },
            });
        });
    });

    it('keeps non-draft versions read only', async () => {
        apiRequestMock.mockResolvedValue([]);
        renderPanel({ ...version, status: 'published' });

        expect(await screen.findByText('هذا المنهج للقراءة فقط؛ لا يمكن إضافة الوحدات أو تعديلها.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'إضافة وحدة' })).not.toBeInTheDocument();
    });
});

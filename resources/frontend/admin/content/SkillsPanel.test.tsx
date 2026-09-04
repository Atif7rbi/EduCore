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

import { SkillsPanel } from './SkillsPanel';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();
vi.mock('../../api/client', () => ({
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

function renderPanel() {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });
    render(
        <QueryClientProvider client={client}>
            <SkillsPanel />
        </QueryClientProvider>,
    );
}

describe('SkillsPanel', () => {
    beforeEach(() => apiRequestMock.mockReset());

    it('creates a skill from a collapsed Arabic form', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/skills') return Promise.resolve([]);
            if (method === 'POST' && url === '/api/admin/skills') return Promise.resolve({ id: 'skill-1' });
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لا توجد مهارات حتى الآن.');
        expect(screen.queryByLabelText('اسم المهارة الجديدة')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة مهارة' }));
        fireEvent.change(screen.getByLabelText('اسم المهارة الجديدة'), {
            target: { value: 'حل التناسب' },
        });
        fireEvent.change(screen.getByLabelText('وصف المهارة الجديدة'), {
            target: { value: 'حل العلاقات التناسبية.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ المهارة' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/skills',
                data: {
                    name: 'حل التناسب',
                    description: 'حل العلاقات التناسبية.',
                },
            });
        });
    });

    it('lists existing skills in Arabic workspace copy', async () => {
        apiRequestMock.mockResolvedValueOnce([
            {
                id: 'skill-1',
                name: 'فهم النسبة',
                description: null,
                created_at: null,
                updated_at: null,
            },
        ]);

        renderPanel();
        expect(await screen.findByText('فهم النسبة')).toBeInTheDocument();
        expect(screen.getByText('بدون وصف')).toBeInTheDocument();
        expect(screen.queryByText('Skills')).not.toBeInTheDocument();
    });
});

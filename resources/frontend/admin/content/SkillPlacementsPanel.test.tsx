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
    EduCoreApiError,
} from '../../api/errors';
import { SkillPlacementsPanel } from './SkillPlacementsPanel';
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

const skill = {
    id: 'skill-1',
    name: 'فهم النسبة',
    description: null,
    created_at: null,
    updated_at: null,
};

const topic = {
    id: 'topic-1',
    curriculum_version_id: 'version-1',
    name: 'النسب والتناسب',
    display_order: 1,
    created_at: null,
    updated_at: null,
};

const placement = {
    id: 'placement-1',
    skill_id: 'skill-1',
    curriculum_version_id: 'version-1',
    skill: { id: 'skill-1', name: 'فهم النسبة' },
    home_topics: [
        {
            id: 'home-1',
            placement_id: 'placement-1',
            topic_id: 'topic-1',
            curriculum_version_id: 'version-1',
            topic: { id: 'topic-1', name: 'النسب والتناسب' },
            created_at: null,
        },
    ],
    created_at: null,
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
            <SkillPlacementsPanel version={currentVersion} />
        </QueryClientProvider>,
    );
}

describe('SkillPlacementsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('links an existing skill to the curriculum using Arabic actions', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url.endsWith('/skill-placements')) return Promise.resolve([]);
            if (method === 'GET' && url === '/api/admin/skills') return Promise.resolve([skill]);
            if (method === 'GET' && url.endsWith('/topics')) return Promise.resolve([topic]);
            if (method === 'POST' && url.endsWith('/skill-placements')) {
                return Promise.resolve({
                    id: 'placement-1',
                    skill_id: 'skill-1',
                    curriculum_version_id: 'version-1',
                    skill: { id: 'skill-1', name: 'فهم النسبة' },
                    home_topics: [],
                    created_at: null,
                });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لم تُربط مهارات بهذا الإصدار بعد.');
        fireEvent.change(screen.getByLabelText('المهارة المراد ربطها'), {
            target: { value: 'skill-1' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'ربط المهارة بالمنهج' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/skill-placements',
                data: { skill_id: 'skill-1' },
            });
        });
    });

    it('adds a main topic to a linked skill without technical wording', async () => {
        const placementWithoutHomeTopic = {
            ...placement,
            home_topics: [],
        };

        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url.endsWith('/skill-placements')) return Promise.resolve([placementWithoutHomeTopic]);
            if (method === 'GET' && url === '/api/admin/skills') return Promise.resolve([skill]);
            if (method === 'GET' && url.endsWith('/topics')) return Promise.resolve([topic]);
            if (method === 'POST' && url === '/api/admin/skill-placements/placement-1/home-topics') {
                return Promise.resolve({ id: 'home-1' });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لم يُحدد موضوع رئيسي لهذه المهارة.');
        fireEvent.change(screen.getByLabelText('الموضوع الرئيسي للمهارة فهم النسبة'), {
            target: { value: 'topic-1' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'إضافة الموضوع الرئيسي' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/skill-placements/placement-1/home-topics',
                data: { topic_id: 'topic-1' },
            });
        });

        expect(screen.queryByText('Skill Placements')).not.toBeInTheDocument();
        expect(screen.queryByText('Home Topics')).not.toBeInTheDocument();
    });

    it('removes a curriculum skill even when it has a main topic', async () => {
        let placements = [placement];

        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url.endsWith('/skill-placements')) return Promise.resolve(placements);
            if (method === 'GET' && url === '/api/admin/skills') return Promise.resolve([skill]);
            if (method === 'GET' && url.endsWith('/topics')) return Promise.resolve([topic]);
            if (method === 'DELETE' && url === '/api/admin/skill-placements/placement-1') {
                placements = [];
                return Promise.resolve({ id: 'placement-1', deleted: true });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('فهم النسبة');
        fireEvent.click(screen.getByRole('button', { name: 'إزالة من المنهج' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'DELETE',
                url: '/api/admin/skill-placements/placement-1',
            });
        });
    });

    it('explains when a skill cannot be removed because content uses it', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url.endsWith('/skill-placements')) return Promise.resolve([placement]);
            if (method === 'GET' && url === '/api/admin/skills') return Promise.resolve([skill]);
            if (method === 'GET' && url.endsWith('/topics')) return Promise.resolve([topic]);
            if (method === 'DELETE' && url === '/api/admin/skill-placements/placement-1') {
                return Promise.reject(new EduCoreApiError({
                    code: 'skill_placement_in_use',
                    message: 'In use.',
                    status: 409,
                    requestId: 'request-1',
                }));
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('فهم النسبة');
        fireEvent.click(screen.getByRole('button', { name: 'إزالة من المنهج' }));

        expect(await screen.findByText(
            'لا يمكن إزالة المهارة لأنها مستخدمة في محتوى درس أو سؤال داخل هذا المنهج. أزل هذه الارتباطات أولًا ثم أعد المحاولة.',
        )).toBeInTheDocument();
        expect(screen.getByText(/request-1/)).toBeInTheDocument();
    });

    it('keeps curriculum skill links read only outside draft state', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url.endsWith('/skill-placements')) return Promise.resolve([]);
            if (method === 'GET' && url === '/api/admin/skills') return Promise.resolve([skill]);
            if (method === 'GET' && url.endsWith('/topics')) return Promise.resolve([topic]);
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel({ ...version, status: 'published' });
        expect(await screen.findByText('هذا الإصدار للقراءة فقط؛ لا يمكن تغيير روابط المهارات أو موضوعاتها الرئيسية.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'ربط المهارة بالمنهج' })).not.toBeInTheDocument();
    });
});

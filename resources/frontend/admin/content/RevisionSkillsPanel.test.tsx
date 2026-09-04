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

import { RevisionSkillsPanel } from './RevisionSkillsPanel';
import type {
    CurriculumVersion,
    LessonRevision,
} from './types';

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

const revision: LessonRevision = {
    id: 'revision-1',
    lesson_id: 'lesson-1',
    curriculum_version_id: 'version-1',
    revision_number: 1,
    primary_topic_id: 'topic-1',
    content_payload: {},
    content_schema_version: 1,
    released_at: null,
    created_at: null,
};

const placement = {
    id: 'placement-1',
    skill_id: 'skill-1',
    curriculum_version_id: 'version-1',
    skill: { id: 'skill-1', name: 'فهم النسبة' },
    home_topics: [],
    created_at: null,
};

function renderPanel(currentRevision: LessonRevision = revision) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });
    render(
        <QueryClientProvider client={client}>
            <RevisionSkillsPanel
                version={version}
                revision={currentRevision}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe('RevisionSkillsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('links a curriculum skill to a draft lesson content version', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/lesson-revisions/revision-1/skills') {
                return Promise.resolve([]);
            }
            if (method === 'GET' && url.endsWith('/skill-placements')) {
                return Promise.resolve([placement]);
            }
            if (method === 'POST' && url === '/api/admin/lesson-revisions/revision-1/skills') {
                return Promise.resolve({ id: 'classification-1' });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لم تُربط مهارات بهذه النسخة بعد.');
        fireEvent.change(screen.getByLabelText('المهارة المراد ربطها بالنسخة'), {
            target: { value: 'placement-1' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'ربط المهارة' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/lesson-revisions/revision-1/skills',
                data: { skill_version_placement_id: 'placement-1' },
            });
        });
    });

    it('keeps skill links read only after the content version is approved', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/lesson-revisions/revision-1/skills') {
                return Promise.resolve([]);
            }
            if (method === 'GET' && url.endsWith('/skill-placements')) {
                return Promise.resolve([placement]);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel({
            ...revision,
            released_at: '2026-09-04T00:00:00Z',
        });

        expect(await screen.findByText('هذه النسخة معتمدة؛ روابط المهارات أصبحت للقراءة فقط.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'ربط المهارة' })).not.toBeInTheDocument();
        expect(screen.queryByText('Skill Placement')).not.toBeInTheDocument();
    });
});

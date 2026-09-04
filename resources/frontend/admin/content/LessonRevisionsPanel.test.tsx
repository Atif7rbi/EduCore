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
    LessonRevisionsPanel,
} from './LessonRevisionsPanel';

import type {
    CurriculumVersion,
    Lesson,
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

vi.mock('./RevisionSkillsPanel', () => ({
    RevisionSkillsPanel: () => <div>ربط مهارات النسخة</div>,
}));

const version: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const lesson: Lesson = {
    id: 'lesson-1',
    curriculum_version_id: 'version-1',
    title: 'النسب',
    description: null,
    status: 'draft',
    display_order: 1,
    published_revision_id: null,
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

function revision(number = 1, releasedAt: string | null = null) {
    return {
        id: `revision-${number}`,
        lesson_id: 'lesson-1',
        curriculum_version_id: 'version-1',
        revision_number: number,
        primary_topic_id: 'topic-1',
        content_payload: {
            blocks: [{ type: 'text', value: 'محتوى' }],
        },
        content_schema_version: 1,
        released_at: releasedAt,
        created_at: null,
    };
}

function renderPanel(currentLesson: Lesson = lesson) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <LessonRevisionsPanel
                version={version}
                lesson={currentLesson}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

function installReads(revisions: ReturnType<typeof revision>[] = []) {
    apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
        if (method === 'GET' && url === '/api/admin/lessons/lesson-1/revisions') {
            return Promise.resolve(revisions);
        }
        if (method === 'GET' && url === '/api/admin/curriculum-versions/version-1/topics') {
            return Promise.resolve([topic]);
        }
        throw new Error(`Unexpected request ${method} ${url}`);
    });
}

describe('LessonRevisionsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('creates the next content version from normal teacher text', async () => {
        apiRequestMock.mockImplementation(({ method, url, data }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/lessons/lesson-1/revisions') {
                return Promise.resolve([revision(1, '2026-09-04T00:00:00Z')]);
            }
            if (method === 'GET' && url === '/api/admin/curriculum-versions/version-1/topics') {
                return Promise.resolve([topic]);
            }
            if (method === 'POST' && url === '/api/admin/lessons/lesson-1/revisions') {
                return Promise.resolve({ id: 'revision-2', data });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        expect(await screen.findByText('سيحفظ النظام هذه النسخة تلقائيًا برقم 2.')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('الموضوع الرئيسي للنسخة'), {
            target: { value: 'topic-1' },
        });
        fireEvent.change(screen.getByLabelText('محتوى الدرس'), {
            target: {
                value: 'الفقرة الأولى.\n\nالفقرة الثانية.',
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ نسخة جديدة' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/lessons/lesson-1/revisions',
                data: {
                    revision_number: 2,
                    primary_topic_id: 'topic-1',
                    content_payload: {
                        blocks: [
                            { type: 'text', value: 'الفقرة الأولى.' },
                            { type: 'text', value: 'الفقرة الثانية.' },
                        ],
                    },
                    content_schema_version: 1,
                },
            });
        });

        expect(screen.queryByText('Content Payload')).not.toBeInTheDocument();
        expect(screen.queryByText('Content Schema Version')).not.toBeInTheDocument();
    });

    it('uses clear Arabic lifecycle actions for content versions', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/lessons/lesson-1/revisions') {
                return Promise.resolve([revision(1)]);
            }
            if (method === 'GET' && url === '/api/admin/curriculum-versions/version-1/topics') {
                return Promise.resolve([topic]);
            }
            if (method === 'POST' && url === '/api/lesson-revisions/revision-1/release') {
                return Promise.resolve(revision(1, '2026-09-04T00:00:00Z'));
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        const button = await screen.findByRole('button', { name: 'اعتماد النسخة' });
        fireEvent.click(button);

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/lesson-revisions/revision-1/release',
            });
        });
    });

    it('publishes a draft lesson with an approved content version', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/lessons/lesson-1/revisions') {
                return Promise.resolve([revision(1, '2026-09-04T00:00:00Z')]);
            }
            if (method === 'GET' && url === '/api/admin/curriculum-versions/version-1/topics') {
                return Promise.resolve([topic]);
            }
            if (method === 'GET' && url === '/api/admin/curriculum-versions/version-1/lessons') {
                return Promise.resolve([
                    {
                        ...lesson,
                        status: 'published',
                        published_revision_id: 'revision-1',
                    },
                ]);
            }
            if (method === 'POST' && url === '/api/lessons/lesson-1/publish') {
                return Promise.resolve({
                    ...lesson,
                    status: 'published',
                    published_revision_id: 'revision-1',
                });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        const button = await screen.findByRole('button', {
            name: 'نشر الدرس بهذه النسخة',
        });
        fireEvent.click(button);

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/lessons/lesson-1/publish',
                data: { published_revision_id: 'revision-1' },
            });
        });
    });

    it('does not expose technical identifiers for a published lesson', async () => {
        installReads([revision(1, '2026-09-04T00:00:00Z')]);
        renderPanel({
            ...lesson,
            status: 'published',
            published_revision_id: 'revision-1',
        });

        expect(await screen.findByText('هذا الدرس منشور للطلاب بالنسخة المعتمدة الحالية.')).toBeInTheDocument();
        expect(screen.queryByText('revision-1')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'إيقاف الدرس' })).toBeInTheDocument();
    });
});

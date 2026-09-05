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
    RevisionSkillsPanel: () => <div>ربط المهارات</div>,
}));

const version: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const draftLesson: Lesson = {
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

const publishedLesson: Lesson = {
    ...draftLesson,
    status: 'published',
    published_revision_id: 'revision-1',
};

const topic = {
    id: 'topic-1',
    curriculum_version_id: 'version-1',
    name: 'النسب والتناسب',
    display_order: 1,
    created_at: null,
    updated_at: null,
};

function revision(
    number = 1,
    releasedAt: string | null = null,
    text = 'المحتوى المنشور',
) {
    return {
        id: `revision-${number}`,
        lesson_id: 'lesson-1',
        curriculum_version_id: 'version-1',
        revision_number: number,
        primary_topic_id: 'topic-1',
        content_payload: {
            blocks: [{ type: 'text', value: text }],
        },
        content_schema_version: 1,
        released_at: releasedAt,
        created_at: null,
    };
}

function renderPanel(currentLesson: Lesson = draftLesson) {
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

function installReads(revisions: ReturnType<typeof revision>[]) {
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

    it('shows published content without exposing revision terminology', async () => {
        installReads([
            revision(1, '2026-09-04T00:00:00Z'),
        ]);
        renderPanel(publishedLesson);

        expect(
            await screen.findByRole('heading', { name: 'المحتوى المنشور' }),
        ).toBeInTheDocument();
        expect(screen.getByText('هذا هو المحتوى الذي يراه الطلاب حاليًا.'))
            .toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'تعديل محتوى الدرس' }))
            .toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'إيقاف النشر' }))
            .toBeInTheDocument();
        expect(screen.queryByText('نسخ المحتوى')).not.toBeInTheDocument();
        expect(screen.queryByText('النسخة 1')).not.toBeInTheDocument();
        expect(screen.queryByText('revision-1')).not.toBeInTheDocument();
    });

    it('creates unpublished edits for a published lesson using teacher copy', async () => {
        let revisions = [
            revision(1, '2026-09-04T00:00:00Z'),
        ];

        apiRequestMock.mockImplementation(({
            method,
            url,
            data,
        }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/lessons/lesson-1/revisions') {
                return Promise.resolve(revisions);
            }
            if (method === 'GET' && url === '/api/admin/curriculum-versions/version-1/topics') {
                return Promise.resolve([topic]);
            }
            if (method === 'POST' && url === '/api/admin/lessons/lesson-1/revisions') {
                const created = revision(2, null, 'المحتوى المعدل');
                revisions = [...revisions, created];
                return Promise.resolve(created);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel(publishedLesson);
        await screen.findByRole('heading', { name: 'المحتوى المنشور' });
        fireEvent.click(screen.getByRole('button', { name: 'تعديل محتوى الدرس' }));

        expect(screen.getByLabelText('الوحدة الرئيسية للدرس')).toHaveValue('topic-1');
        fireEvent.change(screen.getByLabelText('محتوى الدرس'), {
            target: { value: 'المحتوى المعدل' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ التعديلات' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/lessons/lesson-1/revisions',
                data: {
                    revision_number: 2,
                    primary_topic_id: 'topic-1',
                    content_payload: {
                        blocks: [
                            { type: 'text', value: 'المحتوى المعدل' },
                        ],
                    },
                    content_schema_version: 1,
                },
            });
        });

        expect(await screen.findByText('تعديلات غير منشورة')).toBeInTheDocument();
        expect(screen.queryByText('النسخة 2')).not.toBeInTheDocument();
    });

    it('offers skill linking and approval for unpublished edits', async () => {
        installReads([
            revision(1, '2026-09-04T00:00:00Z'),
            revision(2, null, 'تعديلات'),
        ]);
        renderPanel(publishedLesson);

        expect(await screen.findByText('تعديلات غير منشورة')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'ربط المهارات' }))
            .toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'اعتماد التعديلات' }))
            .toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'اعتماد النسخة' }))
            .not.toBeInTheDocument();
    });

    it('publishes approved edits without exposing internal revision labels', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/lessons/lesson-1/revisions') {
                return Promise.resolve([
                    revision(1, '2026-09-04T00:00:00Z'),
                    revision(2, '2026-09-05T00:00:00Z', 'تعديلات معتمدة'),
                ]);
            }
            if (method === 'GET' && url === '/api/admin/curriculum-versions/version-1/topics') {
                return Promise.resolve([topic]);
            }
            if (method === 'POST' && url === '/api/lessons/lesson-1/publish') {
                return Promise.resolve({
                    ...publishedLesson,
                    published_revision_id: 'revision-2',
                });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel(publishedLesson);
        const button = await screen.findByRole('button', {
            name: 'نشر التعديلات',
        });
        fireEvent.click(button);

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/lessons/lesson-1/publish',
                data: { published_revision_id: 'revision-2' },
            });
        });

        expect(screen.queryByText('النسخة 2')).not.toBeInTheDocument();
    });

    it('uses a simple initial content action for a new draft lesson', async () => {
        installReads([]);
        renderPanel();

        expect(await screen.findByText('لم يُضف محتوى لهذا الدرس بعد.'))
            .toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'إضافة محتوى الدرس' }))
            .toBeInTheDocument();
        expect(screen.queryByText('نسخة جديدة من المحتوى')).not.toBeInTheDocument();
    });
});

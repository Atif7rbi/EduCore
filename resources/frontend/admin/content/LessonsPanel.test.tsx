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
    LessonsPanel,
} from './LessonsPanel';

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
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

vi.mock('./LessonRevisionsPanel', () => ({
    LessonRevisionsPanel: ({ lesson }: {
        lesson: { title: string; status: string };
    }) => (
        <div data-testid="lesson-authoring">
            {lesson.title} · {lesson.status}
        </div>
    ),
}));

const draftVersion: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

function lesson(status: 'draft' | 'published' = 'draft') {
    return {
        id: 'lesson-1',
        curriculum_version_id: 'version-1',
        title: 'النسب والتناسب',
        description: 'مقدمة في النسب.',
        status,
        display_order: 1,
        published_revision_id:
            status === 'published' ? 'revision-1' : null,
        created_at: null,
        updated_at: null,
    };
}

function renderPanel(version: CurriculumVersion = draftVersion) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <LessonsPanel version={version} />
        </QueryClientProvider>,
    );
}

describe('LessonsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('uses the lesson title as the single entry point to details', async () => {
        apiRequestMock.mockResolvedValue([lesson()]);
        renderPanel();

        expect(await screen.findByText('النسب والتناسب')).toBeInTheDocument();
        expect(screen.getByRole('button', {
            name: 'فتح الدرس النسب والتناسب',
        })).toBeInTheDocument();
        expect(screen.queryByRole('button', {
            name: 'إدارة الدرس',
        })).not.toBeInTheDocument();
        expect(screen.queryByText('إجراءات')).not.toBeInTheDocument();
        expect(screen.getByText('ترتيب الدروس')).toBeInTheDocument();
        expect(screen.queryByText('ترتيب الظهور')).not.toBeInTheDocument();
    });

    it('creates a lesson with automatic next order', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET') return Promise.resolve([]);
            if (
                method === 'POST'
                && url === '/api/admin/curriculum-versions/version-1/lessons'
            ) return Promise.resolve({ id: 'lesson-1' });
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لا توجد دروس حتى الآن.');
        fireEvent.click(screen.getByRole('button', { name: 'إضافة درس' }));
        fireEvent.change(screen.getByLabelText('عنوان الدرس الجديد'), {
            target: { value: 'النسب' },
        });
        expect(screen.queryByLabelText('ترتيب الدرس الجديد')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'حفظ الدرس' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/lessons',
                data: {
                    title: 'النسب',
                    description: null,
                    display_order: 1,
                },
            });
        });
    });

    it('allows metadata editing for a published lesson in the draft curriculum', async () => {
        let current = lesson('published');
        apiRequestMock.mockImplementation(({
            method,
            url,
            data,
        }: RequestConfig) => {
            if (method === 'GET') return Promise.resolve([current]);
            if (method === 'PUT' && url === '/api/admin/lessons/lesson-1') {
                expect(data).toEqual({
                    title: 'النسب المعدلة',
                    description: 'مقدمة في النسب.',
                    display_order: 1,
                });
                current = {
                    ...current,
                    title: 'النسب المعدلة',
                };
                return Promise.resolve(current);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('النسب والتناسب');
        fireEvent.click(screen.getByRole('button', {
            name: 'فتح الدرس النسب والتناسب',
        }));

        expect(screen.getByRole('button', {
            name: 'تعديل بيانات الدرس',
        })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', {
            name: 'تعديل بيانات الدرس',
        }));
        fireEvent.change(screen.getByLabelText('تعديل عنوان الدرس'), {
            target: { value: 'النسب المعدلة' },
        });
        fireEvent.click(screen.getByRole('button', {
            name: 'حفظ التعديلات',
        }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'PUT',
                url: '/api/admin/lessons/lesson-1',
                data: {
                    title: 'النسب المعدلة',
                    description: 'مقدمة في النسب.',
                    display_order: 1,
                },
            });
        });
    });

    it('filters lessons locally by search and lifecycle', async () => {
        apiRequestMock.mockResolvedValue([
            lesson('draft'),
            {
                ...lesson('published'),
                id: 'lesson-2',
                title: 'الجبر',
                description: 'مقدمة في الجبر.',
                display_order: 2,
            },
        ]);

        renderPanel();
        await screen.findByText('النسب والتناسب');
        fireEvent.change(screen.getByLabelText('البحث في الدروس'), {
            target: { value: 'الجبر' },
        });
        expect(screen.getByText('الجبر')).toBeInTheDocument();
        expect(screen.queryByText('النسب والتناسب')).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('البحث في الدروس'), {
            target: { value: '' },
        });
        fireEvent.change(screen.getByLabelText('تصفية حالة الدروس'), {
            target: { value: 'published' },
        });
        expect(screen.getByText('الجبر')).toBeInTheDocument();
        expect(screen.queryByText('النسب والتناسب')).not.toBeInTheDocument();
    });

    it('keeps lessons read only when the curriculum version is not draft', async () => {
        apiRequestMock.mockResolvedValue([lesson('published')]);
        renderPanel({ ...draftVersion, status: 'published' });

        expect(await screen.findByText('النسب والتناسب')).toBeInTheDocument();
        expect(screen.getByText(
            'هذا المنهج للقراءة فقط؛ لا يمكن إنشاء الدروس أو تعديلها.',
        )).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'إضافة درس' }))
            .not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', {
            name: 'فتح الدرس النسب والتناسب',
        }));
        expect(screen.queryByRole('button', {
            name: 'تعديل بيانات الدرس',
        })).not.toBeInTheDocument();
    });
});

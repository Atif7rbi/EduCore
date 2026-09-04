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
    LessonRevisionsPanel: ({
        lesson,
    }: {
        lesson: {
            title: string;
            status: string;
        };
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

    it('shows the lesson workspace without exposing the create form by default', async () => {
        apiRequestMock.mockResolvedValue([lesson()]);
        renderPanel();

        expect(await screen.findByText('النسب والتناسب')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'إضافة درس' })).toBeInTheDocument();
        expect(screen.queryByLabelText('عنوان الدرس الجديد')).not.toBeInTheDocument();
    });

    it('creates a lesson from the focused create form', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET') return Promise.resolve([]);
            if (method === 'POST' && url === '/api/admin/curriculum-versions/version-1/lessons') {
                return Promise.resolve({ id: 'lesson-1' });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لا توجد دروس في هذا الإصدار حتى الآن.');
        fireEvent.click(screen.getByRole('button', { name: 'إضافة درس' }));
        fireEvent.change(screen.getByLabelText('عنوان الدرس الجديد'), {
            target: { value: 'النسب' },
        });
        fireEvent.change(screen.getByLabelText('ترتيب الدرس الجديد'), {
            target: { value: '3' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ الدرس' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/lessons',
                data: {
                    title: 'النسب',
                    description: null,
                    display_order: 3,
                },
            });
        });
    });

    it('keeps the opened lesson synchronized with the refreshed lessons query', async () => {
        let current = lesson('draft');
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET') return Promise.resolve([current]);
            if (method === 'PUT' && url === '/api/admin/lessons/lesson-1') {
                current = lesson('published');
                return Promise.resolve(current);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('النسب والتناسب');
        fireEvent.click(screen.getByRole('button', { name: 'إدارة الدرس' }));
        expect(screen.getByTestId('lesson-authoring')).toHaveTextContent('draft');

        fireEvent.click(screen.getByRole('button', { name: 'تعديل البيانات' }));
        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));

        await waitFor(() => {
            expect(screen.getByTestId('lesson-authoring')).toHaveTextContent('published');
        });
    });

    it('keeps lessons read only when the curriculum version is not draft', async () => {
        apiRequestMock.mockResolvedValue([lesson('published')]);
        renderPanel({ ...draftVersion, status: 'published' });

        expect(await screen.findByText('النسب والتناسب')).toBeInTheDocument();
        expect(screen.getByText('هذا الإصدار للقراءة فقط؛ لا يمكن إنشاء الدروس أو تعديلها.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'إضافة درس' })).not.toBeInTheDocument();
    });
});

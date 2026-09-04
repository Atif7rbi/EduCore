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
    AdminContentPage,
} from './AdminContentPage';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

vi.mock('./content/TopicsPanel', () => ({
    TopicsPanel: () => <div data-testid="topics-panel">لوحة الموضوعات</div>,
}));

vi.mock('./content/SkillsPanel', () => ({
    SkillsPanel: () => <div data-testid="skills-panel">لوحة المهارات</div>,
}));

vi.mock('./content/SkillPlacementsPanel', () => ({
    SkillPlacementsPanel: ({ version }: {
        version: { status: 'draft' | 'published' | 'retired' };
    }) => (
        <div
            data-testid="placements-panel"
            data-version-status={version.status}
        >
            ربط المهارات
        </div>
    ),
}));

vi.mock('./content/LessonsPanel', () => ({
    LessonsPanel: () => <div data-testid="lessons-panel">لوحة الدروس</div>,
}));

vi.mock('./content/AssessmentItemsPanel', () => ({
    AssessmentItemsPanel: () => <div data-testid="assessment-items-panel">بنك الأسئلة</div>,
}));

vi.mock('./content/PracticeActivitiesPanel', () => ({
    PracticeActivitiesPanel: () => <div data-testid="practice-activities-panel">لوحة التدريبات</div>,
}));

vi.mock('./content/ExamTemplatesPanel', () => ({
    ExamTemplatesPanel: () => <div data-testid="exam-templates-panel">لوحة الاختبارات</div>,
}));

function renderPage() {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <AdminContentPage />
        </QueryClientProvider>,
    );
}

function installContext(status: 'draft' | 'published' | 'retired' = 'draft') {
    apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
        if (method === 'GET' && url === '/api/admin/subjects') {
            return Promise.resolve([
                {
                    id: 'subject-1',
                    name: 'القدرات الكمية',
                    created_at: null,
                    updated_at: null,
                },
            ]);
        }

        if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
            return Promise.resolve([
                {
                    id: 'curriculum-1',
                    subject_id: 'subject-1',
                    name: 'المنهج الكمي',
                    created_at: null,
                    updated_at: null,
                },
            ]);
        }

        if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
            return Promise.resolve([
                {
                    id: 'version-1',
                    curriculum_id: 'curriculum-1',
                    version_number: 1,
                    label: 'الإصدار الأول',
                    status,
                },
            ]);
        }

        throw new Error(`Unexpected request ${method} ${url}`);
    });
}

describe('AdminContentPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('opens the redesigned workspace on lessons with compact context', async () => {
        installContext();
        renderPage();

        expect(
            await screen.findByRole('heading', { name: 'إدارة المحتوى' }),
        ).toBeInTheDocument();

        expect(await screen.findByTestId('lessons-panel')).toBeInTheDocument();
        expect(screen.queryByTestId('topics-panel')).not.toBeInTheDocument();
        expect(screen.getByLabelText('المادة')).toHaveValue('subject-1');
        expect(screen.getByLabelText('المنهج')).toHaveValue('curriculum-1');
        expect(screen.getByText('مسودة')).toBeInTheDocument();
        expect(screen.queryByText('الإصدار الأول')).not.toBeInTheDocument();
    });

    it('shows one authoring section at a time and keeps placements inside skills', async () => {
        installContext();
        renderPage();

        await screen.findByTestId('lessons-panel');

        fireEvent.click(screen.getByRole('tab', { name: 'المهارات' }));

        expect(await screen.findByTestId('skills-panel')).toBeInTheDocument();
        expect(screen.getByTestId('placements-panel')).toHaveAttribute(
            'data-version-status',
            'draft',
        );
        expect(screen.queryByTestId('lessons-panel')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('tab', { name: 'الموضوعات' }));
        expect(await screen.findByTestId('topics-panel')).toBeInTheDocument();
        expect(screen.queryByTestId('skills-panel')).not.toBeInTheDocument();
    });

    it('resets curriculum context when the subject changes', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/subjects') {
                return Promise.resolve([
                    { id: 'subject-1', name: 'القدرات الكمية' },
                    { id: 'subject-2', name: 'القدرات اللفظية' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
                return Promise.resolve([
                    { id: 'curriculum-1', subject_id: 'subject-1', name: 'المنهج الكمي' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
                return Promise.resolve([
                    { id: 'version-1', curriculum_id: 'curriculum-1', version_number: 1, label: 'الأول', status: 'draft' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-2/curricula') {
                return Promise.resolve([
                    { id: 'curriculum-2', subject_id: 'subject-2', name: 'المنهج اللفظي' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-2/versions') {
                return Promise.resolve([
                    { id: 'version-2', curriculum_id: 'curriculum-2', version_number: 1, label: 'الأول', status: 'draft' },
                ]);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPage();
        await screen.findByTestId('lessons-panel');

        fireEvent.change(screen.getByLabelText('المادة'), {
            target: { value: 'subject-2' },
        });

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'GET',
                url: '/api/admin/subjects/subject-2/curricula',
            });
        });

        await waitFor(() => {
            expect(screen.getByLabelText('المنهج')).toHaveValue('curriculum-2');
        });
    });

    it('exposes published lifecycle as a compact status while preserving read-only context', async () => {
        installContext('published');
        renderPage();

        expect(await screen.findByText('منشور')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'المهارات' }));
        expect(await screen.findByTestId('placements-panel')).toHaveAttribute(
            'data-version-status',
            'published',
        );
    });

    it('exposes retired lifecycle as stopped without surfacing internal version labels', async () => {
        installContext('retired');
        renderPage();

        expect(await screen.findByText('موقوف')).toBeInTheDocument();
        expect(screen.queryByText('الإصدار الأول')).not.toBeInTheDocument();
    });
});

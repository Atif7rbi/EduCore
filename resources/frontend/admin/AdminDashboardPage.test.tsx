import {
    render,
    screen,
} from '@testing-library/react';
import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    MemoryRouter,
} from 'react-router-dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    AdminDashboardPage,
} from './AdminDashboardPage';

interface RequestConfig {
    method: string;
    url: string;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

function renderPage() {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
        },
    });

    render(
        <MemoryRouter>
            <QueryClientProvider client={client}>
                <AdminDashboardPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

const summary = {
    counts: {
        subjects: 2,
        curricula: 3,
        curriculum_versions: 4,
        topics: 8,
        lessons: 12,
        skills: 9,
        assessment_items: 30,
        practice_activities: 6,
        exam_templates: 5,
        learners: 24,
    },
    readiness: {
        published_curriculum_versions: 2,
        published_lessons: 7,
        active_practice_activities: 4,
        active_exam_templates: 3,
    },
};

describe('AdminDashboardPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('renders live platform counts and readiness indicators', async () => {
        apiRequestMock.mockResolvedValue(summary);

        renderPage();

        expect(await screen.findByRole('heading', { name: 'المؤشرات الرئيسية' }))
            .toBeInTheDocument();
        expect(screen.getByText('الطلاب')).toBeInTheDocument();
        expect(screen.getByText('الدروس')).toBeInTheDocument();
        expect(screen.getByText('الوحدات')).toBeInTheDocument();
        expect(screen.getByText('الاختبارات')).toBeInTheDocument();
        expect(screen.getByText('جاهزية المحتوى')).toBeInTheDocument();
        expect(screen.getByText('مخزون التأليف')).toBeInTheDocument();
        expect(screen.getByText('٢٤')).toBeInTheDocument();
        expect(screen.getByText('١٢')).toBeInTheDocument();
        expect(screen.getByText('٧')).toBeInTheDocument();

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/admin/dashboard',
        });
    });

    it('keeps the main management destinations available as quick actions', async () => {
        apiRequestMock.mockResolvedValue(summary);

        renderPage();
        await screen.findByText('المؤشرات الرئيسية');

        expect(screen.getAllByRole('link', { name: /إدارة المناهج/ }).length)
            .toBeGreaterThan(0);
        expect(screen.getAllByRole('link', { name: /إدارة المحتوى/ }).length)
            .toBeGreaterThan(0);
    });
});

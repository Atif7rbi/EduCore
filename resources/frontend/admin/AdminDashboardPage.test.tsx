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

function linkWithHref(href: string): HTMLAnchorElement | undefined {
    return screen.getAllByRole('link').find(
        (link) => link.getAttribute('href') === href,
    ) as HTMLAnchorElement | undefined;
}

const teachers = [
    {
        user_id: 'teacher-1',
        name: 'Teacher One',
        email: 'teacher.one@example.test',
        role: 'teacher',
        status: 'active',
        provisioning: {
            state: 'completed',
            provisioned_by_user_id: 'admin-1',
            setup_completed_at: null,
            created_at: null,
        },
        assignment_counts: {
            active: 2,
            inactive: 1,
            total: 3,
        },
        created_at: null,
    },
    {
        user_id: 'teacher-2',
        name: 'Teacher Two',
        email: 'teacher.two@example.test',
        role: 'teacher',
        status: 'active',
        provisioning: {
            state: 'completed',
            provisioned_by_user_id: 'admin-1',
            setup_completed_at: null,
            created_at: null,
        },
        assignment_counts: {
            active: 1,
            inactive: 0,
            total: 1,
        },
        created_at: null,
    },
];

const students = [
    {
        user_id: 'student-1',
        learner_profile_id: 'profile-1',
        name: 'Student One',
        email: 'student.one@example.test',
        role: 'student',
        status: 'active',
        enrollment_counts: {
            pending: 2,
            active: 1,
            inactive: 0,
            total: 3,
        },
        created_at: null,
        learner_profile_created_at: null,
    },
    {
        user_id: 'student-2',
        learner_profile_id: 'profile-2',
        name: 'Student Two',
        email: 'student.two@example.test',
        role: 'student',
        status: 'active',
        enrollment_counts: {
            pending: 1,
            active: 2,
            inactive: 1,
            total: 4,
        },
        created_at: null,
        learner_profile_created_at: null,
    },
];

const summary = {
    counts: {
        subjects: 2,
        curricula: 3,
        teacher_owned_curricula: 2,
        legacy_ownerless_curricula: 1,
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
        published_teacher_owned_curriculum_versions:
            1,
        published_legacy_ownerless_curriculum_versions:
            1,
        published_lessons: 7,
        active_practice_activities: 4,
        active_exam_templates: 3,
    },
};

function installDashboardApi() {
    apiRequestMock.mockImplementation(
        ({
            method,
            url,
        }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/dashboard'
            ) {
                return Promise.resolve(summary);
            }

            if (
                method === 'GET'
                && url === '/api/admin/teachers'
            ) {
                return Promise.resolve(teachers);
            }

            if (
                method === 'GET'
                && url === '/api/admin/students'
            ) {
                return Promise.resolve(students);
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe('AdminDashboardPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('renders live platform counts with western numerals', async () => {
        installDashboardApi();

        renderPage();

        expect(await screen.findByText('المؤشرات الرئيسية'))
            .toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'نظرة عامة' }))
            .toBeInTheDocument();
        expect(screen.queryByText('لوحة الإدارة'))
            .not.toBeInTheDocument();
        expect(
            screen.getByText('حسابات الطلاب')
        ).toBeInTheDocument();

        expect(
            screen.getByText('ملفات المتعلمين')
        ).toBeInTheDocument();
        expect(screen.getAllByText('الدروس').length).toBeGreaterThan(0);
        expect(screen.getAllByText('الوحدات').length).toBeGreaterThan(0);
        expect(screen.getAllByText('الاختبارات').length).toBeGreaterThan(0);
        expect(
            screen.getByText('التشغيل الأكاديمي')
        ).toBeInTheDocument();

        expect(
            screen.getByText('حسابات المعلمين')
        ).toBeInTheDocument();

        expect(
            screen.getByText('إسنادات المواد النشطة')
        ).toBeInTheDocument();

        expect(
            screen.getByText('طلبات التسجيل المعلقة')
        ).toBeInTheDocument();

        expect(
            screen.getByText('التسجيلات النشطة')
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /لا يعني بمفرده أن الطالب يملك وصولًا تعليميًا فعليًا/
            )
        ).toBeInTheDocument();

        expect(
            screen.getByText('جاهزية المحتوى')
        ).toBeInTheDocument();

        expect(
            screen.getByText('مخزون المحتوى')
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'مناهج مملوكة للمعلمين'
            )
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'مناهج تاريخية بلا مالك'
            )
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /نسخ المناهج المنشورة\s*—\s*مملوكة للمعلمين/
            )
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /نسخ المناهج المنشورة\s*—\s*تاريخية بلا مالك/
            )
        ).toBeInTheDocument();

        expect(
            screen.queryByText('المناهج المنشورة')
        ).not.toBeInTheDocument();

        expect(
            screen.queryByText('إصدارات المناهج')
        ).not.toBeInTheDocument();
        expect(screen.getByText('24')).toBeInTheDocument();
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument();

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/admin/dashboard',
        });

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/admin/teachers',
        });

        expect(apiRequestMock).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/admin/students',
        });
    });

    it('routes dashboard cards and quick actions to matching content tabs', async () => {
        installDashboardApi();

        renderPage();
        await screen.findByText('المؤشرات الرئيسية');

        expect(linkWithHref('/admin/teachers'))
            .toBeInTheDocument();
        expect(linkWithHref('/admin/students'))
            .toBeInTheDocument();
        expect(linkWithHref('/admin/content?section=lessons'))
            .toBeInTheDocument();
        expect(linkWithHref('/admin/content?section=topics'))
            .toBeInTheDocument();
        expect(linkWithHref('/admin/content?section=exam-templates'))
            .toBeInTheDocument();
        expect(linkWithHref('/admin/content?section=assessment-items'))
            .toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: /استعراض المناهج/ }).length)
            .toBeGreaterThan(0);
    });
});

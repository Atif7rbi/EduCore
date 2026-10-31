import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
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
    ProductShell,
    adminNavigation,
    learnerNavigation,
    teacherNavigation,
} from './ProductShell';

const logoutMock = vi.fn();
const navigateMock = vi.fn();

vi.mock('../auth/AuthProvider', () => ({
    useAuth: () => ({
        status: 'authenticated',
        user: {
            id: 'user-1',
            name: 'أحمد',
            email: 'ahmad@example.com',
            role: 'admin',
            status: 'active',
            learner_profile_id: null,
        },
        error: null,
        refresh: vi.fn(),
        login: vi.fn(),
        logout: logoutMock,
    }),
}));

vi.mock('react-router-dom', async () => {
    const actual =
        await vi.importActual<
            typeof import('react-router-dom')
        >('react-router-dom');

    return {
        ...actual,
        useNavigate: () => navigateMock,
    };
});

function renderShell(
    navigation = learnerNavigation,
    initialEntry = '/portal/student',
    areaLabel = 'مساحة التعلم',
) {
    render(
        <MemoryRouter
            initialEntries={[
                initialEntry,
            ]}
        >
            <ProductShell
                areaLabel={areaLabel}
                navigation={navigation}
            >
                <div>
                    Page Content
                </div>
            </ProductShell>
        </MemoryRouter>,
    );
}

describe('ProductShell', () => {
    beforeEach(() => {
        logoutMock.mockReset();
        navigateMock.mockReset();
        window.localStorage.clear();
    });

    it('renders authenticated user identity and role', () => {
        renderShell();

        expect(
            screen.getByText('أحمد'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('مدير النظام'),
        ).toBeInTheDocument();
    });

    it('renders learner navigation', () => {
        renderShell();

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'المناهج',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/portal/student/curriculum',
        );

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'النتائج',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/portal/student/results',
        );

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'التقدم',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/portal/student/progress',
        );
    });

    it('marks the current navigation item as active', () => {
        renderShell(
            learnerNavigation,
            '/portal/student/progress',
        );

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'التقدم',
                },
            ),
        ).toHaveClass(
            'authenticated-shell__nav-link--active',
        );
    });

    it('defines administration navigation separately', () => {
        renderShell(
            adminNavigation,
            '/admin',
            'الإدارة',
        );

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'المحتوى',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/admin/content',
        );

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'المعلمون',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/admin/teachers',
        );

        expect(
            screen.getByRole(
                'link',
                {
                    name: 'الطلاب',
                },
            ),
        ).toHaveAttribute(
            'href',
            '/admin/students',
        );

        expect(
            screen.queryByRole(
                'link',
                {
                    name: 'بنك الأسئلة',
                },
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByRole(
                'link',
                {
                    name: 'قوالب الاختبارات',
                },
            ),
        ).not.toBeInTheDocument();
    });

    it('preserves the Admin Teachers navigation icon', () => {
        renderShell(
            adminNavigation,
            '/admin/teachers',
            'الإدارة',
        );

        const teachersLink = screen.getByRole(
            'link',
            {
                name: 'المعلمون',
            },
        );

        expect(teachersLink).toHaveAttribute(
            'href',
            '/admin/teachers',
        );
        expect(
            teachersLink.querySelector('path[d="M4 4h4M16 4h4"]'),
        ).not.toBeNull();
    });

    it('keeps Teacher authoring and enrollment navigation beside monitoring', () => {
        renderShell(
            teacherNavigation,
            '/portal/teacher/monitoring',
            'مساحة المعلم',
        );

        expect(
            screen.getByRole('link', {
                name: 'مساحة التأليف',
            }),
        ).toHaveAttribute('href', '/portal/teacher/workspace');
        expect(
            screen.getByRole('link', {
                name: 'طلبات الالتحاق',
            }),
        ).toHaveAttribute('href', '/portal/teacher/enrollments');
        expect(
            screen.getByRole('link', {
                name: 'طلابي',
            }),
        ).toHaveAttribute('href', '/portal/teacher/students');
        expect(
            screen.getByRole('link', {
                name: 'متابعة الطلاب',
            }),
        ).toHaveAttribute('href', '/portal/teacher/monitoring');
    });

    it('persists the collapsed sidebar preference for the user and area', () => {
        renderShell(
            adminNavigation,
            '/admin',
            'الإدارة',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'طي القائمة الجانبية',
            }),
        );

        expect(
            screen.getByRole('button', {
                name: 'فتح القائمة الجانبية',
            }),
        ).toHaveAttribute('aria-expanded', 'false');

        expect(
            window.localStorage.getItem(
                'educore.sidebar.user-1.الإدارة',
            ),
        ).toBe('collapsed');
    });

    it('restores a previously collapsed sidebar preference', () => {
        window.localStorage.setItem(
            'educore.sidebar.user-1.الإدارة',
            'collapsed',
        );

        renderShell(
            adminNavigation,
            '/admin',
            'الإدارة',
        );

        expect(
            screen.getByRole('button', {
                name: 'فتح القائمة الجانبية',
            }),
        ).toHaveAttribute('aria-expanded', 'false');
    });

    it('logs out and returns to login', async () => {
        logoutMock.mockResolvedValueOnce(
            undefined,
        );

        renderShell();

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name: 'تسجيل الخروج',
                },
            ),
        );

        await waitFor(() => {
            expect(
                logoutMock,
            ).toHaveBeenCalledOnce();
        });

        expect(
            navigateMock,
        ).toHaveBeenCalledWith(
            '/login',
            {
                replace: true,
            },
        );
    });

    it('defines teacher navigation separately', () => {
        renderShell(teacherNavigation, '/portal/teacher/workspace', 'مساحة المعلم');

        expect(screen.getByRole('link', { name: 'مساحة التأليف' })).toHaveAttribute(
            'href',
            '/portal/teacher/workspace',
        );
        expect(screen.getByRole('link', { name: 'طلبات الالتحاق' })).toHaveAttribute(
            'href',
            '/portal/teacher/enrollments',
        );
        expect(screen.getByRole('link', { name: 'طلابي' })).toHaveAttribute(
            'href',
            '/portal/teacher/students',
        );
        expect(screen.queryByRole('link', { name: 'المحتوى' })).not.toBeInTheDocument();
    });

    it('keeps the shell visible when logout fails', async () => {
        logoutMock.mockRejectedValueOnce(
            new Error(
                'Logout failed.',
            ),
        );

        renderShell();

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name: 'تسجيل الخروج',
                },
            ),
        );

        await waitFor(() => {
            expect(
                screen.getByRole('alert'),
            ).toHaveTextContent(
                'تعذر تسجيل الخروج. أعد المحاولة.',
            );
        });

        expect(
            navigateMock,
        ).not.toHaveBeenCalled();
    });
});

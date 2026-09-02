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
    initialEntry = '/app',
) {
    render(
        <MemoryRouter
            initialEntries={[
                initialEntry,
            ]}
        >
            <ProductShell
                areaLabel="مساحة التعلم"
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
            '/app/curriculum',
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
            '/app/results',
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
            '/app/progress',
        );
    });

    it('marks the current navigation item as active', () => {
        renderShell(
            learnerNavigation,
            '/app/progress',
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

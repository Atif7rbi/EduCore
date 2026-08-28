import {
    type PropsWithChildren,
    useState,
} from 'react';
import {
    NavLink,
    useNavigate,
} from 'react-router-dom';

import {
    Button,
    Container,
    Feedback,
} from '../ui';

import {
    useAuth,
} from '../auth/AuthProvider';

interface NavigationItem {
    label: string;
    to: string;
    end?: boolean;
}

interface ProductShellProps
    extends PropsWithChildren {
    areaLabel: string;
    navigation: NavigationItem[];
}

function roleLabel(
    role: string,
): string {
    switch (role) {
        case 'admin':
            return 'مدير النظام';
        case 'teacher':
            return 'معلم';
        default:
            return 'متعلم';
    }
}

export function ProductShell({
    areaLabel,
    children,
    navigation,
}: ProductShellProps) {
    const {
        user,
        logout,
    } = useAuth();

    const navigate = useNavigate();

    const [isLoggingOut, setIsLoggingOut] =
        useState(false);

    const [logoutError, setLogoutError] =
        useState(false);

    async function handleLogout() {
        setLogoutError(false);
        setIsLoggingOut(true);

        try {
            await logout();

            navigate(
                '/login',
                {
                    replace: true,
                },
            );
        } catch {
            setLogoutError(true);
            setIsLoggingOut(false);
        }
    }

    if (!user) {
        return null;
    }

    return (
        <div className="authenticated-shell">
            <header className="authenticated-shell__header">
                <Container className="authenticated-shell__header-inner">
                    <div className="authenticated-shell__identity">
                        <span className="authenticated-shell__brand">
                            EduCore
                        </span>

                        <span className="authenticated-shell__area">
                            {areaLabel}
                        </span>
                    </div>

                    <div className="authenticated-shell__account">
                        <div className="authenticated-shell__user">
                            <strong>
                                {user.name}
                            </strong>

                            <span>
                                {roleLabel(user.role)}
                            </span>
                        </div>

                        <Button
                            variant="secondary"
                            size="sm"
                            isLoading={isLoggingOut}
                            onClick={() => {
                                void handleLogout();
                            }}
                        >
                            تسجيل الخروج
                        </Button>
                    </div>
                </Container>
            </header>

            <Container className="authenticated-shell__layout">
                <nav
                    className="authenticated-shell__navigation"
                    aria-label={`التنقل — ${areaLabel}`}
                >
                    {navigation.map(
                        ({
                            end,
                            label,
                            to,
                        }) => (
                            <NavLink
                                key={to}
                                to={to}
                                end={end}
                                className={({
                                    isActive,
                                }) =>
                                    isActive
                                        ? 'authenticated-shell__nav-link authenticated-shell__nav-link--active'
                                        : 'authenticated-shell__nav-link'
                                }
                            >
                                {label}
                            </NavLink>
                        ),
                    )}
                </nav>

                <main className="authenticated-shell__content">
                    {logoutError ? (
                        <Feedback tone="danger">
                            تعذر تسجيل الخروج. أعد المحاولة.
                        </Feedback>
                    ) : null}

                    {children}
                </main>
            </Container>
        </div>
    );
}

export const learnerNavigation:
    NavigationItem[] = [
        {
            label: 'الرئيسية',
            to: '/app',
            end: true,
        },
        {
            label: 'المناهج',
            to: '/app/curriculum',
        },
        {
            label: 'الممارسة',
            to: '/app/practice',
        },
        {
            label: 'الاختبارات',
            to: '/app/exams',
        },
        {
            label: 'التقدم',
            to: '/app/progress',
        },
    ];

export const adminNavigation:
    NavigationItem[] = [
        {
            label: 'الرئيسية',
            to: '/admin',
            end: true,
        },
        {
            label: 'المناهج',
            to: '/admin/curricula',
        },
        {
            label: 'المحتوى',
            to: '/admin/content',
        },
        {
            label: 'بنك الأسئلة',
            to: '/admin/assessment-items',
        },
        {
            label: 'قوالب الاختبارات',
            to: '/admin/exam-templates',
        },
    ];

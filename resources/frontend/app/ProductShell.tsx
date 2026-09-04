import '../../css/admin-shell-polish.css';
import '../../css/admin-curricula-user.css';

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

function NavigationIcon({
    to,
}: {
    to: string;
}) {
    const common = {
        width: 20,
        height: 20,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
        'aria-hidden': true,
    };

    if (to.endsWith('/content')) {
        return (
            <svg {...common}>
                <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21z" />
                <path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5A2.5 2.5 0 0 1 20 21z" />
            </svg>
        );
    }

    if (to.endsWith('/curricula') || to.endsWith('/curriculum')) {
        return (
            <svg {...common}>
                <path d="M4 7h16M6 4h12v16H6z" />
                <path d="M9 11h6M9 15h6" />
            </svg>
        );
    }

    if (to.endsWith('/exams')) {
        return (
            <svg {...common}>
                <path d="M7 3h8l4 4v14H7z" />
                <path d="M15 3v5h5M10 12h6M10 16h6" />
            </svg>
        );
    }

    if (to.endsWith('/results')) {
        return (
            <svg {...common}>
                <path d="M5 19V9M12 19V5M19 19v-7" />
            </svg>
        );
    }

    if (to.endsWith('/progress')) {
        return (
            <svg {...common}>
                <circle cx="12" cy="12" r="8" />
                <path d="m8.5 12 2.2 2.2 4.8-5" />
            </svg>
        );
    }

    return (
        <svg {...common}>
            <path d="m4 11 8-7 8 7" />
            <path d="M6 10v10h12V10" />
        </svg>
    );
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
            navigate('/login', { replace: true });
        } catch {
            setLogoutError(true);
            setIsLoggingOut(false);
        }
    }

    if (!user) {
        return null;
    }

    const userInitial =
        user.name.trim().charAt(0).toUpperCase() || 'U';

    return (
        <div className="authenticated-shell">
            <header className="authenticated-shell__header">
                <Container className="authenticated-shell__header-inner">
                    <div className="authenticated-shell__identity">
                        <span className="authenticated-shell__brand-mark" aria-hidden="true">
                            <span />
                        </span>
                        <span className="authenticated-shell__brand">
                            EduCore
                        </span>
                        <span className="authenticated-shell__area">
                            {areaLabel}
                        </span>
                    </div>

                    <div className="authenticated-shell__account">
                        <span className="authenticated-shell__avatar" aria-hidden="true">
                            {userInitial}
                        </span>
                        <div className="authenticated-shell__user">
                            <strong>{user.name}</strong>
                            <span>{roleLabel(user.role)}</span>
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
                <aside className="authenticated-shell__sidebar">
                    <div className="authenticated-shell__nav-heading">
                        <span>{areaLabel}</span>
                        <small>مساحة العمل</small>
                    </div>

                    <nav
                        className="authenticated-shell__navigation"
                        aria-label={`التنقل — ${areaLabel}`}
                    >
                        {navigation.map(({ end, label, to }) => (
                            <NavLink
                                key={to}
                                to={to}
                                end={end}
                                className={({ isActive }) =>
                                    isActive
                                        ? 'authenticated-shell__nav-link authenticated-shell__nav-link--active'
                                        : 'authenticated-shell__nav-link'
                                }
                            >
                                <NavigationIcon to={to} />
                                <span>{label}</span>
                            </NavLink>
                        ))}
                    </nav>

                    <div className="authenticated-shell__sidebar-footer">
                        <strong>EduCore</strong>
                        <span>منصة تعليمية متكاملة</span>
                    </div>
                </aside>

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

export const learnerNavigation: NavigationItem[] = [
    { label: 'الرئيسية', to: '/app', end: true },
    { label: 'المناهج', to: '/app/curriculum' },
    { label: 'الاختبارات', to: '/app/exams' },
    { label: 'النتائج', to: '/app/results' },
    { label: 'التقدم', to: '/app/progress' },
];

export const adminNavigation: NavigationItem[] = [
    { label: 'الرئيسية', to: '/admin', end: true },
    { label: 'المناهج', to: '/admin/curricula' },
    { label: 'المحتوى', to: '/admin/content' },
];

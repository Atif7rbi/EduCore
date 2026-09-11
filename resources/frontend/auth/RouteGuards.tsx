import {
    type PropsWithChildren,
} from 'react';
import {
    Navigate,
    Outlet,
    useLocation,
} from 'react-router-dom';

import {
    Button,
    Feedback,
} from '../ui';

import {
    useAuth,
} from './AuthProvider';
import type {
    UserRole,
} from './types';

interface RequireRoleProps
    extends PropsWithChildren {
    allowedRoles: UserRole[];
}

function requestedLocation(
    pathname: string,
    search: string,
    hash: string,
): string {
    return pathname + search + hash;
}

function AuthGateLoading() {
    return (
        <div className="route-gate">
            <Feedback>
                جارٍ التحقق من صلاحية الوصول...
            </Feedback>
        </div>
    );
}

function AuthGateError() {
    const {
        error,
        refresh,
    } = useAuth();

    return (
        <div className="route-gate auth-form__stack">
            <Feedback tone="danger">
                تعذر التحقق من صلاحية الوصول.

                {error &&
                'requestId' in error &&
                typeof error.requestId ===
                    'string' ? (
                    <span className="auth-error-reference">
                        {' '}
                        رقم المرجع:
                        {' '}
                        {error.requestId}
                    </span>
                ) : null}
            </Feedback>

            <Button
                variant="secondary"
                onClick={() => {
                    void refresh().catch(
                        () => undefined,
                    );
                }}
            >
                إعادة المحاولة
            </Button>
        </div>
    );
}

export function RequireAuth({
    children,
}: PropsWithChildren) {
    const {
        status,
        user,
    } = useAuth();

    const location =
        useLocation();

    if (status === 'loading') {
        return <AuthGateLoading />;
    }

    if (status === 'error') {
        return <AuthGateError />;
    }

    if (
        status !== 'authenticated' ||
        !user
    ) {
        return (
            <Navigate
                to="/login"
                replace
                state={{
                    from:
                        requestedLocation(
                            location.pathname,
                            location.search,
                            location.hash,
                        ),
                }}
            />
        );
    }

    return children ?? <Outlet />;
}

export function RequireRole({
    allowedRoles,
    children,
}: RequireRoleProps) {
    const {
        status,
        user,
    } = useAuth();

    const location =
        useLocation();

    if (status === 'loading') {
        return <AuthGateLoading />;
    }

    if (status === 'error') {
        return <AuthGateError />;
    }

    if (
        status !== 'authenticated' ||
        !user
    ) {
        return (
            <Navigate
                to="/login"
                replace
                state={{
                    from:
                        requestedLocation(
                            location.pathname,
                            location.search,
                            location.hash,
                        ),
                }}
            />
        );
    }

    if (
        !allowedRoles.includes(
            user.role,
        )
    ) {
        return (
            <Navigate
                to="/forbidden"
                replace
            />
        );
    }

    return children ?? <Outlet />;
}

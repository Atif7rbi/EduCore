import {
    createContext,
    type PropsWithChildren,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
} from 'react';

import {
    apiRequest,
} from '../api/client';
import {
    EduCoreApiError,
} from '../api/errors';
import {
    type SessionFailure,
    subscribeToSessionFailures,
} from '../api/sessionEvents';

import type {
    AuthUser,
    AuthUserPayload,
    LoginCredentials,
    LogoutPayload,
} from './types';

export type AuthStatus =
    | 'loading'
    | 'authenticated'
    | 'unauthenticated'
    | 'error';

interface AuthContextValue {
    status: AuthStatus;
    user: AuthUser | null;
    error: Error | null;
    sessionIssue: SessionFailure | null;
    refresh: () => Promise<void>;
    login: (
        credentials: LoginCredentials,
    ) => Promise<AuthUser>;
    logout: () => Promise<void>;
}

const AuthContext =
    createContext<AuthContextValue | null>(
        null,
    );

function asError(
    error: unknown,
): Error {
    return error instanceof Error
        ? error
        : new Error(
            'Unexpected authentication error.',
        );
}

export function AuthProvider({
    children,
}: PropsWithChildren) {
    const [status, setStatus] =
        useState<AuthStatus>('loading');

    const [user, setUser] =
        useState<AuthUser | null>(null);

    const [error, setError] =
        useState<Error | null>(null);

    const [
        sessionIssue,
        setSessionIssue,
    ] = useState<SessionFailure | null>(
        null,
    );

    const refresh = useCallback(
        async () => {
            setError(null);

            try {
                const payload =
                    await apiRequest<
                        AuthUserPayload
                    >({
                        method: 'GET',
                        url: '/auth/me',
                    });

                setUser(payload.user);
                setSessionIssue(null);
                setStatus(
                    'authenticated',
                );
            } catch (caughtError) {
                if (
                    caughtError instanceof
                        EduCoreApiError &&
                    caughtError.status === 401
                ) {
                    setUser(null);
                    setSessionIssue(null);
                    setStatus(
                        'unauthenticated',
                    );

                    return;
                }

                setUser(null);
                setError(
                    asError(caughtError),
                );
                setStatus('error');

                throw caughtError;
            }
        },
        [],
    );

    const login = useCallback(
        async (
            credentials:
                LoginCredentials,
        ): Promise<AuthUser> => {
            setError(null);

            const payload =
                await apiRequest<
                    AuthUserPayload
                >({
                    method: 'POST',
                    url: '/auth/login',
                    data: credentials,
                });

            setUser(payload.user);
            setSessionIssue(null);
            setStatus('authenticated');

            return payload.user;
        },
        [],
    );

    const logout = useCallback(
        async () => {
            setError(null);

            try {
                await apiRequest<
                    LogoutPayload
                >({
                    method: 'POST',
                    url: '/auth/logout',
                });
            } catch (caughtError) {
                if (
                    caughtError instanceof
                        EduCoreApiError &&
                    (
                        caughtError.status ===
                            401 ||
                        caughtError.status ===
                            419
                    )
                ) {
                    setUser(null);
                    setSessionIssue({
                        kind:
                            caughtError.status ===
                            419
                                ? 'csrf'
                                : 'expired',
                        requestId:
                            caughtError
                                .requestId,
                    });
                    setStatus(
                        'unauthenticated',
                    );

                    return;
                }

                throw caughtError;
            }

            setUser(null);
            setSessionIssue(null);
            setStatus(
                'unauthenticated',
            );
        },
        [],
    );

    useEffect(() => {
        return subscribeToSessionFailures(
            (failure) => {
                setUser(null);
                setError(null);
                setSessionIssue(
                    failure,
                );
                setStatus(
                    'unauthenticated',
                );
            },
        );
    }, []);

    useEffect(() => {
        void refresh().catch(
            () => undefined,
        );
    }, [refresh]);

    const value =
        useMemo<AuthContextValue>(
            () => ({
                status,
                user,
                error,
                sessionIssue,
                refresh,
                login,
                logout,
            }),
            [
                status,
                user,
                error,
                sessionIssue,
                refresh,
                login,
                logout,
            ],
        );

    return (
        <AuthContext.Provider
            value={value}
        >
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth():
    AuthContextValue {
    const context =
        useContext(AuthContext);

    if (!context) {
        throw new Error(
            'useAuth must be used within AuthProvider.',
        );
    }

    return context;
}

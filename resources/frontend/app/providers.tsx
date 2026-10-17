import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    type PropsWithChildren,
    useMemo,
} from 'react';

import {
    AuthProvider,
    useAuth,
} from '../auth/AuthProvider';

function createQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
                refetchOnWindowFocus: false,
            },
            mutations: {
                retry: false,
            },
        },
    });
}

function PrincipalScopedQueryClient({
    children,
}: PropsWithChildren) {
    const {
        status,
        user,
    } = useAuth();
    const principalId =
        status === 'authenticated'
            ? user?.id ?? null
            : null;
    const queryClient = useMemo(
        () => createQueryClient(),
        [principalId],
    );

    return (
        <QueryClientProvider
            client={queryClient}
            key={principalId ?? 'unauthenticated'}
        >
            {children}
        </QueryClientProvider>
    );
}

export function AppProviders({
    children,
}: PropsWithChildren) {
    return (
        <AuthProvider>
            <PrincipalScopedQueryClient>
                {children}
            </PrincipalScopedQueryClient>
        </AuthProvider>
    );
}

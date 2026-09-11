import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    type PropsWithChildren,
    useState,
} from 'react';

import { AuthProvider } from '../auth/AuthProvider';

export function AppProviders({
    children,
}: PropsWithChildren) {
    const [queryClient] = useState(
        () =>
            new QueryClient({
                defaultOptions: {
                    queries: {
                        retry: false,
                        refetchOnWindowFocus: false,
                    },
                    mutations: {
                        retry: false,
                    },
                },
            }),
    );

    return (
        <QueryClientProvider client={queryClient}>
            <AuthProvider>
                {children}
            </AuthProvider>
        </QueryClientProvider>
    );
}

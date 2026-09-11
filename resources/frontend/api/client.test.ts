import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    apiClient,
} from './client';
import {
    subscribeToSessionFailures,
} from './sessionEvents';

function rejectingAdapter(
    status: number,
    requestId: string,
) {
    return async (
        config: unknown,
    ): Promise<never> => {
        return Promise.reject({
            isAxiosError: true,
            config,
            response: {
                status,
                statusText: 'Request failed.',
                data: {
                    error: {
                        code:
                            status === 419
                                ? 'csrf_token_mismatch'
                                : 'unauthenticated',
                        message: 'Request failed.',
                    },
                },
                headers: {
                    'x-request-id':
                        requestId,
                },
                config,
            },
        });
    };
}

describe('api client session handling', () => {
    it('uses the rotating XSRF cookie instead of the static csrf meta token', async () => {
        const meta =
            document.createElement('meta');

        meta.name = 'csrf-token';
        meta.content = 'stale-meta-token';

        document.head.append(meta);

        try {
            expect(
                apiClient.defaults.xsrfCookieName,
            ).toBe('XSRF-TOKEN');

            expect(
                apiClient.defaults.xsrfHeaderName,
            ).toBe('X-XSRF-TOKEN');

            expect(
                apiClient.defaults.withXSRFToken,
            ).toBe(true);

            await apiClient.request({
                method: 'POST',
                url: '/auth/login',
                adapter: async (config) => {
                    expect(
                        config.headers.has(
                            'X-CSRF-TOKEN',
                        ),
                    ).toBe(false);

                    return {
                        data: {
                            data: {},
                        },
                        status: 200,
                        statusText: 'OK',
                        headers: {},
                        config,
                    };
                },
            });
        } finally {
            meta.remove();
        }
    });

    it('emits session expiry for a runtime 401 response', async () => {
        const failures: string[] = [];

        const unsubscribe =
            subscribeToSessionFailures(
                (failure) => {
                    failures.push(
                        `${failure.kind}:${failure.requestId}`,
                    );
                },
            );

        try {
            await expect(
                apiClient.request({
                    method: 'GET',
                    url: '/api/lessons',
                    adapter:
                        rejectingAdapter(
                            401,
                            'client-401',
                        ),
                }),
            ).rejects.toMatchObject({
                status: 401,
                requestId:
                    'client-401',
            });

            expect(failures).toEqual([
                'expired:client-401',
            ]);
        } finally {
            unsubscribe();
        }
    });

    it('emits csrf failure for a runtime 419 response', async () => {
        const failures: string[] = [];

        const unsubscribe =
            subscribeToSessionFailures(
                (failure) => {
                    failures.push(
                        `${failure.kind}:${failure.requestId}`,
                    );
                },
            );

        try {
            await expect(
                apiClient.request({
                    method: 'POST',
                    url: '/api/attempts',
                    adapter:
                        rejectingAdapter(
                            419,
                            'client-419',
                        ),
                }),
            ).rejects.toMatchObject({
                status: 419,
                requestId:
                    'client-419',
            });

            expect(failures).toEqual([
                'csrf:client-419',
            ]);
        } finally {
            unsubscribe();
        }
    });

    it('does not emit session failure for login 401', async () => {
        const failures: string[] = [];

        const unsubscribe =
            subscribeToSessionFailures(
                (failure) => {
                    failures.push(
                        failure.kind,
                    );
                },
            );

        try {
            await expect(
                apiClient.request({
                    method: 'POST',
                    url: '/auth/login',
                    adapter:
                        rejectingAdapter(
                            401,
                            'login-401',
                        ),
                }),
            ).rejects.toMatchObject({
                status: 401,
                requestId:
                    'login-401',
            });

            expect(failures).toEqual([]);
        } finally {
            unsubscribe();
        }
    });
});

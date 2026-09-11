import axios, {
    type AxiosRequestConfig,
} from 'axios';

import {
    normalizeApiError,
} from './errors';
import {
    classifyRuntimeSessionFailure,
    emitSessionFailure,
} from './sessionEvents';

export interface ApiSuccessEnvelope<T> {
    data: T;
}

export const apiClient = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

apiClient.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        const normalized =
            normalizeApiError(error);

        if (axios.isAxiosError(error)) {
            const sessionFailure =
                classifyRuntimeSessionFailure(
                    normalized,
                    error.config?.url,
                );

            if (sessionFailure) {
                emitSessionFailure(
                    sessionFailure,
                );
            }
        }

        return Promise.reject(normalized);
    },
);

export async function apiRequest<T>(
    config: AxiosRequestConfig,
): Promise<T> {
    const response =
        await apiClient.request<
            ApiSuccessEnvelope<T>
        >(config);

    return response.data.data;
}

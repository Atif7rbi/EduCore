import axios from 'axios';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    EduCoreApiError,
    normalizeApiError,
} from './errors';

describe('normalizeApiError', () => {
    it('normalizes the EduCore API error envelope and request id', () => {
        const axiosError = new axios.AxiosError(
            'Request failed',
            'ERR_BAD_RESPONSE',
            undefined,
            undefined,
            {
                data: {
                    error: {
                        code: 'concurrency_conflict',
                        message: 'The resource changed.',
                    },
                },
                status: 409,
                statusText: 'Conflict',
                headers: {
                    'x-request-id': 'request-123',
                },
                config: {
                    headers: new axios.AxiosHeaders(),
                },
            },
        );

        const result = normalizeApiError(axiosError);

        expect(result).toBeInstanceOf(EduCoreApiError);
        expect(result.code).toBe('concurrency_conflict');
        expect(result.status).toBe(409);
        expect(result.requestId).toBe('request-123');
    });

    it('normalizes validation details', () => {
        const axiosError = new axios.AxiosError(
            'Validation failed',
            'ERR_BAD_REQUEST',
            undefined,
            undefined,
            {
                data: {
                    error: {
                        code: 'validation_failed',
                        message: 'Validation failed.',
                        details: {
                            email: [
                                'The email field is required.',
                            ],
                        },
                    },
                },
                status: 422,
                statusText: 'Unprocessable Entity',
                headers: {},
                config: {
                    headers: new axios.AxiosHeaders(),
                },
            },
        );

        const result = normalizeApiError(axiosError);

        expect(result.status).toBe(422);

        expect(result.details).toEqual({
            email: [
                'The email field is required.',
            ],
        });
    });

    it('normalizes unexpected client errors safely', () => {
        const result = normalizeApiError(
            new Error('Unexpected'),
        );

        expect(result.code).toBe(
            'unexpected_client_error',
        );

        expect(result.status).toBeNull();
        expect(result.requestId).toBeNull();
    });
});

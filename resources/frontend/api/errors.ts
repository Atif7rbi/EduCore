import axios from 'axios';

export type ApiErrorDetails = Record<string, string[]>;

export class EduCoreApiError extends Error {
    readonly code: string;
    readonly status: number | null;
    readonly details?: ApiErrorDetails;
    readonly requestId: string | null;

    constructor({
        code,
        message,
        status,
        details,
        requestId,
    }: {
        code: string;
        message: string;
        status: number | null;
        details?: ApiErrorDetails;
        requestId: string | null;
    }) {
        super(message);

        this.name = 'EduCoreApiError';
        this.code = code;
        this.status = status;
        this.details = details;
        this.requestId = requestId;
    }
}

interface ErrorEnvelope {
    error?: {
        code?: unknown;
        message?: unknown;
        details?: unknown;
    };
}

function normalizeDetails(
    details: unknown,
): ApiErrorDetails | undefined {
    if (
        details === null ||
        typeof details !== 'object' ||
        Array.isArray(details)
    ) {
        return undefined;
    }

    const normalized: ApiErrorDetails = {};

    for (const [field, messages] of Object.entries(details)) {
        if (
            Array.isArray(messages) &&
            messages.every((message) => typeof message === 'string')
        ) {
            normalized[field] = messages;
        }
    }

    return Object.keys(normalized).length > 0
        ? normalized
        : undefined;
}

export function normalizeApiError(error: unknown): EduCoreApiError {
    if (!axios.isAxiosError(error)) {
        return new EduCoreApiError({
            code: 'unexpected_client_error',
            message: 'حدث خطأ غير متوقع.',
            status: null,
            requestId: null,
        });
    }

    const status = error.response?.status ?? null;
    const envelope = error.response?.data as ErrorEnvelope | undefined;

    const code =
        typeof envelope?.error?.code === 'string'
            ? envelope.error.code
            : 'unexpected_http_error';

    const message =
        typeof envelope?.error?.message === 'string'
            ? envelope.error.message
            : 'تعذر إكمال الطلب.';

    const requestIdHeader =
        error.response?.headers?.['x-request-id'];

    const requestId =
        typeof requestIdHeader === 'string'
            ? requestIdHeader
            : null;

    return new EduCoreApiError({
        code,
        message,
        status,
        details: normalizeDetails(envelope?.error?.details),
        requestId,
    });
}

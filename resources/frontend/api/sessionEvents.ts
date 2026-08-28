import {
    EduCoreApiError,
} from './errors';

export type SessionFailureKind =
    | 'expired'
    | 'csrf';

export interface SessionFailure {
    kind: SessionFailureKind;
    requestId: string | null;
}

type SessionFailureListener = (
    failure: SessionFailure,
) => void;

const listeners =
    new Set<SessionFailureListener>();

function requestPath(
    url: string,
): string {
    return new URL(
        url,
        window.location.origin,
    ).pathname;
}

const authLifecyclePaths =
    new Set([
        '/auth/login',
        '/auth/me',
        '/auth/logout',
    ]);

function isAuthLifecycleRequest(
    url: string,
): boolean {
    return authLifecyclePaths.has(
        requestPath(url),
    );
}

export function classifyRuntimeSessionFailure(
    error: EduCoreApiError,
    url: string | undefined,
): SessionFailure | null {
    if (
        !url ||
        isAuthLifecycleRequest(url)
    ) {
        return null;
    }

    if (error.status === 401) {
        return {
            kind: 'expired',
            requestId: error.requestId,
        };
    }

    if (error.status === 419) {
        return {
            kind: 'csrf',
            requestId: error.requestId,
        };
    }

    return null;
}

export function emitSessionFailure(
    failure: SessionFailure,
) {
    for (const listener of listeners) {
        listener(failure);
    }
}

export function subscribeToSessionFailures(
    listener: SessionFailureListener,
): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

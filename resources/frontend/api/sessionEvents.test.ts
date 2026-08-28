import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    EduCoreApiError,
} from './errors';
import {
    classifyRuntimeSessionFailure,
    emitSessionFailure,
    subscribeToSessionFailures,
} from './sessionEvents';

function apiError(
    status: number,
    requestId = 'request-id',
) {
    return new EduCoreApiError({
        code: 'test_error',
        message: 'Test error.',
        status,
        requestId,
    });
}

describe('runtime session failure classification', () => {
    it('classifies runtime 401 as session expiry', () => {
        expect(
            classifyRuntimeSessionFailure(
                apiError(401),
                '/api/lessons',
            ),
        ).toEqual({
            kind: 'expired',
            requestId: 'request-id',
        });
    });

    it('classifies runtime 419 as csrf session failure', () => {
        expect(
            classifyRuntimeSessionFailure(
                apiError(419),
                '/api/attempts',
            ),
        ).toEqual({
            kind: 'csrf',
            requestId: 'request-id',
        });
    });

    it('ignores authentication lifecycle requests', () => {
        expect(
            classifyRuntimeSessionFailure(
                apiError(401),
                '/auth/login',
            ),
        ).toBeNull();

        expect(
            classifyRuntimeSessionFailure(
                apiError(401),
                '/auth/me',
            ),
        ).toBeNull();

        expect(
            classifyRuntimeSessionFailure(
                apiError(419),
                '/auth/logout',
            ),
        ).toBeNull();
    });

    it('ignores unrelated runtime errors', () => {
        expect(
            classifyRuntimeSessionFailure(
                apiError(500),
                '/api/lessons',
            ),
        ).toBeNull();
    });

    it('delivers emitted failures to active subscribers only', () => {
        const received: string[] = [];

        const unsubscribe =
            subscribeToSessionFailures(
                (failure) => {
                    received.push(
                        failure.kind,
                    );
                },
            );

        emitSessionFailure({
            kind: 'expired',
            requestId: null,
        });

        unsubscribe();

        emitSessionFailure({
            kind: 'csrf',
            requestId: null,
        });

        expect(received).toEqual([
            'expired',
        ]);
    });


    it('does not exempt arbitrary future auth paths', () => {
        expect(
            classifyRuntimeSessionFailure(
                apiError(401),
                '/auth/future-protected-action',
            ),
        ).toEqual({
            kind: 'expired',
            requestId: 'request-id',
        });
    });

});

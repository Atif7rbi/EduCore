import {
    act,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    EduCoreApiError,
} from '../api/errors';
import {
    emitSessionFailure,
} from '../api/sessionEvents';

import {
    AuthProvider,
    useAuth,
} from './AuthProvider';

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (...args: unknown[]) =>
        apiRequestMock(...args),
}));

function AuthProbe() {
    const {
        status,
        user,
        error,
        sessionIssue,
    } = useAuth();

    return (
        <>
            <div data-testid="status">
                {status}
            </div>

            <div data-testid="user">
                {user?.email ?? 'none'}
            </div>

            <div data-testid="error">
                {error?.message ?? 'none'}
            </div>

            <div data-testid="session-kind">
                {sessionIssue?.kind ?? 'none'}
            </div>

            <div data-testid="session-request-id">
                {sessionIssue?.requestId ?? 'none'}
            </div>
        </>
    );
}

function activeUserPayload() {
    return {
        user: {
            id: 'user-1',
            name: 'Learner',
            email: 'learner@example.com',
            role: 'student',
            status: 'active',
            learner_profile_id: 'learner-1',
        },
    };
}

describe('AuthProvider', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('treats 401 from auth me as unauthenticated', async () => {
        apiRequestMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'unauthenticated',
                message: 'Unauthenticated.',
                status: 401,
                requestId: 'request-401',
            }),
        );

        render(
            <AuthProvider>
                <AuthProbe />
            </AuthProvider>,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('status'),
            ).toHaveTextContent(
                'unauthenticated',
            );
        });

        expect(
            screen.getByTestId('user'),
        ).toHaveTextContent('none');

        expect(
            screen.getByTestId('error'),
        ).toHaveTextContent('none');

        expect(
            screen.getByTestId(
                'session-kind',
            ),
        ).toHaveTextContent('none');
    });

    it('preserves non-auth failures as an error state', async () => {
        apiRequestMock.mockRejectedValueOnce(
            new EduCoreApiError({
                code: 'internal_error',
                message: 'Service unavailable.',
                status: 500,
                requestId: 'request-500',
            }),
        );

        render(
            <AuthProvider>
                <AuthProbe />
            </AuthProvider>,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('status'),
            ).toHaveTextContent('error');
        });

        expect(
            screen.getByTestId('error'),
        ).toHaveTextContent(
            'Service unavailable.',
        );
    });

    it('clears an authenticated session after runtime 401', async () => {
        apiRequestMock.mockResolvedValueOnce(
            activeUserPayload(),
        );

        render(
            <AuthProvider>
                <AuthProbe />
            </AuthProvider>,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('status'),
            ).toHaveTextContent(
                'authenticated',
            );
        });

        act(() => {
            emitSessionFailure({
                kind: 'expired',
                requestId: 'runtime-401',
            });
        });

        expect(
            screen.getByTestId('status'),
        ).toHaveTextContent(
            'unauthenticated',
        );

        expect(
            screen.getByTestId('user'),
        ).toHaveTextContent('none');

        expect(
            screen.getByTestId(
                'session-kind',
            ),
        ).toHaveTextContent('expired');

        expect(
            screen.getByTestId(
                'session-request-id',
            ),
        ).toHaveTextContent(
            'runtime-401',
        );
    });

    it('clears an authenticated session after runtime 419', async () => {
        apiRequestMock.mockResolvedValueOnce(
            activeUserPayload(),
        );

        render(
            <AuthProvider>
                <AuthProbe />
            </AuthProvider>,
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('status'),
            ).toHaveTextContent(
                'authenticated',
            );
        });

        act(() => {
            emitSessionFailure({
                kind: 'csrf',
                requestId: 'runtime-419',
            });
        });

        expect(
            screen.getByTestId('status'),
        ).toHaveTextContent(
            'unauthenticated',
        );

        expect(
            screen.getByTestId(
                'session-kind',
            ),
        ).toHaveTextContent('csrf');

        expect(
            screen.getByTestId(
                'session-request-id',
            ),
        ).toHaveTextContent(
            'runtime-419',
        );
    });
});

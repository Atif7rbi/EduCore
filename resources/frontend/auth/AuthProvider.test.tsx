import {
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
        </>
    );
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
            ).toHaveTextContent('unauthenticated');
        });

        expect(
            screen.getByTestId('user'),
        ).toHaveTextContent('none');

        expect(
            screen.getByTestId('error'),
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
});

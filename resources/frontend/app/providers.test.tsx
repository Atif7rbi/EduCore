import {
    useQuery,
} from '@tanstack/react-query';
import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    act,
    useState,
} from 'react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    apiRequest,
} from '../api/client';
import {
    emitSessionFailure,
} from '../api/sessionEvents';
import {
    useAuth,
} from '../auth/AuthProvider';

import {
    AppProviders,
} from './providers';

interface TeacherUser {
    id: string;
    name: string;
    email: string;
    role: 'teacher';
    status: 'active';
    learner_profile_id: null;
}

interface DeferredValue {
    promise: Promise<string>;
    resolve: (value: string) => void;
}

const apiRequestMock =
    vi.mocked(apiRequest);

let loadPrivateValue:
    (principalId: string) => Promise<string>;

vi.mock('../api/client', () => ({
    apiRequest: vi.fn(),
}));

function teacherUser(
    id: string,
): TeacherUser {
    return {
        id,
        name: id,
        email: id + '@example.com',
        role: 'teacher',
        status: 'active',
        learner_profile_id: null,
    };
}

function deferredValue(): DeferredValue {
    let resolve:
        (value: string) => void = () => undefined;
    const promise =
        new Promise<string>((fulfill) => {
            resolve = fulfill;
        });

    return {
        promise,
        resolve,
    };
}

function QueryConsumer() {
    const {
        login,
        logout,
        status,
        user,
    } = useAuth();
    const [rerenderCount, setRerenderCount] =
        useState(0);
    const principalId =
        status === 'authenticated'
            ? user?.id ?? null
            : null;
    const query = useQuery({
        queryKey: [
            'principal-cache-probe',
        ],
        enabled: principalId !== null,
        queryFn: () =>
            loadPrivateValue(
                principalId ?? '',
            ),
    });

    return (
        <section>
            <output data-testid="principal">
                {principalId ?? 'unauthenticated'}
            </output>

            <output data-testid="private-value">
                {principalId === null
                    ? 'hidden'
                    : query.data ?? 'loading'}
            </output>

            <output data-testid="rerender-count">
                {rerenderCount}
            </output>

            <button
                type="button"
                onClick={() => {
                    void login({
                        email:
                            'teacher-b@example.com',
                        password: 'secret',
                    });
                }}
            >
                Switch to teacher B
            </button>

            <button
                type="button"
                onClick={() => {
                    void login({
                        email:
                            'teacher-a@example.com',
                        password: 'secret',
                    });
                }}
            >
                Switch to teacher A
            </button>

            <button
                type="button"
                onClick={() => {
                    void logout();
                }}
            >
                Logout
            </button>

            <button
                type="button"
                onClick={() => {
                    setRerenderCount(
                        (count) => count + 1,
                    );
                }}
            >
                Rerender
            </button>
        </section>
    );
}

function renderProbe() {
    return render(
        <AppProviders>
            <QueryConsumer />
        </AppProviders>,
    );
}

function configureAuthentication(
    initialTeacherId = 'teacher-a',
) {
    apiRequestMock.mockImplementation(
        (config) => {
            if (
                config.method === 'GET'
                && config.url === '/auth/me'
            ) {
                return Promise.resolve({
                    user: teacherUser(
                        initialTeacherId,
                    ),
                });
            }

            if (
                config.method === 'POST'
                && config.url === '/auth/login'
            ) {
                const teacherId =
                    config.data?.email ===
                    'teacher-b@example.com'
                        ? 'teacher-b'
                        : 'teacher-a';

                return Promise.resolve({
                    user: teacherUser(teacherId),
                });
            }

            if (
                config.method === 'POST'
                && config.url === '/auth/logout'
            ) {
                return Promise.resolve({});
            }

            return Promise.reject(
                new Error(
                    'Unexpected request.',
                ),
            );
        },
    );
}

describe('AppProviders principal cache isolation', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
        loadPrivateValue = async (
            principalId,
        ) => 'private:' + principalId;
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('never renders a previous teacher cache entry during account and session transitions', async () => {
        const teacherB =
            deferredValue();
        const teacherAAfterLogout =
            deferredValue();
        let teacherAQueries = 0;

        configureAuthentication();

        loadPrivateValue = (
            principalId,
        ) => {
            if (principalId === 'teacher-b') {
                return teacherB.promise;
            }

            teacherAQueries += 1;

            return teacherAQueries === 1
                ? Promise.resolve(
                    'private:teacher-a:initial',
                )
                : teacherAAfterLogout.promise;
        };

        renderProbe();

        await waitFor(() => {
            expect(
                screen.getByTestId(
                    'private-value',
                ),
            ).toHaveTextContent(
                'private:teacher-a:initial',
            );
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Switch to teacher B',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('principal'),
            ).toHaveTextContent('teacher-b');
        });

        expect(
            screen.getByTestId('private-value'),
        ).toHaveTextContent('loading');
        expect(
            screen.getByTestId('private-value'),
        ).not.toHaveTextContent(
            'private:teacher-a:initial',
        );

        await act(async () => {
            teacherB.resolve(
                'private:teacher-b',
            );
        });

        await waitFor(() => {
            expect(
                screen.getByTestId(
                    'private-value',
                ),
            ).toHaveTextContent(
                'private:teacher-b',
            );
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Logout',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('principal'),
            ).toHaveTextContent(
                'unauthenticated',
            );
        });
        expect(
            screen.getByTestId('private-value'),
        ).toHaveTextContent('hidden');

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Switch to teacher A',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('principal'),
            ).toHaveTextContent('teacher-a');
        });

        expect(
            screen.getByTestId('private-value'),
        ).toHaveTextContent('loading');
        expect(
            screen.getByTestId('private-value'),
        ).not.toHaveTextContent(
            'private:teacher-b',
        );

        await act(async () => {
            teacherAAfterLogout.resolve(
                'private:teacher-a:after-logout',
            );
        });

        await waitFor(() => {
            expect(
                screen.getByTestId(
                    'private-value',
                ),
            ).toHaveTextContent(
                'private:teacher-a:after-logout',
            );
        });
    });

    it('retains cached data across same-principal rerenders', async () => {
        configureAuthentication();
        const loadSpy = vi.fn(
            async () => 'private:teacher-a',
        );
        loadPrivateValue = loadSpy;

        renderProbe();

        await waitFor(() => {
            expect(
                screen.getByTestId(
                    'private-value',
                ),
            ).toHaveTextContent(
                'private:teacher-a',
            );
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Rerender',
            }),
        );

        expect(
            screen.getByTestId('private-value'),
        ).toHaveTextContent(
            'private:teacher-a',
        );
        expect(loadSpy).toHaveBeenCalledOnce();
    });

    it.each([
        {
            kind: 'expired' as const,
            requestId: 'runtime-401',
        },
        {
            kind: 'csrf' as const,
            requestId: 'runtime-419',
        },
    ])(
        'hides private cache entries after runtime $kind session invalidation',
        async (failure) => {
            configureAuthentication();

            renderProbe();

            await waitFor(() => {
                expect(
                    screen.getByTestId(
                        'private-value',
                    ),
                ).toHaveTextContent(
                    'private:teacher-a',
                );
            });

            act(() => {
                emitSessionFailure(failure);
            });

            await waitFor(() => {
                expect(
                    screen.getByTestId('principal'),
                ).toHaveTextContent(
                    'unauthenticated',
                );
            });
            expect(
                screen.getByTestId('private-value'),
            ).toHaveTextContent('hidden');
        },
    );
});

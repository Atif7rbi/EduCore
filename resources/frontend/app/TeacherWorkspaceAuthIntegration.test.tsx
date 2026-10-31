import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    AxiosHeaders,
    type AxiosAdapter,
    type AxiosResponse,
    type InternalAxiosRequestConfig,
} from 'axios';
import {
    RouterProvider,
} from 'react-router-dom';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    apiClient,
} from '../api/client';
import type {
    AuthUser,
    UserRole,
} from '../auth/types';

import {
    AppProviders,
} from './providers';
import {
    router,
} from './router';

interface Deferred<T> {
    promise: Promise<T>;
    reject: (reason: unknown) => void;
    resolve: (value: T) => void;
}

interface PendingAdapterRequest {
    config: InternalAxiosRequestConfig;
    deferred: Deferred<AxiosResponse>;
}

const scopedPath =
    '/portal/teacher/workspace/assignment-1'
    + '/curricula/curriculum-1'
    + '/versions/version-1?section=topics';

const users: Record<UserRole, AuthUser> = {
    teacher: {
        id: 'teacher-a',
        name: 'المعلم أ',
        email: 'teacher-a@example.com',
        role: 'teacher',
        status: 'active',
        learner_profile_id: null,
    },
    student: {
        id: 'student-1',
        name: 'الطالب',
        email: 'student@example.com',
        role: 'student',
        status: 'active',
        learner_profile_id: 'learner-1',
    },
    admin: {
        id: 'admin-1',
        name: 'المدير',
        email: 'admin@example.com',
        role: 'admin',
        status: 'active',
        learner_profile_id: null,
    },
};

const teacherB: AuthUser = {
    id: 'teacher-b',
    name: 'المعلم ب',
    email: 'teacher-b@example.com',
    role: 'teacher',
    status: 'active',
    learner_profile_id: null,
};

let currentUser: AuthUser;
let pendingMutation: PendingAdapterRequest | null;
let pendingSessionFailure: PendingAdapterRequest | null;
let pendingTeacherBTopics: PendingAdapterRequest | null;
let deferTeacherBTopicRead: boolean;
let requestLog: Array<{
    method: string;
    principalId: string;
    url: string;
}>;
let originalAdapter: typeof apiClient.defaults.adapter;

function deferred<T>(): Deferred<T> {
    let resolve: (value: T) => void = () => undefined;
    let reject: (reason: unknown) => void = () => undefined;
    const promise = new Promise<T>((fulfill, fail) => {
        resolve = fulfill;
        reject = fail;
    });

    return {
        promise,
        reject,
        resolve,
    };
}

function success(
    config: InternalAxiosRequestConfig,
    data: unknown,
): AxiosResponse {
    return {
        config,
        data: {
            data,
        },
        headers: new AxiosHeaders(),
        status: 200,
        statusText: 'OK',
    };
}

function failure(
    config: InternalAxiosRequestConfig,
    status: number,
    requestId: string,
) {
    return {
        config,
        isAxiosError: true,
        message: 'Request failed.',
        name: 'AxiosError',
        response: {
            config,
            data: {
                error: {
                    code: status === 419
                        ? 'csrf_token_mismatch'
                        : status === 401
                            ? 'unauthenticated'
                            : 'resource_unavailable',
                    message: 'Request failed.',
                },
            },
            headers: new AxiosHeaders({
                'x-request-id': requestId,
            }),
            status,
            statusText: 'Request failed.',
        },
        toJSON: () => ({}),
    };
}

function assignment() {
    return {
        id: 'assignment-1',
        teacher_user_id: currentUser.id,
        subject: {
            id: 'subject-1',
            code: 'MATH',
            name: 'الرياضيات',
            status: 'active',
        },
        status: 'active',
        created_at: null,
        updated_at: null,
    };
}

function parseRequestData(data: unknown): Record<string, unknown> {
    if (typeof data === 'string') {
        return JSON.parse(data) as Record<string, unknown>;
    }

    return data as Record<string, unknown>;
}

function configureAdapter(): AxiosAdapter {
    return async (config) => {
        const method = (config.method ?? 'GET').toUpperCase();
        const url = config.url ?? '';

        requestLog.push({
            method,
            principalId: currentUser.id,
            url,
        });

        if (method === 'GET' && url === '/auth/me') {
            return success(config, {
                user: currentUser,
            });
        }

        if (method === 'POST' && url === '/auth/login') {
            const credentials = parseRequestData(config.data);

            currentUser = credentials.email === teacherB.email
                ? teacherB
                : users.teacher;

            return success(config, {
                user: currentUser,
            });
        }

        if (
            method === 'GET'
            && url === '/api/teacher/subject-assignments'
        ) {
            return success(config, [assignment()]);
        }

        if (
            method === 'GET'
            && url === '/api/teacher/subject-assignments/assignment-1/curricula'
        ) {
            return success(config, [{
                education_stage_id: 'stage-1',
                id: 'curriculum-1',
                name: 'منهج الرياضيات',
                subject_id: 'subject-1',
                teacher_subject_assignment_id: 'assignment-1',
            }]);
        }

        if (
            method === 'GET'
            && url === '/api/teacher/subject-assignments/assignment-1/curricula/curriculum-1/versions'
        ) {
            return success(config, [{
                curriculum_id: 'curriculum-1',
                id: 'version-1',
                label: 'الإصدار التجريبي',
                status: 'draft',
                version_number: 1,
            }]);
        }

        if (
            method === 'GET'
            && url.endsWith('/versions/version-1/topics')
        ) {
            if (
                currentUser.id === teacherB.id
                && deferTeacherBTopicRead
            ) {
                const pending = {
                    config,
                    deferred: deferred<AxiosResponse>(),
                };

                pendingTeacherBTopics = pending;

                return pending.deferred.promise;
            }

            return success(config, [{
                curriculum_version_id: 'version-1',
                display_order: 1,
                id: currentUser.id + '-topic',
                name: currentUser.id === teacherB.id
                    ? 'موضوع المعلم ب'
                    : 'موضوع المعلم أ',
            }]);
        }

        if (
            method === 'POST'
            && url.endsWith('/versions/version-1/topics')
        ) {
            const pending = {
                config,
                deferred: deferred<AxiosResponse>(),
            };

            pendingMutation = pending;

            return pending.deferred.promise;
        }

        if (
            method === 'GET'
            && url.endsWith('/versions/version-1/skill-placements')
        ) {
            return success(config, []);
        }

        if (method === 'GET' && url === '/api/teacher/skills') {
            return success(config, []);
        }

        if (method === 'GET' && url === '/api/session-probe') {
            const pending = {
                config,
                deferred: deferred<AxiosResponse>(),
            };

            pendingSessionFailure = pending;

            return pending.deferred.promise;
        }

        throw new Error(`Unexpected request: ${method} ${url}`);
    };
}

async function renderProduction(
    path = scopedPath,
) {
    await act(async () => {
        await router.navigate(path, {
            replace: true,
        });
    });

    return render(
        <AppProviders>
            <RouterProvider router={router} />
        </AppProviders>,
    );
}

async function expectWorkspace() {
    await waitFor(() => {
        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'تأليف المحتوى',
            }),
        ).toBeInTheDocument();
    });

    await waitFor(() => {
        expect(
            screen.getByRole('button', {
                name: 'إضافة موضوع',
            }),
        ).toBeInTheDocument();
    });
}

async function startDirtyMutation(
    draftName = 'مسودة معلّقة',
) {
    fireEvent.click(
        screen.getByRole('button', {
            name: 'إضافة موضوع',
        }),
    );
    fireEvent.change(
        screen.getByRole('textbox', {
            name: 'اسم الموضوع',
        }),
        {
            target: {
                value: draftName,
            },
        },
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'حفظ الموضوع',
        }),
    );

    await waitFor(() => {
        expect(pendingMutation).not.toBeNull();
    });

    expect(
        screen.getByRole('textbox', {
            name: 'اسم الموضوع',
        }),
    ).toHaveValue(draftName);
}

async function expireSession(
    status: 401 | 419,
    requestId: string,
) {
    const outcome = apiClient.get('/api/session-probe')
        .catch((error: unknown) => error);

    await waitFor(() => {
        expect(pendingSessionFailure).not.toBeNull();
    });

    await act(async () => {
        pendingSessionFailure!.deferred.reject(
            failure(
                pendingSessionFailure!.config,
                status,
                requestId,
            ),
        );

        await outcome;
    });

    const normalized = await outcome;

    expect(normalized).toMatchObject({
        requestId,
        status,
    });

    await waitFor(() => {
        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'تسجيل الدخول',
            }),
        ).toBeInTheDocument();
    });

    expect(router.state.location.pathname).toBe('/login');
    expect(
        screen.queryByRole('heading', {
            level: 1,
            name: 'تأليف المحتوى',
        }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('textbox', {
            name: 'اسم الموضوع',
        }),
    ).not.toBeInTheDocument();
}

async function settleMutationSuccess(
    name = 'نتيجة قديمة',
) {
    const pending = pendingMutation;

    expect(pending).not.toBeNull();

    await act(async () => {
        pending!.deferred.resolve(
            success(pending!.config, {
                curriculum_version_id: 'version-1',
                display_order: 0,
                id: 'late-topic',
                name,
            }),
        );

        await pending!.deferred.promise;
    });
}

async function settleMutationFailure(
    status: 403 | 404,
    requestId: string,
) {
    const pending = pendingMutation;

    expect(pending).not.toBeNull();

    await act(async () => {
        pending!.deferred.reject(
            failure(
                pending!.config,
                status,
                requestId,
            ),
        );

        await pending!.deferred.promise.catch(
            () => undefined,
        );
    });
}

async function loginAsTeacherB() {
    fireEvent.change(
        screen.getByRole('textbox', {
            name: 'البريد الإلكتروني',
        }),
        {
            target: {
                value: teacherB.email,
            },
        },
    );
    fireEvent.change(
        screen.getByLabelText('كلمة المرور'),
        {
            target: {
                value: 'secret',
            },
        },
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: 'تسجيل الدخول',
        }),
    );
}

describe('Teacher Workspace production authentication integration', () => {
    beforeEach(() => {
        originalAdapter = apiClient.defaults.adapter;
        currentUser = users.teacher;
        pendingMutation = null;
        pendingSessionFailure = null;
        pendingTeacherBTopics = null;
        deferTeacherBTopicRead = false;
        requestLog = [];
        apiClient.defaults.adapter = configureAdapter();
        window.localStorage.clear();
    });

    afterEach(async () => {
        cleanup();
        apiClient.defaults.adapter = originalAdapter;
        vi.restoreAllMocks();

        await router.navigate('/login', {
            replace: true,
        });
    });

    it('allows an authorized Teacher through the production role guard to the exact scoped workspace', async () => {
        await renderProduction();
        await expectWorkspace();

        expect(
            router.state.location.pathname
                + router.state.location.search,
        ).toBe(scopedPath);
        expect(
            screen.getAllByText('مساحة المعلم').length,
        ).toBeGreaterThan(0);
        await waitFor(() => {
            expect(
                screen.getByText('موضوع المعلم أ'),
            ).toBeInTheDocument();
        });
        expect(
            screen.getByRole('navigation', {
                name: 'أقسام مساحة المعلم',
            }),
        ).toBeInTheDocument();
    });

    it('rejects an authenticated Student from the production Teacher route', async () => {
        currentUser = users.student;

        await renderProduction();

        await waitFor(() => {
            expect(
                screen.getByRole('heading', {
                    level: 1,
                    name: 'غير مصرح لك بالوصول',
                }),
            ).toBeInTheDocument();
        });

        expect(router.state.location.pathname).toBe('/forbidden');
        expect(
            screen.queryByText('تأليف المحتوى'),
        ).not.toBeInTheDocument();
    });

    it('rejects an authenticated Admin from the production Teacher route', async () => {
        currentUser = users.admin;

        await renderProduction();

        await waitFor(() => {
            expect(
                screen.getByRole('heading', {
                    level: 1,
                    name: 'غير مصرح لك بالوصول',
                }),
            ).toBeInTheDocument();
        });

        expect(router.state.location.pathname).toBe('/forbidden');
        expect(
            screen.queryByText('تأليف المحتوى'),
        ).not.toBeInTheDocument();
    });

    it('removes a dirty protected workspace on runtime 401 and ignores a late mutation success', async () => {
        const confirmation = vi.spyOn(window, 'confirm')
            .mockReturnValue(false);

        await renderProduction();
        await expectWorkspace();
        await startDirtyMutation();

        await expireSession(401, 'runtime-401');

        expect(confirmation).not.toHaveBeenCalled();
        expect(
            screen.getByText(/runtime-401/),
        ).toBeInTheDocument();

        await settleMutationSuccess();

        expect(router.state.location.pathname).toBe('/login');
        expect(
            screen.queryByText('نتيجة قديمة'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('تأليف المحتوى'),
        ).not.toBeInTheDocument();
    });

    it('keeps runtime 419 fail-closed when a late mutation 403 arrives', async () => {
        const transitions: string[] = [];
        const confirmation = vi.spyOn(window, 'confirm')
            .mockReturnValue(false);
        const unsubscribe = router.subscribe((state) => {
            transitions.push(
                state.location.pathname
                + state.location.search,
            );
        });

        try {
            await renderProduction();
            await expectWorkspace();
            await startDirtyMutation('مسودة 419');
            await expireSession(419, 'runtime-419');

            expect(confirmation).not.toHaveBeenCalled();
            expect(
                screen.getByText(/runtime-419/),
            ).toBeInTheDocument();

            const transitionCount = transitions.length;

            await settleMutationFailure(403, 'late-403');

            expect(router.state.location.pathname).toBe('/login');
            expect(
                transitions.slice(transitionCount),
            ).not.toContain('/portal/teacher/workspace');
            expect(
                transitions.slice(transitionCount).some(
                    (path) => path.startsWith('/portal/teacher/workspace'),
                ),
            ).toBe(false);
            expect(
                screen.queryByText('تأليف المحتوى'),
            ).not.toBeInTheDocument();
        } finally {
            unsubscribe();
        }
    });

    it('keeps runtime 401 fail-closed without an intermediate redirect when a late mutation 404 arrives', async () => {
        const transitions: string[] = [];
        const unsubscribe = router.subscribe((state) => {
            transitions.push(
                state.location.pathname
                + state.location.search,
            );
        });

        try {
            await renderProduction();
            await expectWorkspace();
            await startDirtyMutation('مسودة 404');
            await expireSession(401, 'runtime-401-late-404');

            const transitionCount = transitions.length;

            await settleMutationFailure(404, 'late-404');

            expect(router.state.location.pathname).toBe('/login');
            expect(
                transitions.slice(transitionCount).some(
                    (path) => path.startsWith('/portal/teacher/workspace'),
                ),
            ).toBe(false);
            expect(
                screen.queryByText('سياق التأليف غير متاح'),
            ).not.toBeInTheDocument();
        } finally {
            unsubscribe();
        }
    });

    it('restores the scoped deep link for Teacher B without exposing Teacher A cache, draft, or late result', async () => {
        deferTeacherBTopicRead = true;

        await renderProduction();
        await expectWorkspace();
        await startDirtyMutation('مسودة المعلم أ');
        await expireSession(401, 'switch-principal-401');

        await loginAsTeacherB();

        await waitFor(() => {
            expect(pendingTeacherBTopics).not.toBeNull();
        });

        expect(
            router.state.location.pathname
                + router.state.location.search,
        ).toBe(scopedPath);
        expect(
            screen.queryByText('موضوع المعلم أ'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('مسودة المعلم أ'),
        ).not.toBeInTheDocument();

        await settleMutationSuccess('نتيجة المعلم أ');

        expect(
            screen.queryByText('نتيجة المعلم أ'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('موضوع المعلم أ'),
        ).not.toBeInTheDocument();

        const pending = pendingTeacherBTopics;

        await act(async () => {
            pending!.deferred.resolve(
                success(pending!.config, [{
                    curriculum_version_id: 'version-1',
                    display_order: 1,
                    id: 'teacher-b-topic',
                    name: 'موضوع المعلم ب',
                }]),
            );

            await pending!.deferred.promise;
        });

        await waitFor(() => {
            expect(
                screen.getByText('موضوع المعلم ب'),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByText('المعلم ب'),
        ).toBeInTheDocument();
        expect(
            router.state.location.pathname
                + router.state.location.search,
        ).toBe(scopedPath);
        expect(
            requestLog,
        ).toContainEqual({
            method: 'GET',
            principalId: 'teacher-b',
            url: '/api/teacher/subject-assignments/assignment-1/curricula/curriculum-1/versions/version-1/topics',
        });
    });
});

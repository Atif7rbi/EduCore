import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    createMemoryRouter,
    RouterProvider,
    useLocation,
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
    apiRequest,
} from '../api/client';
import {
    EduCoreApiError,
} from '../api/errors';
import {
    emitSessionFailure,
} from '../api/sessionEvents';
import {
    AppProviders,
} from '../app/providers';
import {
    useAuth,
} from '../auth/AuthProvider';

import {
    TeacherWorkspacePage,
} from './TeacherWorkspacePage';

interface Deferred<T> {
    promise: Promise<T>;
    reject: (error: unknown) => void;
    resolve: (value: T) => void;
}

interface RequestConfig {
    data?: Record<string, unknown>;
    method?: string;
    url?: string;
}

type Settlement = 'success' | 403 | 404 | 409;

const apiRequestMock = vi.mocked(apiRequest);
const contextA =
    '/portal/teacher/workspace/assignment-a/'
    + 'curricula/curriculum-a/versions/version-a';
const contextB =
    '/portal/teacher/workspace/assignment-b/'
    + 'curricula/curriculum-b/versions/version-b';

let currentPrincipal = 'teacher-a';
let pendingTopicMutation: Deferred<unknown> | null = null;
let versionReads = new Map<string, number>();

vi.mock('../api/client', () => ({
    apiRequest: vi.fn(),
}));

function deferred<T>(): Deferred<T> {
    let resolve: (value: T) => void = () => undefined;
    let reject: (error: unknown) => void = () => undefined;
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

function teacher(id: string) {
    return {
        email: `${id}@example.com`,
        id,
        learner_profile_id: null,
        name: id,
        role: 'teacher' as const,
        status: 'active' as const,
    };
}

function apiError(status: 403 | 404 | 409) {
    return new EduCoreApiError({
        code: status === 409 ? 'lifecycle_conflict' : 'resource_unavailable',
        message: `Deferred ${status}`,
        requestId: `deferred-${status}`,
        status,
    });
}

function contextPart(url: string, segment: 'assignment' | 'curriculum') {
    const match = url.match(
        segment === 'assignment'
            ? /subject-assignments\/(assignment-[^/]+)/
            : /curricula\/(curriculum-[^/]+)/,
    );

    return match?.[1] ?? `${segment}-a`;
}

function configureApi() {
    apiRequestMock.mockImplementation((config: RequestConfig) => {
        const method = config.method ?? 'GET';
        const url = config.url ?? '';

        if (method === 'GET' && url === '/auth/me') {
            return Promise.resolve({
                user: teacher(currentPrincipal),
            });
        }

        if (method === 'POST' && url === '/auth/login') {
            currentPrincipal = config.data?.email === 'teacher-b@example.com'
                ? 'teacher-b'
                : 'teacher-a';

            return Promise.resolve({
                user: teacher(currentPrincipal),
            });
        }

        if (method === 'GET' && url === '/api/teacher/subject-assignments') {
            return Promise.resolve([
                {
                    created_at: null,
                    id: 'assignment-a',
                    status: 'active',
                    subject: {
                        code: 'SUB-A',
                        id: 'subject-a',
                        name: 'Subject A',
                        status: 'active',
                    },
                    teacher_user_id: currentPrincipal,
                    updated_at: null,
                },
                {
                    created_at: null,
                    id: 'assignment-b',
                    status: 'active',
                    subject: {
                        code: 'SUB-B',
                        id: 'subject-b',
                        name: 'Subject B',
                        status: 'active',
                    },
                    teacher_user_id: currentPrincipal,
                    updated_at: null,
                },
            ]);
        }

        if (method === 'GET' && url.endsWith('/curricula')) {
            const assignmentId = contextPart(url, 'assignment');
            const suffix = assignmentId.endsWith('-b') ? 'b' : 'a';

            return Promise.resolve([
                {
                    education_stage_id: null,
                    id: `curriculum-${suffix}`,
                    name: `Curriculum ${suffix.toUpperCase()}`,
                    subject_id: `subject-${suffix}`,
                    teacher_subject_assignment_id: assignmentId,
                },
            ]);
        }

        if (method === 'GET' && url.endsWith('/versions')) {
            const curriculumId = contextPart(url, 'curriculum');
            const suffix = curriculumId.endsWith('-b') ? 'b' : 'a';
            versionReads.set(
                curriculumId,
                (versionReads.get(curriculumId) ?? 0) + 1,
            );

            return Promise.resolve([
                {
                    curriculum_id: curriculumId,
                    id: `version-${suffix}`,
                    label: `Version ${suffix.toUpperCase()}`,
                    status: 'draft',
                    version_number: 1,
                },
            ]);
        }

        if (method === 'POST' && url.endsWith('/topics')) {
            pendingTopicMutation = deferred();

            return pendingTopicMutation.promise;
        }

        if (method === 'GET' && (
            url.endsWith('/topics')
            || url.endsWith('/skill-placements')
            || url.endsWith('/lessons')
            || url.endsWith('/assessment-items')
            || url === '/api/teacher/skills'
        )) {
            return Promise.resolve([]);
        }

        return Promise.reject(
            new Error(`Unexpected request ${method} ${url}`),
        );
    });
}

function LocationProbe() {
    const location = useLocation();

    return (
        <output data-testid="location">
            {location.pathname + location.search + location.hash}
        </output>
    );
}

function PrincipalControls() {
    const {
        login,
        status,
        user,
    } = useAuth();

    return (
        <div>
            <output data-testid="principal">
                {status === 'authenticated' ? user?.id : 'unauthenticated'}
            </output>
            <button
                onClick={() => {
                    void login({
                        email: 'teacher-b@example.com',
                        password: 'secret',
                    });
                }}
                type="button"
            >
                Switch principal
            </button>
        </div>
    );
}

function WorkspaceRoute() {
    return (
        <>
            <LocationProbe />
            <TeacherWorkspacePage />
        </>
    );
}

function renderWorkspace(path = `${contextA}?section=topics`) {
    const router = createMemoryRouter(
        [
            {
                element: <WorkspaceRoute />,
                path: '/portal/teacher/workspace',
            },
            {
                element: <WorkspaceRoute />,
                path:
                    '/portal/teacher/workspace/:assignmentId/'
                    + 'curricula/:curriculumId/versions/:versionId',
            },
        ],
        {
            initialEntries: [path],
        },
    );

    render(
        <AppProviders>
            <PrincipalControls />
            <RouterProvider router={router} />
        </AppProviders>,
    );

    return router;
}

async function waitForTopics() {
    await screen.findByRole('button', {
        name: 'إضافة موضوع',
    });
}

async function startTopicMutation() {
    fireEvent.click(screen.getByRole('button', { name: 'إضافة موضوع' }));
    fireEvent.change(screen.getByLabelText('اسم الموضوع'), {
        target: { value: 'Deferred topic' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'حفظ الموضوع' }));

    await waitFor(() => expect(pendingTopicMutation).not.toBeNull());
}

async function settleMutation(settlement: Settlement) {
    await act(async () => {
        if (settlement === 'success') {
            pendingTopicMutation?.resolve({
                curriculum_version_id: 'version-a',
                display_order: 0,
                id: 'topic-created',
                name: 'Deferred topic',
            });
        } else {
            pendingTopicMutation?.reject(apiError(settlement));
        }

        await pendingTopicMutation?.promise.catch(() => undefined);
    });
}

function expectLocation(path: string) {
    expect(screen.getByTestId('location')).toHaveTextContent(path);
}

describe('Teacher Workspace async instance isolation', () => {
    beforeEach(() => {
        currentPrincipal = 'teacher-a';
        pendingTopicMutation = null;
        versionReads = new Map();
        apiRequestMock.mockReset();
        configureApi();
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it.each<Settlement>([
        'success',
        403,
        404,
        409,
    ])('isolates a late %s result after section A changes to B', async (settlement) => {
        renderWorkspace();
        await waitForTopics();
        await startTopicMutation();
        const versionReadsBefore = versionReads.get('curriculum-a') ?? 0;

        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByRole('heading', { name: 'الدروس ونسخها' });
        await settleMutation(settlement);

        await waitFor(() => {
            expectLocation(`${contextA}?section=lessons`);
        });
        expect(screen.getByRole('heading', { name: 'الدروس ونسخها' })).toBeInTheDocument();
        expect(screen.queryByLabelText('اسم الموضوع')).not.toBeInTheDocument();

        if (settlement === 409) {
            await waitFor(() => {
                expect(versionReads.get('curriculum-a')).toBeGreaterThan(
                    versionReadsBefore,
                );
            });
        }
    });

    it.each<Settlement>([
        'success',
        403,
        404,
        409,
    ])('isolates a late %s result after context A changes to B', async (settlement) => {
        const router = renderWorkspace();
        await waitForTopics();
        await startTopicMutation();
        const oldVersionReads = versionReads.get('curriculum-a') ?? 0;

        await act(async () => {
            await router.navigate(`${contextB}?section=lessons`);
        });
        await screen.findByRole('heading', { name: 'الدروس ونسخها' });
        await settleMutation(settlement);

        await waitFor(() => {
            expectLocation(`${contextB}?section=lessons`);
        });
        expect(screen.getByRole('heading', { name: 'الدروس ونسخها' })).toBeInTheDocument();
        expect(versionReads.get('curriculum-a')).toBe(oldVersionReads);
    });

    it.each<Settlement>([
        'success',
        403,
        404,
    ])('isolates principal B from principal A late %s', async (settlement) => {
        renderWorkspace();
        await waitForTopics();
        await startTopicMutation();

        fireEvent.click(screen.getByRole('button', { name: 'Switch principal' }));
        await waitFor(() => {
            expect(screen.getByTestId('principal')).toHaveTextContent('teacher-b');
        });
        await waitForTopics();
        await settleMutation(settlement);

        expect(screen.getByTestId('principal')).toHaveTextContent('teacher-b');
        expectLocation(`${contextA}?section=topics`);
        expect(screen.getByRole('button', { name: 'إضافة موضوع' })).toBeInTheDocument();
    });

    it.each([
        ['expired', 'runtime-401'],
        ['csrf', 'runtime-419'],
    ] as const)('keeps a pending mutation isolated after %s session invalidation', async (kind, requestId) => {
        renderWorkspace();
        await waitForTopics();
        await startTopicMutation();

        act(() => {
            emitSessionFailure({
                kind,
                requestId,
            });
        });
        await waitFor(() => {
            expect(screen.getByTestId('principal')).toHaveTextContent('unauthenticated');
        });
        await settleMutation('success');

        expect(screen.queryByRole('button', { name: 'إضافة موضوع' }))
            .not.toBeInTheDocument();
        expect(screen.getByTestId('principal')).toHaveTextContent('unauthenticated');
    });

    it('does not reactivate the original section A instance after A to B to A', async () => {
        renderWorkspace();
        await waitForTopics();
        await startTopicMutation();

        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByRole('heading', { name: 'الدروس ونسخها' });
        fireEvent.click(
            screen.getByRole('button', { name: 'الموضوعات والمهارات' }),
        );
        await waitForTopics();
        await settleMutation(404);

        expectLocation(`${contextA}?section=topics`);
        expect(screen.getByRole('button', { name: 'إضافة موضوع' })).toBeInTheDocument();
    });
});

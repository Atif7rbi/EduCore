import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
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
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    TeacherWorkspacePage,
} from './TeacherWorkspacePage';

const selectorProps = vi.fn();

vi.mock('../auth/AuthProvider', () => ({
    useAuth: () => ({
        status: 'authenticated',
        user: {
            email: 'teacher@example.com',
            id: 'teacher-a',
            learner_profile_id: null,
            name: 'Teacher',
            role: 'teacher',
            status: 'active',
        },
    }),
}));

vi.mock('./TeacherTopicsPanel', () => ({
    TeacherTopicsPanel: (props: {
        onContextUnavailable: () => void;
        onDirtyChange: (dirty: boolean) => void;
        onLifecycleConflict: () => Promise<void>;
    }) => (
        <div data-testid="teacher-topics">
            <button
                onClick={() => props.onDirtyChange(true)}
                type="button"
            >
                Mark unsaved
            </button>

            <button
                onClick={() => {
                    void props.onLifecycleConflict();
                }}
                type="button"
            >
                Refresh lifecycle authority
            </button>

            <button
                onClick={props.onContextUnavailable}
                type="button"
            >
                Trigger 403
            </button>

            <button
                onClick={props.onContextUnavailable}
                type="button"
            >
                Trigger 404
            </button>
        </div>
    ),
}));

vi.mock('./TeacherSkillPlacementsPanel', () => ({
    TeacherSkillPlacementsPanel: () => (
        <div data-testid="teacher-skill-placements" />
    ),
}));

vi.mock('./TeacherContextSelector', () => ({
    TeacherContextSelector: (props: {
        assignmentId: string | null;
        authenticatedUserId: string;
        curriculumId: string | null;
        curriculumVersionId: string | null;
        onContextResolved: (context: {
            assignmentId: string;
            curriculumId: string;
            curriculumVersionId: string;
            versionStatus: 'draft' | 'published' | 'retired';
        }) => void;
        onContextUnavailable: () => void;
    }) => {
        selectorProps(props);

        return (
            <div data-testid="teacher-context-selector">
                <output data-testid="selector-user">
                    {props.authenticatedUserId}
                </output>

                <output data-testid="selector-context">
                    {
                        [
                            props.assignmentId,
                            props.curriculumId,
                            props.curriculumVersionId,
                        ].join(':')
                    }
                </output>

                <button
                    onClick={() => {
                        props.onContextResolved({
                            assignmentId: 'assignment-a',
                            curriculumId: 'curriculum-a',
                            curriculumVersionId: 'version-a',
                            versionStatus: 'draft',
                        });
                    }}
                    type="button"
                >
                    Resolve context
                </button>

                <button
                    onClick={props.onContextUnavailable}
                    type="button"
                >
                    Invalidate context
                </button>
            </div>
        );
    },
}));

function WorkspaceRoute() {
    const location = useLocation();

    return (
        <>
            <output data-testid="location">
                {location.pathname}
            </output>

            <TeacherWorkspacePage />
        </>
    );
}

function renderWorkspace(
    path: string,
) {
    selectorProps.mockClear();
    const router = createMemoryRouter(
        [
            {
                element: <WorkspaceRoute />,
                path: '/teacher/workspace',
            },
            {
                element: <WorkspaceRoute />,
                path:
                    '/teacher/workspace/:assignmentId/'
                    + 'curricula/:curriculumId/versions/:versionId',
            },
        ],
        {
            initialEntries: [path],
        },
    );

    const client = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <RouterProvider router={router} />
        </QueryClientProvider>,
    );

    return {
        client,
        router,
    };
}

describe('TeacherWorkspacePage', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the teacher context selector for an authenticated teacher', () => {
        renderWorkspace('/teacher/workspace');

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'تأليف المحتوى',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId(
                'teacher-context-selector',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByTestId('selector-user'),
        ).toHaveTextContent('teacher-a');
    });

    it('restores a scoped URL and navigates only after a complete context resolves', async () => {
        renderWorkspace(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
        );

        expect(
            screen.getByTestId('selector-context'),
        ).toHaveTextContent(
            'assignment-a:curriculum-a:version-a',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Resolve context',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('location'),
            ).toHaveTextContent(
                '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
            );
        });
    });

    it('returns an unavailable context to the unscoped workspace with a generic message', async () => {
        renderWorkspace(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Invalidate context',
            }),
        );

        await waitFor(() => {
            expect(
                screen.getByTestId('location'),
            ).toHaveTextContent(
                '/teacher/workspace',
            );
        });

        expect(
            screen.getByText(
                /سياق التأليف غير متاح/,
            ),
        ).toBeInTheDocument();
    });

    it('keeps a dirty workspace in place when navigation confirmation is cancelled', async () => {
        const confirm = vi.spyOn(
            window,
            'confirm',
        ).mockReturnValue(false);
        const {
            router,
        } = renderWorkspace(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Resolve context',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Mark unsaved',
            }),
        );

        const beforeUnload = new Event(
            'beforeunload',
            {
                cancelable: true,
            },
        );
        window.dispatchEvent(beforeUnload);
        expect(beforeUnload.defaultPrevented).toBe(true);

        await act(async () => {
            await router.navigate('/teacher/workspace');
        });

        await waitFor(() => {
            expect(confirm).toHaveBeenCalledTimes(1);
            expect(
                screen.getByTestId('location'),
            ).toHaveTextContent(
                '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
            );
        });
    });

    it('discards dirty state only after confirmed workspace navigation', async () => {
        const confirm = vi.spyOn(
            window,
            'confirm',
        ).mockReturnValue(true);
        const {
            router,
        } = renderWorkspace(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Resolve context',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Mark unsaved',
            }),
        );

        await act(async () => {
            await router.navigate('/teacher/workspace');
        });

        await waitFor(() => {
            expect(confirm).toHaveBeenCalledTimes(1);
            expect(
                screen.getByTestId('location'),
            ).toHaveTextContent('/teacher/workspace');
        });
    });


    it.each([
        'Trigger 403',
        'Trigger 404',
    ])(
        'forces unavailable-context navigation after %s despite dirty state',
        async (trigger) => {
            const confirm = vi.spyOn(
                window,
                'confirm',
            ).mockReturnValue(false);
            renderWorkspace(
                '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
            );

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Resolve context',
                }),
            );
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Mark unsaved',
                }),
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: trigger,
                }),
            );

            await waitFor(() => {
                expect(
                    screen.getByTestId('location'),
                ).toHaveTextContent('/teacher/workspace');
            });
            expect(confirm).not.toHaveBeenCalled();
            expect(
                screen.getByText(
                    /سياق التأليف غير متاح/,
                ),
            ).toBeInTheDocument();
            expect(
                screen.queryByTestId('teacher-topics'),
            ).not.toBeInTheDocument();
        },
    );


    it('refreshes the authoritative version query after a taxonomy conflict', async () => {
        const {
            client,
        } = renderWorkspace(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a',
        );
        const invalidateQueries = vi.spyOn(
            client,
            'invalidateQueries',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Resolve context',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Refresh lifecycle authority',
            }),
        );

        await waitFor(() => {
            expect(invalidateQueries).toHaveBeenCalledWith({
                queryKey: [
                    'teacher',
                    'teacher-a',
                    'assignment',
                    'assignment-a',
                    'curriculum',
                    'curriculum-a',
                    'curriculum-version',
                    null,
                    'resource',
                    'curriculum-versions',
                    null,
                ],
            });
        });
    });

});

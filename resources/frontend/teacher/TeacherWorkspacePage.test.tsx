import {
    useState,
} from 'react';
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
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    TeacherWorkspacePage,
} from './TeacherWorkspacePage';

const scopedPath =
    '/teacher/workspace/assignment-a/'
    + 'curricula/curriculum-a/versions/version-a';
const selectorProps = vi.fn();

let resolveLateTopic: ((value: string) => void) | null = null;
let lateTopicResponse = Promise.resolve('');

function resetLateTopicResponse() {
    lateTopicResponse = new Promise((resolve) => {
        resolveLateTopic = resolve;
    });
}

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

interface DraftPanelProps {
    dirtyLabel: string;
    draftLabel: string;
    onDirtyChange: (dirty: boolean) => void;
    testId: string;
}

function DraftPanel({
    dirtyLabel,
    draftLabel,
    onDirtyChange,
    testId,
}: DraftPanelProps) {
    const [draft, setDraft] = useState('');

    return (
        <div data-testid={testId}>
            <label>
                {draftLabel}
                <input
                    aria-label={draftLabel}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        onDirtyChange(event.target.value !== '');
                    }}
                    value={draft}
                />
            </label>

            <button
                onClick={() => onDirtyChange(true)}
                type="button"
            >
                {dirtyLabel}
            </button>
        </div>
    );
}

vi.mock('./TeacherTopicsPanel', () => ({
    TeacherTopicsPanel: (props: {
        onContextUnavailable: () => void;
        onDirtyChange: (dirty: boolean) => void;
        onLifecycleConflict: () => Promise<void>;
    }) => {
        const [lateResult, setLateResult] = useState('');

        return (
            <div data-testid="teacher-topics">
                <DraftPanel
                    dirtyLabel="Mark unsaved"
                    draftLabel="Topic draft"
                    onDirtyChange={props.onDirtyChange}
                    testId="teacher-topics-draft"
                />

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

                <button
                    onClick={() => {
                        void lateTopicResponse.then(setLateResult);
                    }}
                    type="button"
                >
                    Start late topic response
                </button>

                {lateResult ? <output>{lateResult}</output> : null}
            </div>
        );
    },
}));

vi.mock('./TeacherSkillPlacementsPanel', () => ({
    TeacherSkillPlacementsPanel: () => (
        <div data-testid="teacher-skill-placements" />
    ),
}));

vi.mock('./TeacherLessonsPanel', () => ({
    TeacherLessonsPanel: (props: {
        onDirtyChange: (dirty: boolean) => void;
    }) => (
        <DraftPanel
            dirtyLabel="Mark lesson unsaved"
            draftLabel="Lesson draft"
            onDirtyChange={props.onDirtyChange}
            testId="teacher-lessons"
        />
    ),
}));

vi.mock('./TeacherAssessmentItemsPanel', () => ({
    TeacherAssessmentItemsPanel: (props: {
        onDirtyChange: (dirty: boolean) => void;
    }) => (
        <DraftPanel
            dirtyLabel="Mark assessment unsaved"
            draftLabel="Assessment draft"
            onDirtyChange={props.onDirtyChange}
            testId="teacher-assessments"
        />
    ),
}));

vi.mock('./TeacherPracticeActivitiesPanel', () => ({
    TeacherPracticeActivitiesPanel: (props: {
        onDirtyChange: (dirty: boolean) => void;
    }) => (
        <DraftPanel
            dirtyLabel="Mark practice unsaved"
            draftLabel="Practice draft"
            onDirtyChange={props.onDirtyChange}
            testId="teacher-practice"
        />
    ),
}));

vi.mock('./TeacherExamTemplatesPanel', () => ({
    TeacherExamTemplatesPanel: (props: {
        onDirtyChange: (dirty: boolean) => void;
    }) => (
        <DraftPanel
            dirtyLabel="Mark exam unsaved"
            draftLabel="Exam draft"
            onDirtyChange={props.onDirtyChange}
            testId="teacher-exams"
        />
    ),
}));

interface ContextValue {
    assignmentId: string;
    curriculumId: string;
    curriculumVersionId: string;
    versionStatus: 'draft' | 'published' | 'retired';
}

interface SelectorMockProps {
    assignmentId: string | null;
    authenticatedUserId: string;
    curriculumId: string | null;
    curriculumVersionId: string | null;
    onBeforeContextChange?: () => boolean;
    onContextResolved: (context: ContextValue) => void;
    onContextUnavailable: () => void;
}

vi.mock('./TeacherContextSelector', () => ({
    TeacherContextSelector: (props: SelectorMockProps) => {
        selectorProps(props);
        const currentContext = {
            assignmentId: props.assignmentId ?? 'assignment-a',
            curriculumId: props.curriculumId ?? 'curriculum-a',
            curriculumVersionId:
                props.curriculumVersionId ?? 'version-a',
            versionStatus: 'draft' as const,
        };

        function resolveChangedContext(
            changes: Partial<ContextValue>,
        ) {
            if (props.onBeforeContextChange?.() === false) {
                return;
            }

            props.onContextResolved({
                ...currentContext,
                ...changes,
            });
        }

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
                    onClick={() => props.onContextResolved(currentContext)}
                    type="button"
                >
                    Resolve context
                </button>

                <button
                    onClick={() => resolveChangedContext({
                        assignmentId: 'assignment-b',
                    })}
                    type="button"
                >
                    Select assignment B
                </button>

                <button
                    onClick={() => resolveChangedContext({
                        curriculumId: 'curriculum-b',
                    })}
                    type="button"
                >
                    Select curriculum B
                </button>

                <button
                    onClick={() => resolveChangedContext({
                        curriculumVersionId: 'version-b',
                    })}
                    type="button"
                >
                    Select version B
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
                {location.pathname + location.search + location.hash}
            </output>

            <TeacherWorkspacePage />
        </>
    );
}

function renderWorkspace(path: string) {
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

async function resolveContext() {
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Resolve context',
        }),
    );

    await screen.findByRole('navigation', {
        name: 'أقسام مساحة المعلم',
    });
}

function expectLocation(expected: string) {
    expect(screen.getByTestId('location')).toHaveTextContent(expected);
}

describe('TeacherWorkspacePage', () => {
    beforeEach(() => {
        resetLateTopicResponse();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders context selection without protected sections before authority resolves', () => {
        renderWorkspace(`${scopedPath}?section=topics`);

        expect(
            screen.getByTestId('teacher-context-selector'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('navigation', {
                name: 'أقسام مساحة المعلم',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByTestId('teacher-topics'),
        ).not.toBeInTheDocument();
    });

    it('normalizes a missing section only after context resolution', async () => {
        renderWorkspace(scopedPath);

        expectLocation(scopedPath);
        expect(
            screen.queryByTestId('teacher-topics'),
        ).not.toBeInTheDocument();

        await resolveContext();

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=topics`);
        });
        expect(screen.getByTestId('teacher-topics')).toBeInTheDocument();
    });

    it('normalizes an unsupported section after context resolution', async () => {
        renderWorkspace(`${scopedPath}?section=unknown`);

        expect(
            screen.queryByTestId('teacher-topics'),
        ).not.toBeInTheDocument();

        await resolveContext();

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=topics`);
        });
    });

    it('restores a valid section deep link after context resolution', async () => {
        renderWorkspace(`${scopedPath}?section=lessons`);

        await resolveContext();

        expectLocation(`${scopedPath}?section=lessons`);
        expect(screen.getByTestId('teacher-lessons')).toBeInTheDocument();
        expect(
            screen.queryByTestId('teacher-topics'),
        ).not.toBeInTheDocument();
    });

    it.each([
        ['topics', 'الموضوعات والمهارات', 'teacher-topics'],
        ['lessons', 'الدروس', 'teacher-lessons'],
        ['assessments', 'عناصر التقييم', 'teacher-assessments'],
        ['practice', 'أنشطة التدريب', 'teacher-practice'],
        ['exams', 'نماذج الامتحان', 'teacher-exams'],
    ])(
        'renders only the %s section from the URL',
        async (section, label, testId) => {
            renderWorkspace(`${scopedPath}?section=${section}`);
            await resolveContext();

            expect(
                screen.getByRole('button', {
                    name: label,
                }),
            ).toHaveAttribute('aria-current', 'page');
            expect(screen.getByTestId(testId)).toBeInTheDocument();

            const sectionIds = [
                'teacher-topics',
                'teacher-lessons',
                'teacher-assessments',
                'teacher-practice',
                'teacher-exams',
            ];
            sectionIds
                .filter((candidate) => candidate !== testId)
                .forEach((candidate) => {
                    expect(
                        screen.queryByTestId(candidate),
                    ).not.toBeInTheDocument();
                });
        },
    );

    it('switches a clean section without confirmation', async () => {
        const confirm = vi.spyOn(window, 'confirm');
        renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();

        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=lessons`);
        });
        expect(confirm).not.toHaveBeenCalled();
        expect(screen.getByTestId('teacher-lessons')).toBeInTheDocument();
    });

    it('keeps the exact URL, active panel and draft when section discard is cancelled', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();

        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'unsaved topic' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=topics`);
        });
        expect(screen.getByLabelText('Topic draft')).toHaveValue(
            'unsaved topic',
        );
        expect(screen.getByTestId('teacher-topics')).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'الموضوعات والمهارات',
            }),
        ).toHaveAttribute('aria-current', 'page');
    });

    it('discards the old draft only after a confirmed section transition', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();

        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'discard me' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=lessons`);
        });
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.queryByLabelText('Topic draft')).not.toBeInTheDocument();
        expect(screen.getByTestId('teacher-lessons')).toBeInTheDocument();
    });

    it('prompts again after a cancelled section transition is retried', async () => {
        const confirm = vi.spyOn(window, 'confirm')
            .mockReturnValueOnce(false)
            .mockReturnValueOnce(true);
        renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'retry me' },
        });

        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await waitFor(() => expect(confirm).toHaveBeenCalledTimes(1));
        expectLocation(`${scopedPath}?section=topics`);

        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await waitFor(() => {
            expectLocation(`${scopedPath}?section=lessons`);
        });
        expect(confirm).toHaveBeenCalledTimes(2);
    });

    it('does not let a confirmed transition bypass a later unrelated navigation', async () => {
        const confirm = vi.spyOn(window, 'confirm')
            .mockReturnValueOnce(true)
            .mockReturnValueOnce(false);
        renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'first draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByTestId('teacher-lessons');

        fireEvent.change(screen.getByLabelText('Lesson draft'), {
            target: { value: 'second draft' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'عناصر التقييم' }),
        );

        await waitFor(() => expect(confirm).toHaveBeenCalledTimes(2));
        expectLocation(`${scopedPath}?section=lessons`);
        expect(screen.getByLabelText('Lesson draft')).toHaveValue(
            'second draft',
        );
    });

    it('cancels browser back while the active section is dirty', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const { router } = renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByTestId('teacher-lessons');
        fireEvent.change(screen.getByLabelText('Lesson draft'), {
            target: { value: 'keep on back' },
        });

        await act(async () => {
            await router.navigate(-1);
        });

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=lessons`);
        });
        expect(screen.getByLabelText('Lesson draft')).toHaveValue(
            'keep on back',
        );
    });

    it('allows confirmed browser back to the previous section', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const { router } = renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByTestId('teacher-lessons');
        fireEvent.change(screen.getByLabelText('Lesson draft'), {
            target: { value: 'discard on back' },
        });

        await act(async () => {
            await router.navigate(-1);
        });

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=topics`);
        });
        expect(screen.getByTestId('teacher-topics')).toBeInTheDocument();
    });

    it('cancels browser forward and preserves the exact section draft', async () => {
        const { router } = renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByTestId('teacher-lessons');

        await act(async () => {
            await router.navigate(-1);
        });
        await screen.findByTestId('teacher-topics');

        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'keep on forward' },
        });

        await act(async () => {
            await router.navigate(1);
        });

        await waitFor(() => expect(confirm).toHaveBeenCalledTimes(1));
        expectLocation(`${scopedPath}?section=topics`);
        expect(screen.getByTestId('teacher-topics')).toBeInTheDocument();
        expect(screen.getByLabelText('Topic draft')).toHaveValue(
            'keep on forward',
        );
    });

    it('confirms browser forward for only that destination', async () => {
        const { router } = renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByTestId('teacher-lessons');

        await act(async () => {
            await router.navigate(-1);
        });
        await screen.findByTestId('teacher-topics');

        const confirm = vi.spyOn(window, 'confirm')
            .mockReturnValueOnce(true)
            .mockReturnValueOnce(false);
        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'discard on forward' },
        });

        await act(async () => {
            await router.navigate(1);
        });

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=lessons`);
        });
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.queryByLabelText('Topic draft')).not.toBeInTheDocument();
        expect(screen.getByTestId('teacher-lessons')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Lesson draft'), {
            target: { value: 'second navigation draft' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'عناصر التقييم' }),
        );

        await waitFor(() => expect(confirm).toHaveBeenCalledTimes(2));
        expectLocation(`${scopedPath}?section=lessons`);
        expect(screen.getByLabelText('Lesson draft')).toHaveValue(
            'second navigation draft',
        );
    });

    it.each([
        [
            'Select assignment B',
            '/teacher/workspace/assignment-b/curricula/curriculum-a/versions/version-a',
        ],
        [
            'Select curriculum B',
            '/teacher/workspace/assignment-a/curricula/curriculum-b/versions/version-a',
        ],
        [
            'Select version B',
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-b',
        ],
    ])(
        'preserves the current section during %s',
        async (buttonName, nextPath) => {
            renderWorkspace(`${scopedPath}?section=exams`);
            await resolveContext();

            fireEvent.click(screen.getByRole('button', { name: buttonName }));

            await waitFor(() => {
                expectLocation(`${nextPath}?section=exams`);
            });
            expect(
                screen.queryByRole('navigation', {
                    name: 'أقسام مساحة المعلم',
                }),
            ).not.toBeInTheDocument();

            await resolveContext();
            expect(screen.getByTestId('teacher-exams')).toBeInTheDocument();
        },
    );

    it('uses topics when an invalid section is replaced with a new context', async () => {
        renderWorkspace(`${scopedPath}?section=invalid`);
        await resolveContext();
        await waitFor(() => {
            expectLocation(`${scopedPath}?section=topics`);
        });

        fireEvent.click(
            screen.getByRole('button', { name: 'Select version B' }),
        );

        await waitFor(() => {
            expectLocation(
                '/teacher/workspace/assignment-a/curricula/'
                + 'curriculum-a/versions/version-b?section=topics',
            );
        });
    });

    it('preserves the hash during section navigation', async () => {
        renderWorkspace(`${scopedPath}?section=topics#editor`);
        await resolveContext();

        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));

        await waitFor(() => {
            expectLocation(`${scopedPath}?section=lessons#editor`);
        });
    });

    it.each([
        'Trigger 403',
        'Trigger 404',
    ])(
        'forces unavailable-context navigation after %s despite dirty state',
        async (trigger) => {
            const confirm = vi.spyOn(window, 'confirm')
                .mockReturnValue(false);
            renderWorkspace(`${scopedPath}?section=topics`);
            await resolveContext();
            fireEvent.change(screen.getByLabelText('Topic draft'), {
                target: { value: 'protected content' },
            });

            fireEvent.click(screen.getByRole('button', { name: trigger }));

            expect(
                screen.queryByTestId('teacher-topics'),
            ).not.toBeInTheDocument();
            await waitFor(() => {
                expectLocation('/teacher/workspace');
            });
            expect(confirm).not.toHaveBeenCalled();
            expect(
                screen.getByText(/سياق التأليف غير متاح/),
            ).toBeInTheDocument();
        },
    );

    it('does not display a late response after the old section unmounts', async () => {
        renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Start late topic response',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'الدروس' }));
        await screen.findByTestId('teacher-lessons');

        await act(async () => {
            resolveLateTopic?.('late topic result');
            await lateTopicResponse;
        });

        expect(
            screen.queryByText('late topic result'),
        ).not.toBeInTheDocument();
        expectLocation(`${scopedPath}?section=lessons`);
        expect(screen.getByTestId('teacher-lessons')).toBeInTheDocument();
    });

    it('does not navigate when the active section control is selected again', async () => {
        const confirm = vi.spyOn(window, 'confirm');
        renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'still here' },
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: 'الموضوعات والمهارات',
            }),
        );

        expect(confirm).not.toHaveBeenCalled();
        expectLocation(`${scopedPath}?section=topics`);
        expect(screen.getByLabelText('Topic draft')).toHaveValue(
            'still here',
        );
    });

    it('retains route navigation protection outside section changes', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const { router } = renderWorkspace(`${scopedPath}?section=topics`);
        await resolveContext();
        fireEvent.change(screen.getByLabelText('Topic draft'), {
            target: { value: 'route draft' },
        });

        await act(async () => {
            await router.navigate('/teacher/workspace');
        });

        await waitFor(() => expect(confirm).toHaveBeenCalledTimes(1));
        expectLocation(`${scopedPath}?section=topics`);
        expect(screen.getByLabelText('Topic draft')).toHaveValue(
            'route draft',
        );
    });

    it('protects the beforeunload boundary while a section is dirty', async () => {
        renderWorkspace(`${scopedPath}?section=practice`);
        await resolveContext();
        fireEvent.change(screen.getByLabelText('Practice draft'), {
            target: { value: 'practice changes' },
        });
        const beforeUnload = new Event('beforeunload', {
            cancelable: true,
        });

        window.dispatchEvent(beforeUnload);

        expect(beforeUnload.defaultPrevented).toBe(true);
    });

    it('refreshes the authoritative version query from the active section', async () => {
        const { client } = renderWorkspace(`${scopedPath}?section=topics`);
        const invalidateQueries = vi.spyOn(client, 'invalidateQueries');
        await resolveContext();

        fireEvent.click(
            screen.getByRole('button', {
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

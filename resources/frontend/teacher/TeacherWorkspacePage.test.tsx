import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    MemoryRouter,
    Route,
    Routes,
    useLocation,
} from 'react-router-dom';
import {
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
            id: 'teacher-a',
            name: 'Teacher',
            email: 'teacher@example.com',
            role: 'teacher',
            status: 'active',
            learner_profile_id: null,
        },
    }),
}));

vi.mock('./TeacherContextSelector', () => ({
    TeacherContextSelector: (props: {
        authenticatedUserId: string;
        assignmentId: string | null;
        curriculumId: string | null;
        curriculumVersionId: string | null;
        onContextResolved: (context: {
            assignmentId: string;
            curriculumId: string;
            curriculumVersionId: string;
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
                    type="button"
                    onClick={() => {
                        props.onContextResolved({
                            assignmentId: 'assignment-a',
                            curriculumId: 'curriculum-a',
                            curriculumVersionId: 'version-a',
                        });
                    }}
                >
                    Resolve context
                </button>
                <button
                    type="button"
                    onClick={props.onContextUnavailable}
                >
                    Invalidate context
                </button>
            </div>
        );
    },
}));

function LocationProbe() {
    const location = useLocation();

    return (
        <output data-testid="location">
            {location.pathname}
        </output>
    );
}

function renderWorkspace(
    path: string,
) {
    selectorProps.mockClear();

    render(
        <MemoryRouter
            initialEntries={[path]}
        >
            <LocationProbe />
            <Routes>
                <Route
                    path="/teacher/workspace"
                    element={
                        <TeacherWorkspacePage />
                    }
                />
                <Route
                    path="/teacher/workspace/:assignmentId/curricula/:curriculumId/versions/:versionId"
                    element={
                        <TeacherWorkspacePage />
                    }
                />
            </Routes>
        </MemoryRouter>,
    );
}

describe('TeacherWorkspacePage', () => {
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
});

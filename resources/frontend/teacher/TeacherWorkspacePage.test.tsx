import {
    render,
    screen,
} from '@testing-library/react';
import {
    MemoryRouter,
    Route,
    Routes,
} from 'react-router-dom';
import {
    describe,
    expect,
    it,
} from 'vitest';

import {
    TeacherWorkspacePage,
} from './TeacherWorkspacePage';

function renderWorkspace(
    path: string,
) {
    render(
        <MemoryRouter
            initialEntries={[path]}
        >
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
    it('renders the Slice 1 context-selection foundation without an API request', () => {
        renderWorkspace('/teacher/workspace');

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'تأليف المحتوى',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /ستتوفر أداة الاختيار في الشريحة التالية/,
            ),
        ).toBeInTheDocument();
    });

    it('renders a scoped route as a placeholder without retrieving context', () => {
        renderWorkspace(
            '/teacher/workspace/assignment-a/curricula/curriculum-a/versions/version-a?section=lessons',
        );

        expect(
            screen.getByText(
                'سيُفتح قسم lessons عند اكتمال مساحة التأليف.',
            ),
        ).toBeInTheDocument();
    });
});
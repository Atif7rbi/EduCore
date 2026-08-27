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
    App,
    LearnerFoundationPage,
    LoginFoundationPage,
} from './App';

function renderRoute(
    path: string,
    element: React.ReactNode,
) {
    render(
        <MemoryRouter initialEntries={[path]}>
            <Routes>
                <Route element={<App />}>
                    <Route
                        path={path}
                        element={element}
                    />
                </Route>
            </Routes>
        </MemoryRouter>,
    );
}

describe('frontend foundation', () => {
    it('renders the learner shell in the RTL test environment', () => {
        renderRoute(
            '/app',
            <LearnerFoundationPage />,
        );

        expect(
            screen.getByRole('banner'),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('main'),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'مرحبًا بك في EduCore',
            }),
        ).toBeInTheDocument();

        expect(
            document.documentElement.lang,
        ).toBe('ar');

        expect(
            document.documentElement.dir,
        ).toBe('rtl');
    });

    it('renders accessible login foundation fields', () => {
        renderRoute(
            '/login',
            <LoginFoundationPage />,
        );

        expect(
            screen.getByRole('textbox', {
                name: 'البريد الإلكتروني',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByLabelText(
                'كلمة المرور',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('button', {
                name: 'تسجيل الدخول',
            }),
        ).toBeInTheDocument();
    });
});

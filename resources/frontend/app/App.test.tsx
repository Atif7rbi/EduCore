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
    AdminFoundationPage,
    App,
    ForbiddenFoundationPage,
    LearnerFoundationPage,
    NotFoundFoundationPage,
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

describe('frontend foundation pages', () => {
    it('renders the learner foundation in the RTL environment', () => {
        renderRoute(
            '/app',
            <LearnerFoundationPage />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'مرحبًا بك في EduCore',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: 'استكشاف المناهج',
            }),
        ).toHaveAttribute(
            'href',
            '/app/curriculum',
        );

        expect(
            screen.getByRole('link', {
                name: 'عرض التقدم والتحليلات',
            }),
        ).toHaveAttribute(
            'href',
            '/app/progress',
        );

        expect(
            document.documentElement.lang,
        ).toBe('ar');

        expect(
            document.documentElement.dir,
        ).toBe('rtl');
    });

    it('renders the admin foundation page', () => {
        renderRoute(
            '/admin',
            <AdminFoundationPage />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'إدارة EduCore',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: 'إدارة المناهج',
            }),
        ).toHaveAttribute(
            'href',
            '/admin/curricula',
        );

        expect(
            screen.getByRole('link', {
                name: 'فتح استوديو المحتوى',
            }),
        ).toHaveAttribute(
            'href',
            '/admin/content',
        );
    });

    it('renders the forbidden foundation page', () => {
        renderRoute(
            '/forbidden',
            <ForbiddenFoundationPage />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'غير مصرح لك بالوصول',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('link', {
                name: 'العودة إلى التطبيق',
            }),
        ).toHaveAttribute(
            'href',
            '/app',
        );
    });

    it('renders the not-found foundation page', () => {
        renderRoute(
            '/missing',
            <NotFoundFoundationPage />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'الصفحة غير موجودة',
            }),
        ).toBeInTheDocument();
    });

});

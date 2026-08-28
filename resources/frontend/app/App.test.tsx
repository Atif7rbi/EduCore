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
    ProductPlaceholderPage,
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
            screen.getByText(
                'مساحة المستخدم والتنقل الأساسي جاهزان.',
            ),
        ).toBeInTheDocument();

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
            screen.getByRole('heading', {
                level: 2,
                name: 'Admin Studio',
            }),
        ).toBeInTheDocument();
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

    it('renders a route-specific product placeholder', () => {
        renderRoute(
            '/app/practice',
            <ProductPlaceholderPage
                eyebrow="التدريب"
                title="الممارسة"
                description="مساحة الممارسة."
            />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'الممارسة',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('status'),
        ).toHaveTextContent(
            'سيتم تفعيل وظائفها في المرحلة المخصصة لها.',
        );
    });

});

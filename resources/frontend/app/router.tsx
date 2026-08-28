import {
    createBrowserRouter,
} from 'react-router-dom';

import {
    RequireAuth,
    RequireRole,
} from '../auth/RouteGuards';
import {
    LoginPage,
} from '../auth/LoginPage';

import {
    AdminFoundationPage,
    App,
    ForbiddenFoundationPage,
    LearnerFoundationPage,
    NotFoundFoundationPage,
} from './App';

export const router = createBrowserRouter([
    {
        element: <App />,
        children: [
            {
                path: '/login',
                element: <LoginPage />,
            },
            {
                path: '/forbidden',
                element: (
                    <RequireAuth>
                        <ForbiddenFoundationPage />
                    </RequireAuth>
                ),
            },
            {
                path: '/app',
                element: (
                    <RequireAuth>
                        <LearnerFoundationPage />
                    </RequireAuth>
                ),
            },
            {
                path: '/app/*',
                element: (
                    <RequireAuth>
                        <LearnerFoundationPage />
                    </RequireAuth>
                ),
            },
            {
                path: '/admin',
                element: (
                    <RequireRole
                        allowedRoles={[
                            'admin',
                        ]}
                    >
                        <AdminFoundationPage />
                    </RequireRole>
                ),
            },
            {
                path: '/admin/*',
                element: (
                    <RequireRole
                        allowedRoles={[
                            'admin',
                        ]}
                    >
                        <AdminFoundationPage />
                    </RequireRole>
                ),
            },
            {
                path: '*',
                element: <NotFoundFoundationPage />,
            },
        ],
    },
]);

import {
    createBrowserRouter,
} from 'react-router-dom';

import {
    AdminFoundationPage,
    App,
    LearnerFoundationPage,
    NotFoundFoundationPage,
} from './App';

import { LoginPage } from '../auth/LoginPage';

export const router = createBrowserRouter([
    {
        element: <App />,
        children: [
            {
                path: '/login',
                element: <LoginPage />,
            },
            {
                path: '/app',
                element: <LearnerFoundationPage />,
            },
            {
                path: '/app/*',
                element: <LearnerFoundationPage />,
            },
            {
                path: '/admin',
                element: <AdminFoundationPage />,
            },
            {
                path: '/admin/*',
                element: <AdminFoundationPage />,
            },
            {
                path: '*',
                element: <NotFoundFoundationPage />,
            },
        ],
    },
]);

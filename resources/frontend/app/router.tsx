import {
    createBrowserRouter,
} from 'react-router-dom';

import {
    AdminFoundationPage,
    App,
    LearnerFoundationPage,
    LoginFoundationPage,
    NotFoundFoundationPage,
} from './App';

export const router = createBrowserRouter([
    {
        element: <App />,
        children: [
            {
                path: '/login',
                element: <LoginFoundationPage />,
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

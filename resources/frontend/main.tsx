import {
    StrictMode,
} from 'react';
import {
    createRoot,
} from 'react-dom/client';
import {
    RouterProvider,
} from 'react-router-dom';

import '../css/app.css';

import {
    AppProviders,
} from './app/providers';
import {
    router,
} from './app/router';

const rootElement =
    document.getElementById('app');

if (!rootElement) {
    throw new Error(
        'EduCore frontend root element was not found.',
    );
}

createRoot(rootElement).render(
    <StrictMode>
        <AppProviders>
            <RouterProvider router={router} />
        </AppProviders>
    </StrictMode>,
);

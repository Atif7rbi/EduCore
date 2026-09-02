import {
    Navigate,
    createBrowserRouter,
} from 'react-router-dom';

import {
    LoginPage,
} from '../auth/LoginPage';
import {
    RequireAuth,
    RequireRole,
} from '../auth/RouteGuards';
import {
    CurriculumDiscoveryPage,
} from '../learner/CurriculumDiscoveryPage';
import {
    CurriculumVersionPage,
} from '../learner/CurriculumVersionPage';
import {
    LessonPage,
} from '../learner/LessonPage';
import {
    ExamsPage,
} from '../learner/ExamsPage';
import {
    ResultsPage,
} from '../learner/ResultsPage';
import {
    ProgressPage,
} from '../learner/ProgressPage';
import {
    AdminCurriculaPage,
} from '../admin/AdminCurriculaPage';
import {
    AdminContentPage,
} from '../admin/AdminContentPage';
import {
    PracticeActivityPage,
} from '../learner/PracticeActivityPage';
import {
    AttemptPage,
} from '../learner/AttemptPage';

import {
    AdminFoundationPage,
    AdminProductShell,
    App,
    ForbiddenFoundationPage,
    LearnerFoundationPage,
    LearnerProductShell,
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
                        <LearnerProductShell />
                    </RequireAuth>
                ),
                children: [
                    {
                        index: true,
                        element: (
                            <LearnerFoundationPage />
                        ),
                    },
                    {
                        path: 'curriculum',
                        element: (
                            <CurriculumDiscoveryPage />
                        ),
                    },
                    {
                        path: 'curriculum/:curriculumVersionId',
                        element: (
                            <CurriculumVersionPage />
                        ),
                    },
                    {
                        path: 'lessons/:lessonId',
                        element: (
                            <LessonPage />
                        ),
                    },
                    {
                        path: 'practice',
                        element: (
                            <Navigate
                                replace
                                to="/app/curriculum"
                            />
                        ),
                    },
                    {
                        path: 'practice/:practiceActivityId',
                        element: (
                            <PracticeActivityPage />
                        ),
                    },
                    {
                        path: 'attempts/:attemptId',
                        element: (
                            <AttemptPage />
                        ),
                    },
                    {
                        path: 'exams',
                        element: (
                            <ExamsPage />
                        ),
                    },
                    {
                        path: 'results',
                        element: (
                            <ResultsPage />
                        ),
                    },
                    {
                        path: 'progress',
                        element: (
                            <ProgressPage />
                        ),
                    },
                ],
            },
            {
                path: '/admin',
                element: (
                    <RequireRole
                        allowedRoles={[
                            'admin',
                        ]}
                    >
                        <AdminProductShell />
                    </RequireRole>
                ),
                children: [
                    {
                        index: true,
                        element: (
                            <AdminFoundationPage />
                        ),
                    },
                    {
                        path: 'curricula',
                        element: (
                            <AdminCurriculaPage />
                        ),
                    },
                    {
                        path: 'content',
                        element: (
                            <AdminContentPage />
                        ),
                    },
                    {
                        path: 'assessment-items',
                        element: (
                            <Navigate
                                replace
                                to="/admin/content"
                            />
                        ),
                    },
                    {
                        path: 'exam-templates',
                        element: (
                            <Navigate
                                replace
                                to="/admin/content"
                            />
                        ),
                    },
                ],
            },
            {
                path: '*',
                element: (
                    <NotFoundFoundationPage />
                ),
            },
        ],
    },
]);

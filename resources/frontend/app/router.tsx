import {
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
    ProductPlaceholderPage,
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
                            <ProductPlaceholderPage
                                eyebrow="التدريب"
                                title="الممارسة"
                                description="ابدأ الممارسة من أحد الدروس أو المناهج المتاحة."
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
                            <ProductPlaceholderPage
                                eyebrow="التقدم"
                                title="التقدم والتحليلات"
                                description="عرض التقدم والأدلة والتحليلات سيتم تفعيله في P5."
                            />
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
                            <ProductPlaceholderPage
                                eyebrow="Admin Studio"
                                title="بنك الأسئلة"
                                description="إدارة Assessment Items ومراجعاتها وتصنيفها سيتم تفعيلها في P4."
                            />
                        ),
                    },
                    {
                        path: 'exam-templates',
                        element: (
                            <ProductPlaceholderPage
                                eyebrow="Admin Studio"
                                title="قوالب الاختبارات"
                                description="إدارة Exam Templates ودورة إصدارها سيتم تفعيلها في P4."
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

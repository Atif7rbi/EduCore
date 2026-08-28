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
                            <ProductPlaceholderPage
                                eyebrow="التعلم"
                                title="المناهج"
                                description="استعراض المناهج والمحتوى التعليمي سيكون متاحًا ضمن تجربة المتعلم في P3."
                            />
                        ),
                    },
                    {
                        path: 'practice',
                        element: (
                            <ProductPlaceholderPage
                                eyebrow="التدريب"
                                title="الممارسة"
                                description="أنشطة الممارسة ومحاولاتها سيتم تفعيلها ضمن تجربة المتعلم في P3."
                            />
                        ),
                    },
                    {
                        path: 'exams',
                        element: (
                            <ProductPlaceholderPage
                                eyebrow="التقييم"
                                title="الاختبارات"
                                description="تجربة الاختبارات والمحاولات والنتائج سيتم تفعيلها ضمن P3."
                            />
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
                            <ProductPlaceholderPage
                                eyebrow="Admin Studio"
                                title="المناهج"
                                description="إدارة Subjects وCurriculum Versions سيتم تفعيلها في P4."
                            />
                        ),
                    },
                    {
                        path: 'content',
                        element: (
                            <ProductPlaceholderPage
                                eyebrow="Admin Studio"
                                title="المحتوى"
                                description="إدارة Topics وSkills وLessons سيتم تفعيلها في P4."
                            />
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

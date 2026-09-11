import {
    Link,
    Outlet,
} from 'react-router-dom';

import {
    Container,
    Feedback,
    Surface,
} from '../ui';

import {
    ProductShell,
    adminNavigation,
    learnerNavigation,
} from './ProductShell';

function FoundationShell() {
    return (
        <div className="product-shell">
            <Outlet />
        </div>
    );
}

interface FoundationPageProps {
    eyebrow: string;
    title: string;
    description: string;
    children?: React.ReactNode;
}

function FoundationPage({
    children,
    description,
    eyebrow,
    title,
}: FoundationPageProps) {
    return (
        <section
            className="foundation-page"
            aria-labelledby="foundation-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    {eyebrow}
                </p>

                <h1
                    className="foundation-page__title"
                    id="foundation-title"
                >
                    {title}
                </h1>

                <p className="foundation-page__description">
                    {description}
                </p>
            </div>

            {children}
        </section>
    );
}

export function App() {
    return <FoundationShell />;
}

export function LearnerProductShell() {
    return (
        <ProductShell
            areaLabel="مساحة التعلم"
            navigation={learnerNavigation}
        >
            <Outlet />
        </ProductShell>
    );
}

export function AdminProductShell() {
    return (
        <ProductShell
            areaLabel="الإدارة"
            navigation={adminNavigation}
        >
            <Outlet />
        </ProductShell>
    );
}

export function LearnerFoundationPage() {
    return (
        <FoundationPage
            eyebrow="مساحة المتعلم"
            title="مرحبًا بك في EduCore"
            description="ابدأ من المناهج المتاحة، ثم انتقل بين الدروس والممارسة والاختبارات وتابع نتائجك وتقدمك."
        >
            <div className="foundation-grid">
                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            ابدأ التعلم
                        </h2>

                        <p className="foundation-card__text">
                            استكشف المناهج والدروس المتاحة وابدأ رحلة التعلم من المحتوى المنشور.
                        </p>

                        <Link
                            className="foundation-link"
                            to="/app/curriculum"
                        >
                            استكشاف المناهج
                        </Link>
                    </div>
                </Surface>

                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            متابعتك
                        </h2>

                        <p className="foundation-card__text">
                            راجع نتائج محاولاتك وتقدم الدروس وتحليل المهارات من مساحة المتعلم.
                        </p>

                        <Link
                            className="foundation-link"
                            to="/app/progress"
                        >
                            عرض التقدم والتحليلات
                        </Link>
                    </div>
                </Surface>
            </div>
        </FoundationPage>
    );
}

export function AdminFoundationPage() {
    return (
        <FoundationPage
            eyebrow="إدارة المحتوى"
            title="إدارة EduCore"
            description="أدر بنية المناهج والمحتوى التعليمي وأدوات التأليف من مساحة الإدارة."
        >
            <div className="foundation-grid">
                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            المناهج والإصدارات
                        </h2>

                        <p className="foundation-card__text">
                            أنشئ المواد والمناهج والإصدارات وأدر دورة نشرها.
                        </p>

                        <Link
                            className="foundation-link"
                            to="/admin/curricula"
                        >
                            إدارة المناهج
                        </Link>
                    </div>
                </Surface>

                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            استوديو المحتوى
                        </h2>

                        <p className="foundation-card__text">
                            أدر الموضوعات والمهارات والدروس والأسئلة وأنشطة الممارسة وقوالب الاختبارات.
                        </p>

                        <Link
                            className="foundation-link"
                            to="/admin/content"
                        >
                            فتح استوديو المحتوى
                        </Link>
                    </div>
                </Surface>
            </div>
        </FoundationPage>
    );
}

export function NotFoundFoundationPage() {
    return (
        <Container className="standalone-page">
            <FoundationPage
                eyebrow="404"
                title="الصفحة غير موجودة"
                description="المسار المطلوب غير متاح داخل EduCore."
            >
                <Link
                    className="foundation-link"
                    to="/app"
                >
                    العودة إلى التطبيق
                </Link>
            </FoundationPage>
        </Container>
    );
}

export function ForbiddenFoundationPage() {
    return (
        <Container className="standalone-page">
            <FoundationPage
                eyebrow="403"
                title="غير مصرح لك بالوصول"
                description="لا يملك حسابك الصلاحية اللازمة للوصول إلى هذه الصفحة."
            >
                <Link
                    className="foundation-link"
                    to="/app"
                >
                    العودة إلى التطبيق
                </Link>
            </FoundationPage>
        </Container>
    );
}

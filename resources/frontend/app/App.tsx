import {
    Link,
    Outlet,
} from 'react-router-dom';

import {
    Button,
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
            description="هذه هي نقطة البداية لتجربة التعلم. سيتم تفعيل المحتوى والممارسة والاختبارات في المراحل القادمة."
        >
            <div className="foundation-grid">
                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            تجربة التعلم
                        </h2>

                        <p className="foundation-card__text">
                            المناهج والدروس والممارسة والاختبارات ستُبنى فوق هذا الهيكل.
                        </p>

                        <Button>
                            استكشاف المنصة
                        </Button>
                    </div>
                </Surface>

                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            حالة المنصة
                        </h2>

                        <Feedback tone="success">
                            مساحة المستخدم والتنقل الأساسي جاهزان.
                        </Feedback>
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
            description="مساحة الإدارة جاهزة لاستقبال أدوات إدارة المناهج والمحتوى في مرحلة P4."
        >
            <Surface className="foundation-card">
                <div className="foundation-stack">
                    <h2 className="foundation-card__title">
                        Admin Studio
                    </h2>

                    <p className="foundation-card__text">
                        سيتم بناء workflows الإدارة الفعلية في مرحلة P4.
                    </p>
                </div>
            </Surface>
        </FoundationPage>
    );
}

interface ProductPlaceholderPageProps {
    eyebrow: string;
    title: string;
    description: string;
}

export function ProductPlaceholderPage({
    description,
    eyebrow,
    title,
}: ProductPlaceholderPageProps) {
    return (
        <FoundationPage
            eyebrow={eyebrow}
            title={title}
            description={description}
        >
            <Surface className="foundation-card">
                <div className="foundation-stack">
                    <Feedback>
                        هذه المساحة جاهزة داخل هيكل المنتج، وسيتم تفعيل وظائفها في المرحلة المخصصة لها.
                    </Feedback>
                </div>
            </Surface>
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

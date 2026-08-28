import {
    Link,
    Outlet,
} from 'react-router-dom';

import {
    Button,
    Container,
    Feedback,
    Surface,
    TextField,
} from '../ui';

function FoundationShell() {
    return (
        <div className="product-shell">
            <header className="product-shell__header">
                <Container className="product-shell__header-inner">
                    <Link
                        className="product-shell__brand"
                        to="/app"
                    >
                        EduCore
                    </Link>

                    <span className="product-shell__stage">
                        Product Foundation
                    </span>
                </Container>
            </header>

            <main className="product-shell__main">
                <Container>
                    <Outlet />
                </Container>
            </main>
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

export function LoginFoundationPage() {
    return (
        <FoundationPage
            eyebrow="الوصول إلى المنصة"
            title="تسجيل الدخول"
            description="تم تجهيز البنية الأساسية لتجربة تسجيل الدخول، وسيتم ربط التدفق الكامل في المرحلة التالية."
        >
            <Surface
                className="foundation-card"
                elevated
            >
                <div className="foundation-stack">
                    <TextField
                        type="email"
                        label="البريد الإلكتروني"
                        placeholder="name@example.com"
                        autoComplete="email"
                    />

                    <TextField
                        type="password"
                        label="كلمة المرور"
                        autoComplete="current-password"
                    />

                    <Button>
                        تسجيل الدخول
                    </Button>
                </div>
            </Surface>
        </FoundationPage>
    );
}

export function LearnerFoundationPage() {
    return (
        <FoundationPage
            eyebrow="مساحة المتعلم"
            title="مرحبًا بك في EduCore"
            description="تم تأسيس الهيكل العام لتجربة المتعلم باللغة العربية وباتجاه RTL."
        >
            <div className="foundation-grid">
                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            تجربة التعلم
                        </h2>

                        <p className="foundation-card__text">
                            ستظهر هنا الدروس والممارسة والاختبارات ضمن المراحل القادمة.
                        </p>

                        <Button>
                            استكشاف المنصة
                        </Button>
                    </div>
                </Surface>

                <Surface className="foundation-card">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            حالة الواجهة
                        </h2>

                        <Feedback tone="success">
                            Design System وRTL foundation جاهزان.
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
            description="تم تأسيس shell الإدارة فقط، بدون تفعيل أي workflow إداري في هذه المرحلة."
        >
            <Surface className="foundation-card">
                <div className="foundation-stack">
                    <h2 className="foundation-card__title">
                        Admin Studio
                    </h2>

                    <p className="foundation-card__text">
                        سيتم بناء أدوات إدارة المناهج والمحتوى في مرحلة P4.
                    </p>

                    <Button variant="secondary">
                        معاينة الأساس
                    </Button>
                </div>
            </Surface>
        </FoundationPage>
    );
}

export function NotFoundFoundationPage() {
    return (
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
    );
}

export function ForbiddenFoundationPage() {
    return (
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
    );
}

import {
    Link,
    Outlet,
} from 'react-router-dom';

function FoundationShell() {
    return (
        <main>
            <Outlet />
        </main>
    );
}

function FoundationPage({
    title,
}: {
    title: string;
}) {
    return (
        <section aria-labelledby="foundation-title">
            <h1 id="foundation-title">
                {title}
            </h1>

            <p>
                تم تأسيس طبقة الواجهة الأمامية لـ EduCore.
            </p>

            <Link to="/app">
                الانتقال إلى التطبيق
            </Link>
        </section>
    );
}

export function App() {
    return <FoundationShell />;
}

export function LoginFoundationPage() {
    return (
        <FoundationPage title="تسجيل الدخول" />
    );
}

export function LearnerFoundationPage() {
    return (
        <FoundationPage title="EduCore" />
    );
}

export function AdminFoundationPage() {
    return (
        <FoundationPage title="إدارة EduCore" />
    );
}

export function NotFoundFoundationPage() {
    return (
        <FoundationPage title="الصفحة غير موجودة" />
    );
}

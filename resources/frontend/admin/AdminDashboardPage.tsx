import {
    Link,
} from 'react-router-dom';
import {
    useQuery,
} from '@tanstack/react-query';

import {
    EduCoreApiError,
} from '../api/errors';
import {
    Feedback,
    Surface,
} from '../ui';

import {
    adminDashboardKey,
    fetchAdminDashboard,
} from './dashboard/api';

function requestId(error: unknown) {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function formatCount(value: number): string {
    return value.toLocaleString('en-US');
}

function DashboardIcon({ name }: { name: string }) {
    const paths: Record<string, React.ReactNode> = {
        subjects: <path d="M5 4.75h11a2 2 0 0 1 2 2v11H7a2 2 0 0 0-2 2V4.75Zm0 0a2 2 0 0 0-2 2v11a2 2 0 0 1 2-2h13" />,
        curricula: <path d="M4 5.5h16M6.5 3v5M17.5 3v5M6 11h5M6 15h8M6 19h6" />,
        topics: <path d="M4 6h6l2 2h8v10H4V6Z" />,
        lessons: <><path d="M5 4.5h6a3 3 0 0 1 3 3v12H8a3 3 0 0 0-3 3v-18Z" /><path d="M19 4.5h-2.5A2.5 2.5 0 0 0 14 7v12h5V4.5Z" /></>,
        exams: <><path d="M7 3.5h8l4 4v13H7v-17Z" /><path d="M15 3.5v4h4M10 12h6M10 16h6" /></>,
        learners: <><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" /><path d="M5 21a7 7 0 0 1 14 0" /></>,
        skills: <><circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="3" /><path d="M12 2v3M22 12h-3M12 22v-3M2 12h3" /></>,
        questions: <><rect x="4" y="4" width="16" height="16" rx="3" /><path d="M9.5 10a2.5 2.5 0 1 1 4.7 1.2c-.9 1.3-2.2 1.5-2.2 3M12 17.2v.1" /></>,
        practice: <><path d="M5 6h3M5 12h3M5 18h3M11 6h8M11 12h8M11 18h8" /></>,
    };

    return (
        <svg
            aria-hidden="true"
            className="admin-dashboard-card__icon-svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            {paths[name] ?? paths.lessons}
        </svg>
    );
}

interface StatCardProps {
    icon: string;
    label: string;
    value: number;
    to?: string;
    hint?: string;
}

function StatCard({
    hint,
    icon,
    label,
    to,
    value,
}: StatCardProps) {
    const content = (
        <>
            <div className="admin-dashboard-card__topline">
                <span className="admin-dashboard-card__icon">
                    <DashboardIcon name={icon} />
                </span>
                {to ? <span className="admin-dashboard-card__arrow" aria-hidden="true">↗</span> : null}
            </div>
            <strong className="admin-dashboard-card__value">{formatCount(value)}</strong>
            <span className="admin-dashboard-card__label">{label}</span>
            {hint ? <span className="admin-dashboard-card__hint">{hint}</span> : null}
        </>
    );

    if (to) {
        return (
            <Link className="admin-dashboard-card admin-dashboard-card--link" to={to}>
                {content}
            </Link>
        );
    }

    return <div className="admin-dashboard-card">{content}</div>;
}

export function AdminDashboardPage() {
    const dashboardQuery = useQuery({
        queryKey: adminDashboardKey(),
        queryFn: fetchAdminDashboard,
    });

    const data = dashboardQuery.data;
    const id = requestId(dashboardQuery.error);

    return (
        <section className="admin-dashboard" aria-labelledby="admin-dashboard-title">
            <header className="admin-dashboard__hero">
                <div>
                    <p className="admin-dashboard__eyebrow">لوحة الإدارة</p>
                    <h1 id="admin-dashboard-title">نظرة عامة</h1>
                    <p>
                        تابع حجم المحتوى التعليمي وجاهزية النشر، وانتقل بسرعة إلى أهم أدوات الإدارة.
                    </p>
                </div>
                <div className="admin-dashboard__hero-actions">
                    <Link className="admin-dashboard__primary-action" to="/admin/content">
                        فتح إدارة المحتوى
                    </Link>
                    <Link className="admin-dashboard__secondary-action" to="/admin/curricula">
                        إدارة المناهج
                    </Link>
                </div>
            </header>

            {dashboardQuery.isPending ? (
                <div className="admin-dashboard__loading" aria-live="polite">
                    <span className="admin-dashboard__loading-dot" />
                    جار تحميل لوحة الإدارة…
                </div>
            ) : dashboardQuery.isError ? (
                <Feedback tone="danger">
                    <strong>تعذر تحميل بيانات لوحة الإدارة.</strong>
                    {id ? <p className="learner-read-request-id">رقم الطلب: {id}</p> : null}
                </Feedback>
            ) : data ? (
                <>
                    <section className="admin-dashboard__section" aria-labelledby="dashboard-key-numbers">
                        <div className="admin-dashboard__section-heading">
                            <div>
                                <h2 id="dashboard-key-numbers">المؤشرات الرئيسية</h2>
                                <p>ملخص مباشر لحجم المنصة والمحتوى الموجود حاليًا.</p>
                            </div>
                            <span className="admin-dashboard__live-badge">بيانات مباشرة</span>
                        </div>

                        <div className="admin-dashboard__stats-grid">
                            <StatCard icon="learners" label="الطلاب" value={data.counts.learners} />
                            <StatCard icon="lessons" label="الدروس" value={data.counts.lessons} to="/admin/content" />
                            <StatCard icon="topics" label="الوحدات" value={data.counts.topics} to="/admin/content" />
                            <StatCard icon="exams" label="الاختبارات" value={data.counts.exam_templates} to="/admin/content" />
                            <StatCard icon="curricula" label="المناهج" value={data.counts.curricula} to="/admin/curricula" />
                            <StatCard icon="subjects" label="المواد" value={data.counts.subjects} to="/admin/curricula" />
                        </div>
                    </section>

                    <div className="admin-dashboard__lower-grid">
                        <Surface className="admin-dashboard__readiness">
                            <div className="admin-dashboard__section-heading admin-dashboard__section-heading--compact">
                                <div>
                                    <h2>جاهزية المحتوى</h2>
                                    <p>مؤشرات المحتوى المتاح أو المعتمد للاستخدام.</p>
                                </div>
                            </div>

                            <div className="admin-dashboard__readiness-grid">
                                <div>
                                    <span>الدروس المنشورة</span>
                                    <strong>{formatCount(data.readiness.published_lessons)}</strong>
                                </div>
                                <div>
                                    <span>المناهج المنشورة</span>
                                    <strong>{formatCount(data.readiness.published_curriculum_versions)}</strong>
                                </div>
                                <div>
                                    <span>التدريبات النشطة</span>
                                    <strong>{formatCount(data.readiness.active_practice_activities)}</strong>
                                </div>
                                <div>
                                    <span>الاختبارات النشطة</span>
                                    <strong>{formatCount(data.readiness.active_exam_templates)}</strong>
                                </div>
                            </div>
                        </Surface>

                        <Surface className="admin-dashboard__inventory">
                            <div className="admin-dashboard__section-heading admin-dashboard__section-heading--compact">
                                <div>
                                    <h2>مخزون التأليف</h2>
                                    <p>العناصر التي يعتمد عليها فريق المحتوى أثناء البناء.</p>
                                </div>
                            </div>

                            <div className="admin-dashboard__inventory-list">
                                <div><span><DashboardIcon name="skills" /> المهارات</span><strong>{formatCount(data.counts.skills)}</strong></div>
                                <div><span><DashboardIcon name="questions" /> بنك الأسئلة</span><strong>{formatCount(data.counts.assessment_items)}</strong></div>
                                <div><span><DashboardIcon name="practice" /> التدريبات</span><strong>{formatCount(data.counts.practice_activities)}</strong></div>
                                <div><span><DashboardIcon name="curricula" /> إصدارات المناهج</span><strong>{formatCount(data.counts.curriculum_versions)}</strong></div>
                            </div>
                        </Surface>
                    </div>

                    <section className="admin-dashboard__section" aria-labelledby="dashboard-actions">
                        <div className="admin-dashboard__section-heading">
                            <div>
                                <h2 id="dashboard-actions">إجراءات سريعة</h2>
                                <p>اختصارات للمهام الأكثر استخدامًا في الإدارة اليومية.</p>
                            </div>
                        </div>

                        <div className="admin-dashboard__quick-actions">
                            <Link to="/admin/curricula">
                                <span><DashboardIcon name="curricula" /></span>
                                <strong>إدارة المناهج</strong>
                                <small>المواد، المناهج، والإصدارات</small>
                            </Link>
                            <Link to="/admin/content">
                                <span><DashboardIcon name="lessons" /></span>
                                <strong>إدارة المحتوى</strong>
                                <small>الوحدات، الدروس، والمهارات</small>
                            </Link>
                            <Link to="/admin/content">
                                <span><DashboardIcon name="questions" /></span>
                                <strong>بنك الأسئلة</strong>
                                <small>بناء وتصنيف أسئلة التقييم</small>
                            </Link>
                            <Link to="/admin/content">
                                <span><DashboardIcon name="exams" /></span>
                                <strong>التدريبات والاختبارات</strong>
                                <small>إدارة الأنشطة وقوالب الاختبارات</small>
                            </Link>
                        </div>
                    </section>
                </>
            ) : null}
        </section>
    );
}

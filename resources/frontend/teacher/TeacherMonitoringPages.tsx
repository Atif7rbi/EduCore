import '../../css/teacher-monitoring.css';

import {
    type KeyboardEvent,
    useEffect,
    useRef,
    useState,
} from 'react';
import {
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';
import {
    Link,
    useParams,
    useSearchParams,
} from 'react-router-dom';

import {
    useAuth,
} from '../auth/AuthProvider';
import {
    Button,
    Feedback,
    Surface,
} from '../ui';
import {
    fetchTeacherAssignments,
    fetchTeacherCurricula,
    fetchTeacherCurriculumVersions,
    teacherAssignmentsKey,
    teacherCurriculaKey,
    teacherCurriculumVersionsKey,
} from './api';
import {
    fetchTeacherEnrollments,
    teacherEnrollmentsKey,
} from './enrollmentApi';
import type {
    TeacherEnrollmentStatus,
} from './enrollmentApi';
import {
    fetchTeacherAttemptResult,
    fetchTeacherAttempts,
    fetchTeacherLessonProgress,
    resolveTeacherMonitoringEnrollment,
    teacherAttemptResultKey,
    teacherAttemptsKey,
    teacherLessonProgressKey,
    teacherMonitoringEnrollmentContextKey,
    teacherMonitoringRootKey,
} from './monitoringApi';

import type {
    TeacherMonitoringScopeSummary,
    TeacherMonitoredAttempt,
    TeacherAttemptKind,
    TeacherAttemptStatus,
} from './monitoringApi';

type MonitoringTab =
    | 'progress'
    | 'attempts'
    | 'results';

const monitoringTabs = [
    ['progress', 'التقدم'],
    ['attempts', 'المحاولات'],
    ['results', 'النتائج'],
] as const satisfies ReadonlyArray<readonly [MonitoringTab, string]>;

const enrollmentStatusLabels: Record<TeacherEnrollmentStatus, string> = {
    active: 'نشط',
    pending: 'قيد الانتظار',
};

function enrollmentStatusLabel(status: string): string {
    return enrollmentStatusLabels[
        status as TeacherEnrollmentStatus
    ] ?? 'حالة غير معروفة';
}

function positivePage(value: string | null): number {
    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed > 0
        ? parsed
        : 1;
}

function dateTimeLabel(value: string | null): string {
    if (!value) {
        return 'غير متاح';
    }

    return new Intl.DateTimeFormat(
        'ar-SA-u-ca-gregory',
        {
            dateStyle: 'medium',
            timeStyle: 'short',
        },
    ).format(new Date(value));
}

function setParam(
    current: URLSearchParams,
    name: string,
    value: string | null,
): URLSearchParams {
    const next = new URLSearchParams(current);

    if (value) {
        next.set(name, value);
    } else {
        next.delete(name);
    }

    return next;
}

export function TeacherMonitoringOverviewPage() {
    const { user } = useAuth();
    const [searchParams, setSearchParams] = useSearchParams();
    const assignmentId = searchParams.get('assignment_id') || null;
    const page = positivePage(searchParams.get('page'));
    const assignments = useQuery({
        queryKey: teacherAssignmentsKey(user?.id ?? 'unauthenticated'),
        queryFn: ({ signal }) => fetchTeacherAssignments(signal),
        enabled: user?.role === 'teacher',
    });
    const enrollments = useQuery({
        queryKey: teacherEnrollmentsKey(
            user?.id ?? 'unauthenticated',
            'active',
            page,
            assignmentId,
        ),
        queryFn: ({ signal }) => fetchTeacherEnrollments(
            'active',
            page,
            signal,
            assignmentId,
        ),
        enabled: user?.role === 'teacher',
    });

    useEffect(() => {
        const lastPage = enrollments.data?.pagination.last_page;

        if (lastPage !== undefined && page > Math.max(1, lastPage)) {
            const next = new URLSearchParams(searchParams);
            next.set('page', String(Math.max(1, lastPage)));
            setSearchParams(next, { replace: true });
        }
    }, [enrollments.data, page, searchParams, setSearchParams]);

    function changeAssignment(nextAssignmentId: string) {
        const next = setParam(
            searchParams,
            'assignment_id',
            nextAssignmentId || null,
        );
        next.set('page', '1');
        setSearchParams(next);
    }

    function changePage(nextPage: number) {
        const next = new URLSearchParams(searchParams);
        next.set('page', String(nextPage));
        setSearchParams(next);
    }

    const pagination = enrollments.data?.pagination;

    return (
        <section className="teacher-monitoring-page" aria-labelledby="teacher-monitoring-title">
            <header className="teacher-monitoring-page__heading">
                <p>مساحة المعلم</p>
                <h1 id="teacher-monitoring-title">متابعة الطلاب</h1>
                <p>اختر طالبًا ملتحقًا بتكليف نشط لمراجعة تقدمه ومحاولاته ونتائجه المسجلة.</p>
            </header>

            <Surface className="teacher-monitoring-filter-card">
                <label htmlFor="monitoring-assignment">التكليف والمادة</label>
                <select
                    id="monitoring-assignment"
                    value={assignmentId ?? ''}
                    disabled={assignments.isPending}
                    onChange={(event) => changeAssignment(event.target.value)}
                >
                    <option value="">كل المواد</option>
                    {assignments.data
                        ?.filter((item) => item.status === 'active' && item.subject.status === 'active')
                        .map((assignment) => (
                            <option key={assignment.id} value={assignment.id}>
                                {assignment.subject.name}
                            </option>
                        ))}
                </select>
            </Surface>

            {assignments.isError || enrollments.isError ? (
                <Feedback tone="danger">
                    تعذر تحميل الطلاب المخولين. أعد المحاولة.
                    <Button variant="secondary" size="sm" onClick={() => void Promise.all([
                        assignments.refetch(),
                        enrollments.refetch(),
                    ])}>
                        إعادة المحاولة
                    </Button>
                </Feedback>
            ) : null}

            {enrollments.isPending ? (
                <Feedback>جارٍ تحميل الطلاب...</Feedback>
            ) : null}

            {enrollments.isSuccess && enrollments.data.items.length === 0 ? (
                <Feedback>لا يوجد طلاب نشطون ضمن هذا النطاق.</Feedback>
            ) : null}

            {enrollments.isSuccess && enrollments.data.items.length > 0 ? (
                <div className="teacher-monitoring-students" aria-label="الطلاب النشطون">
                    {enrollments.data.items.map((enrollment) => (
                        <article className="teacher-monitoring-student-card" key={enrollment.id}>
                            <div>
                                <h2>{enrollment.student.name}</h2>
                                <p dir="auto">{enrollment.teacher_subject_assignment.subject.name}</p>
                                <p className="teacher-monitoring-enrollment-status">
                                    حالة الالتحاق: {enrollmentStatusLabel(enrollment.status)}
                                </p>
                            </div>
                            <Link
                                className="teacher-monitoring-link"
                                to={
                                    '/teacher/monitoring/enrollments/'
                                    + enrollment.id
                                    + '?assignment_id='
                                    + enrollment.teacher_subject_assignment.id
                                }
                            >
                                فتح المتابعة
                            </Link>
                        </article>
                    ))}
                </div>
            ) : null}

            {pagination ? (
                <nav className="teacher-monitoring-pagination" aria-label="صفحات الطلاب">
                    <Button
                        variant="secondary"
                        disabled={pagination.current_page <= 1 || enrollments.isFetching}
                        onClick={() => changePage(pagination.current_page - 1)}
                    >
                        السابق
                    </Button>
                    <span>صفحة {pagination.current_page} من {Math.max(1, pagination.last_page)}</span>
                    <Button
                        variant="secondary"
                        disabled={pagination.current_page >= pagination.last_page || enrollments.isFetching}
                        onClick={() => changePage(pagination.current_page + 1)}
                    >
                        التالي
                    </Button>
                </nav>
            ) : null}
        </section>
    );
}

function ScopeHeading({ scope }: { scope: TeacherMonitoringScopeSummary | null }) {
    if (!scope) {
        return null;
    }

    return (
        <Surface className="teacher-monitoring-scope">
            <div>
                <span>الطالب</span>
                <strong>{scope.enrollment.student.name}</strong>
            </div>
            <div>
                <span>المادة</span>
                <strong dir="auto">{scope.teacher_subject_assignment.subject.name}</strong>
            </div>
            <div>
                <span>المنهج</span>
                <strong dir="auto">{scope.curriculum.name}</strong>
            </div>
            <div>
                <span>الإصدار</span>
                <strong dir="auto">{scope.curriculum_version.label}</strong>
            </div>
        </Surface>
    );
}

function EnrollmentIdentity({
    enrollment,
}: {
    enrollment: Awaited<ReturnType<typeof resolveTeacherMonitoringEnrollment>>;
}) {
    if (!enrollment) {
        return null;
    }

    return (
        <Surface className="teacher-monitoring-enrollment-identity">
            <div>
                <span>الطالب</span>
                <strong>{enrollment.student.name}</strong>
            </div>
            <div>
                <span>المادة</span>
                <strong dir="auto">{enrollment.teacher_subject_assignment.subject.name}</strong>
            </div>
            <div>
                <span>حالة الالتحاق</span>
                <strong>{enrollmentStatusLabel(enrollment.status)}</strong>
            </div>
        </Surface>
    );
}

function ProgressPanel({
    assignmentId,
    enrollmentId,
    userId,
    versionId,
}: {
    assignmentId: string;
    enrollmentId: string;
    userId: string;
    versionId: string;
}) {
    const progress = useQuery({
        queryKey: teacherLessonProgressKey(userId, enrollmentId, assignmentId, versionId),
        queryFn: ({ signal }) => fetchTeacherLessonProgress(enrollmentId, versionId, signal),
    });

    if (progress.isPending) {
        return <Feedback>جارٍ تحميل تقدم الدروس...</Feedback>;
    }

    if (progress.isError) {
        return (
            <Feedback tone="danger">
                تعذر تحميل تقدم الدروس.
                <Button variant="secondary" size="sm" onClick={() => void progress.refetch()}>
                    إعادة المحاولة
                </Button>
            </Feedback>
        );
    }

    const labels = {
        completed: 'مكتمل',
        in_progress: 'قيد التقدم',
        not_recorded: 'غير مسجل',
    } as const;

    return (
        <div className="teacher-monitoring-panel">
            <ScopeHeading scope={progress.data} />
            <div className="teacher-monitoring-summary" aria-label="ملخص تقدم الدروس">
                <span>بدأ: {progress.data.recorded_started_count}</span>
                <span>أكمل: {progress.data.recorded_completed_count}</span>
            </div>
            {progress.data.items.length === 0 ? (
                <Feedback>لا توجد دروس منشورة مؤهلة في هذا الإصدار.</Feedback>
            ) : (
                <div className="teacher-monitoring-records">
                    {progress.data.items.map((item) => (
                        <article key={item.lesson_revision.id} className="teacher-monitoring-record">
                            <div>
                                <h3>{item.lesson.title}</h3>
                                <p>المراجعة {item.lesson_revision.revision_number}</p>
                            </div>
                            <span className={'teacher-monitoring-status teacher-monitoring-status--' + item.progress.status}>
                                {labels[item.progress.status]}
                            </span>
                            <dl>
                                <div><dt>بدأ</dt><dd>{dateTimeLabel(item.progress.started_at)}</dd></div>
                                <div><dt>اكتمل</dt><dd>{dateTimeLabel(item.progress.completed_at)}</dd></div>
                            </dl>
                        </article>
                    ))}
                </div>
            )}
        </div>
    );
}

function ResultSummary({ attempt }: { attempt: TeacherMonitoredAttempt }) {
    if (attempt.result.availability !== 'available') {
        return <p className="teacher-monitoring-muted">لا تتوفر نتيجة للمحاولة المتروكة.</p>;
    }

    const summary = attempt.result.summary;

    return (
        <dl className="teacher-monitoring-result-summary">
            <div><dt>المجموع</dt><dd>{summary.total}</dd></div>
            <div><dt>مجاب</dt><dd>{summary.answered}</dd></div>
            <div><dt>صحيح</dt><dd>{summary.correct}</dd></div>
            <div><dt>غير صحيح</dt><dd>{summary.incorrect}</dd></div>
            <div><dt>دون إجابة</dt><dd>{summary.unanswered}</dd></div>
        </dl>
    );
}

function AttemptsPanel({
    assignmentId,
    enrollmentId,
    mode,
    searchParams,
    setSearchParams,
    userId,
    versionId,
}: {
    assignmentId: string;
    enrollmentId: string;
    mode: 'attempts' | 'results';
    searchParams: URLSearchParams;
    setSearchParams: ReturnType<typeof useSearchParams>[1];
    userId: string;
    versionId: string;
}) {
    const kindValue = searchParams.get('kind');
    const statusValue = searchParams.get('status');
    const kind: TeacherAttemptKind | null = kindValue === 'practice' || kindValue === 'exam' ? kindValue : null;
    const status: TeacherAttemptStatus | null = statusValue === 'submitted' || statusValue === 'abandoned' ? statusValue : null;
    const page = positivePage(searchParams.get('attempt_page'));
    const contextIdentity = [userId, enrollmentId, assignmentId, versionId, mode, kind, status, page].join(':');
    const [selectedAttempt, setSelectedAttempt] = useState<{
        contextIdentity: string;
        id: string;
    } | null>(null);
    const selectedAttemptId =
        selectedAttempt?.contextIdentity === contextIdentity
            ? selectedAttempt.id
            : null;

    const attempts = useQuery({
        queryKey: teacherAttemptsKey(userId, enrollmentId, assignmentId, versionId, kind, status, page),
        queryFn: ({ signal }) => fetchTeacherAttempts(enrollmentId, versionId, kind, status, page, signal),
    });
    const result = useQuery({
        queryKey: teacherAttemptResultKey(userId, enrollmentId, assignmentId, versionId, selectedAttemptId ?? 'none'),
        queryFn: ({ signal }) => fetchTeacherAttemptResult(enrollmentId, selectedAttemptId!, signal),
        enabled: mode === 'results' && selectedAttemptId !== null,
    });

    useEffect(() => {
        setSelectedAttempt(null);
    }, [contextIdentity]);

    useEffect(() => {
        const lastPage = attempts.data?.pagination.last_page;

        if (lastPage !== undefined && page > Math.max(1, lastPage)) {
            const next = new URLSearchParams(searchParams);
            next.set('attempt_page', String(Math.max(1, lastPage)));
            setSearchParams(next, { replace: true });
        }
    }, [attempts.data, page, searchParams, setSearchParams]);

    function updateFilter(name: string, value: string) {
        const next = setParam(searchParams, name, value || null);
        next.set('attempt_page', '1');
        setSearchParams(next);
    }

    function updatePage(nextPage: number) {
        const next = new URLSearchParams(searchParams);
        next.set('attempt_page', String(nextPage));
        setSearchParams(next);
    }

    if (attempts.isPending) {
        return <Feedback>جارٍ تحميل المحاولات النهائية...</Feedback>;
    }

    if (attempts.isError) {
        return (
            <Feedback tone="danger">
                تعذر تحميل المحاولات.
                <Button variant="secondary" size="sm" onClick={() => void attempts.refetch()}>
                    إعادة المحاولة
                </Button>
            </Feedback>
        );
    }

    const pagination = attempts.data.pagination;

    return (
        <div className="teacher-monitoring-panel">
            <ScopeHeading scope={attempts.data} />
            <div className="teacher-monitoring-attempt-filters" aria-label="مرشحات المحاولات">
                <label>النوع
                    <select value={kind ?? ''} onChange={(event) => updateFilter('kind', event.target.value)}>
                        <option value="">الكل</option>
                        <option value="practice">ممارسة</option>
                        <option value="exam">اختبار</option>
                    </select>
                </label>
                <label>الحالة
                    <select value={status ?? ''} onChange={(event) => updateFilter('status', event.target.value)}>
                        <option value="">الكل</option>
                        <option value="submitted">مسلّمة</option>
                        <option value="abandoned">متروكة</option>
                    </select>
                </label>
            </div>

            {attempts.data.items.length === 0 ? (
                <Feedback>لا توجد محاولات نهائية مطابقة.</Feedback>
            ) : (
                <div className="teacher-monitoring-records">
                    {attempts.data.items.map((attempt) => (
                        <article className="teacher-monitoring-record" key={attempt.id}>
                            <div>
                                <h3>{attempt.kind === 'practice' ? 'محاولة ممارسة' : 'محاولة اختبار'}</h3>
                                <p>{attempt.status === 'submitted' ? 'مسلّمة' : 'متروكة'} — {dateTimeLabel(attempt.finalized_at)}</p>
                            </div>
                            {mode === 'attempts' ? <ResultSummary attempt={attempt} /> : null}
                            {mode === 'results' && attempt.status === 'submitted' ? (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => setSelectedAttempt({
                                        contextIdentity,
                                        id: attempt.id,
                                    })}
                                >
                                    عرض ملخص النتيجة
                                </Button>
                            ) : null}
                            {mode === 'results' && attempt.status === 'abandoned' ? (
                                <p className="teacher-monitoring-muted">لا تنطبق نتيجة على المحاولة المتروكة.</p>
                            ) : null}
                        </article>
                    ))}
                </div>
            )}

            <nav className="teacher-monitoring-pagination" aria-label="صفحات المحاولات">
                <Button
                    variant="secondary"
                    disabled={pagination.current_page <= 1 || attempts.isFetching}
                    onClick={() => updatePage(pagination.current_page - 1)}
                >السابق</Button>
                <span>صفحة {pagination.current_page} من {Math.max(1, pagination.last_page)}</span>
                <Button
                    variant="secondary"
                    disabled={pagination.current_page >= pagination.last_page || attempts.isFetching}
                    onClick={() => updatePage(pagination.current_page + 1)}
                >التالي</Button>
            </nav>

            {mode === 'results' && selectedAttemptId !== null ? (
                <Surface className="teacher-monitoring-result-detail" aria-live="polite">
                    {result.isPending ? <Feedback>جارٍ تحميل ملخص النتيجة...</Feedback> : null}
                    {result.isError ? (
                        <Feedback tone="danger">
                            تعذر تحميل ملخص النتيجة.
                            <Button variant="secondary" size="sm" onClick={() => void result.refetch()}>
                                إعادة المحاولة
                            </Button>
                        </Feedback>
                    ) : null}
                    {result.isSuccess ? (
                        <>
                            <h3>ملخص النتيجة المعتمد</h3>
                            <ResultSummary attempt={result.data.attempt} />
                        </>
                    ) : null}
                </Surface>
            ) : null}
        </div>
    );
}

export function TeacherMonitoringDetailPage() {
    const { user } = useAuth();
    const { enrollmentId = '' } = useParams();
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] = useSearchParams();
    const assignmentHint = searchParams.get('assignment_id') || '';
    const curriculumId = searchParams.get('curriculum_id') || '';
    const versionId = searchParams.get('version_id') || '';
    const tabValue = searchParams.get('tab');
    const tab: MonitoringTab = tabValue === 'attempts' || tabValue === 'results' ? tabValue : 'progress';
    const tabRefs = useRef<Record<MonitoringTab, HTMLButtonElement | null>>({
        progress: null,
        attempts: null,
        results: null,
    });

    const enrollmentContext = useQuery({
        queryKey: teacherMonitoringEnrollmentContextKey(
            user?.id ?? 'unauthenticated',
            enrollmentId,
        ),
        queryFn: ({ signal }) => resolveTeacherMonitoringEnrollment(
            enrollmentId,
            signal,
        ),
        enabled: user?.role === 'teacher' && enrollmentId !== '',
    });
    const assignmentId = enrollmentContext.data
        ?.teacher_subject_assignment.id ?? '';

    const curricula = useQuery({
        queryKey: teacherCurriculaKey(user?.id ?? 'unauthenticated', assignmentId),
        queryFn: ({ signal }) => fetchTeacherCurricula(assignmentId, signal),
        enabled: user?.role === 'teacher' && assignmentId !== '',
    });
    const selectedCurriculum = curricula.data?.find((item) => item.id === curriculumId && item.teacher_subject_assignment_id === assignmentId) ?? null;
    const versions = useQuery({
        queryKey: teacherCurriculumVersionsKey(user?.id ?? 'unauthenticated', assignmentId, curriculumId),
        queryFn: ({ signal }) => fetchTeacherCurriculumVersions(assignmentId, curriculumId, signal),
        enabled: selectedCurriculum !== null,
    });
    const visibleVersions = versions.data?.filter((item) => item.status === 'published' || item.status === 'retired') ?? [];
    const selectedVersion = visibleVersions.find((item) => item.id === versionId && item.curriculum_id === curriculumId) ?? null;

    useEffect(() => {
        if (!assignmentId || assignmentHint === assignmentId) {
            return;
        }

        const next = new URLSearchParams(searchParams);
        next.set('assignment_id', assignmentId);
        setSearchParams(next, { replace: true });
    }, [
        assignmentHint,
        assignmentId,
        searchParams,
        setSearchParams,
    ]);

    function replaceContext(updates: Record<string, string | null>) {
        void queryClient.cancelQueries({
            queryKey: teacherMonitoringRootKey(
                user?.id ?? 'unauthenticated',
            ),
        });
        const next = new URLSearchParams(searchParams);
        Object.entries(updates).forEach(([name, value]) => {
            if (value) next.set(name, value); else next.delete(name);
        });
        setSearchParams(next);
    }

    function selectTab(nextTab: MonitoringTab, moveFocus = false) {
        replaceContext({ tab: nextTab });

        if (moveFocus) {
            tabRefs.current[nextTab]?.focus();
        }
    }

    function handleTabKeyDown(
        event: KeyboardEvent<HTMLButtonElement>,
        currentTab: MonitoringTab,
    ) {
        const currentIndex = monitoringTabs.findIndex(([value]) => value === currentTab);
        const tablist = event.currentTarget.closest('[role="tablist"]');
        const isRtl = tablist?.getAttribute('dir') === 'rtl';
        let nextIndex: number | null = null;

        if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = monitoringTabs.length - 1;
        } else if (event.key === 'ArrowLeft') {
            nextIndex = isRtl
                ? (currentIndex + 1) % monitoringTabs.length
                : (currentIndex - 1 + monitoringTabs.length) % monitoringTabs.length;
        } else if (event.key === 'ArrowRight') {
            nextIndex = isRtl
                ? (currentIndex - 1 + monitoringTabs.length) % monitoringTabs.length
                : (currentIndex + 1) % monitoringTabs.length;
        }

        if (nextIndex === null) {
            return;
        }

        event.preventDefault();
        selectTab(monitoringTabs[nextIndex][0], true);
    }

    if (enrollmentContext.isPending) {
        return (
            <section className="teacher-monitoring-page" aria-labelledby="teacher-monitoring-detail-title">
                <h1 id="teacher-monitoring-detail-title">تفاصيل متابعة الطالب</h1>
                <Feedback>جارٍ التحقق من نطاق الطالب المخول...</Feedback>
            </section>
        );
    }

    if (enrollmentContext.isError) {
        return (
            <section className="teacher-monitoring-page" aria-labelledby="teacher-monitoring-detail-title">
                <h1 id="teacher-monitoring-detail-title">تفاصيل متابعة الطالب</h1>
                <Feedback tone="danger">
                    تعذر التحقق من نطاق الطالب المخول.
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => void enrollmentContext.refetch()}
                    >
                        إعادة تحميل بيانات الطالب
                    </Button>
                </Feedback>
                <Link className="teacher-monitoring-link" to="/teacher/monitoring">العودة إلى متابعة الطلاب</Link>
            </section>
        );
    }

    if (!enrollmentContext.data || !assignmentId) {
        return (
            <section className="teacher-monitoring-page" aria-labelledby="teacher-monitoring-detail-title">
                <h1 id="teacher-monitoring-detail-title">تفاصيل متابعة الطالب</h1>
                <Feedback tone="danger">هذا الالتحاق غير متاح للمعلم الحالي أو لم يعد نشطًا.</Feedback>
                <Link className="teacher-monitoring-link" to="/teacher/monitoring">العودة إلى متابعة الطلاب</Link>
            </section>
        );
    }

    return (
        <section className="teacher-monitoring-page" aria-labelledby="teacher-monitoring-detail-title">
            <header className="teacher-monitoring-page__heading">
                <Link className="teacher-monitoring-link" to={'/teacher/monitoring?assignment_id=' + assignmentId}>العودة إلى الطلاب</Link>
                <h1 id="teacher-monitoring-detail-title">تفاصيل متابعة الطالب</h1>
                <p>اختر منهجًا وإصدارًا منشورًا أو متقاعدًا لعرض الحقائق المسجلة ضمن ذلك الإصدار فقط.</p>
            </header>

            <EnrollmentIdentity enrollment={enrollmentContext.data} />

            <fieldset className="teacher-monitoring-version-selector">
                <legend>نطاق المنهج والإصدار</legend>
                <label>المنهج
                    <select
                        value={curriculumId}
                        disabled={curricula.isPending}
                        onChange={(event) => replaceContext({ curriculum_id: event.target.value || null, version_id: null })}
                    >
                        <option value="">اختر المنهج</option>
                        {curricula.data?.map((curriculum) => (
                            <option value={curriculum.id} key={curriculum.id}>{curriculum.name}</option>
                        ))}
                    </select>
                </label>
                <label>إصدار المنهج
                    <select
                        value={versionId}
                        disabled={!selectedCurriculum || versions.isPending}
                        onChange={(event) => replaceContext({ version_id: event.target.value || null })}
                    >
                        <option value="">اختر الإصدار</option>
                        {visibleVersions.map((version) => (
                            <option value={version.id} key={version.id}>
                                الإصدار {version.version_number} — {version.label} ({version.status === 'published' ? 'منشور' : 'متقاعد'})
                            </option>
                        ))}
                    </select>
                </label>
            </fieldset>

            {curricula.isPending ? (
                <Feedback>جارٍ تحميل المناهج المخولة...</Feedback>
            ) : null}
            {curricula.isError ? (
                <Feedback tone="danger">
                    تعذر تحميل المناهج المخولة.
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => void curricula.refetch()}
                    >
                        إعادة تحميل المناهج
                    </Button>
                </Feedback>
            ) : null}
            {selectedCurriculum && versions.isPending ? (
                <Feedback>جارٍ تحميل إصدارات المنهج المخولة...</Feedback>
            ) : null}
            {selectedCurriculum && versions.isError ? (
                <Feedback tone="danger">
                    تعذر تحميل إصدارات المنهج المخولة.
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => void versions.refetch()}
                    >
                        إعادة تحميل الإصدارات
                    </Button>
                </Feedback>
            ) : null}
            {versions.isSuccess && selectedCurriculum && visibleVersions.length === 0 ? (
                <Feedback>لا توجد إصدارات منشورة أو متقاعدة متاحة لهذا المنهج.</Feedback>
            ) : null}

            {!selectedVersion
                && !curricula.isPending
                && !curricula.isError
                && !(selectedCurriculum && versions.isPending)
                && !(selectedCurriculum && versions.isError) ? (
                <Feedback>اختر المنهج ثم الإصدار لعرض بيانات المتابعة.</Feedback>
            ) : (
                selectedVersion ? <>
                    <div
                        className="teacher-monitoring-tabs"
                        role="tablist"
                        aria-label="أقسام متابعة الطالب"
                        dir="rtl"
                    >
                        {monitoringTabs.map(([value, label]) => (
                            <button
                                key={value}
                                id={`teacher-monitoring-${enrollmentId}-${value}-tab`}
                                ref={(element) => {
                                    tabRefs.current[value] = element;
                                }}
                                type="button"
                                role="tab"
                                aria-selected={tab === value}
                                aria-controls={`teacher-monitoring-${enrollmentId}-${value}-panel`}
                                tabIndex={tab === value ? 0 : -1}
                                onClick={() => selectTab(value)}
                                onKeyDown={(event) => handleTabKeyDown(event, value)}
                            >{label}</button>
                        ))}
                    </div>

                    {monitoringTabs.map(([panelTab]) => {
                        const isActive = tab === panelTab;

                        return (
                            <div
                                key={panelTab}
                                id={`teacher-monitoring-${enrollmentId}-${panelTab}-panel`}
                                role="tabpanel"
                                aria-labelledby={`teacher-monitoring-${enrollmentId}-${panelTab}-tab`}
                                hidden={!isActive}
                                tabIndex={isActive ? 0 : -1}
                            >
                                {isActive && panelTab === 'progress' ? (
                                    <ProgressPanel
                                        assignmentId={assignmentId}
                                        enrollmentId={enrollmentId}
                                        userId={user!.id}
                                        versionId={selectedVersion.id}
                                    />
                                ) : null}
                                {isActive && panelTab !== 'progress' ? (
                                    <AttemptsPanel
                                        assignmentId={assignmentId}
                                        enrollmentId={enrollmentId}
                                        mode={panelTab}
                                        searchParams={searchParams}
                                        setSearchParams={setSearchParams}
                                        userId={user!.id}
                                        versionId={selectedVersion.id}
                                    />
                                ) : null}
                            </div>
                        );
                    })}
                </> : null
            )}
        </section>
    );
}

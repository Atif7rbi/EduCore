import '../../css/teacher-enrollment.css';

import {
    useEffect,
    useRef,
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';
import {
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
    decideTeacherEnrollment,
    fetchTeacherEnrollments,
    teacherEnrollmentsKey,
    teacherEnrollmentsRootKey,
} from './enrollmentApi';

import type {
    TeacherEnrollment,
    TeacherEnrollmentDecision,
    TeacherEnrollmentStatus,
} from './enrollmentApi';

interface TeacherEnrollmentPageProps {
    mode: 'requests' | 'students';
}

interface EnrollmentQueryContext {
    assignmentId: string | null;
    identity: string;
    page: number;
    status: TeacherEnrollmentStatus;
    userId: string;
}

type EnrollmentReconciliationState =
    | 'idle'
    | 'refreshing'
    | 'failed';

function dateLabel(value: string | null): string {
    if (!value) {
        return 'غير متاح';
    }

    return new Intl.DateTimeFormat(
        'ar-SA-u-ca-gregory',
        {
            dateStyle: 'medium',
        },
    ).format(new Date(value));
}

function statusLabel(
    status: TeacherEnrollmentStatus,
): string {
    return status === 'pending'
        ? 'بانتظار القرار'
        : 'نشط';
}

function TeacherEnrollmentCard({
    enrollment,
    isDeciding,
    onDecision,
    showActions,
}: {
    enrollment: TeacherEnrollment;
    isDeciding: boolean;
    onDecision: (
        enrollment: TeacherEnrollment,
        decision: TeacherEnrollmentDecision,
    ) => void;
    showActions: boolean;
}) {
    return (
        <article className="teacher-enrollment-card">
            <div className="teacher-enrollment-card__identity">
                <h2>{enrollment.student.name}</h2>
                <span className="teacher-enrollment-card__status">
                    {statusLabel(enrollment.status)}
                </span>
            </div>

            <dl className="teacher-enrollment-card__facts">
                <div>
                    <dt>المادة</dt>
                    <dd dir="auto">
                        {
                            enrollment
                                .teacher_subject_assignment
                                .subject
                                .name
                        }
                    </dd>
                </div>
                <div>
                    <dt>
                        {enrollment.status === 'active'
                            ? 'تاريخ الالتحاق'
                            : 'تاريخ الطلب'}
                    </dt>
                    <dd>
                        {dateLabel(
                            enrollment.accepted_at
                            ?? enrollment.created_at,
                        )}
                    </dd>
                </div>
                <div>
                    <dt>حالة الالتحاق</dt>
                    <dd>{statusLabel(enrollment.status)}</dd>
                </div>
            </dl>

            {showActions ? (
                <div
                    className="teacher-enrollment-card__actions"
                    aria-label={
                        'قرار طلب التحاق '
                        + enrollment.student.name
                    }
                >
                    <Button
                        isLoading={isDeciding}
                        disabled={isDeciding}
                        onClick={() => {
                            onDecision(enrollment, 'accept');
                        }}
                    >
                        قبول الطلب
                    </Button>

                    <Button
                        variant="danger"
                        disabled={isDeciding}
                        onClick={() => {
                            onDecision(enrollment, 'decline');
                        }}
                    >
                        رفض الطلب
                    </Button>
                </div>
            ) : null}
        </article>
    );
}

function TeacherEnrollmentPage({
    mode,
}: TeacherEnrollmentPageProps) {
    const {
        user,
    } = useAuth();
    const queryClient = useQueryClient();
    const [searchParams] = useSearchParams();
    const decisionInFlight = useRef(false);
    const reconciliationInFlight = useRef(false);
    const reconciliationGeneration = useRef(0);
    const [page, setPage] = useState(1);
    const [successMessage, setSuccessMessage] =
        useState<string | null>(null);
    const [reconciliationState, setReconciliationState] =
        useState<EnrollmentReconciliationState>('idle');
    const status: TeacherEnrollmentStatus =
        mode === 'requests'
            ? 'pending'
            : 'active';
    const assignmentId =
        searchParams.get('assignment_id') || null;
    const contextScopeIdentity = JSON.stringify([
        user?.id ?? null,
        user?.role ?? null,
        status,
        assignmentId,
    ]);
    const contextIdentity = JSON.stringify([
        contextScopeIdentity,
        page,
    ]);
    const activeContextIdentity = useRef(contextIdentity);

    if (activeContextIdentity.current !== contextIdentity) {
        activeContextIdentity.current = contextIdentity;
        reconciliationGeneration.current += 1;
        reconciliationInFlight.current = false;
    }
    const title = mode === 'requests'
        ? 'طلبات الالتحاق'
        : 'طلابي';
    const description = mode === 'requests'
        ? 'راجع الطلبات المعلّقة لموادك واتخذ القرار عبر دورة الالتحاق المعتمدة.'
        : 'الطلاب الملتحقون حاليًا بتعيينات المواد النشطة الخاصة بك.';

    const enrollments = useQuery({
        queryKey: teacherEnrollmentsKey(
            user?.id ?? 'unauthenticated',
            status,
            page,
            assignmentId,
        ),
        queryFn: ({ signal }) =>
            fetchTeacherEnrollments(
                status,
                page,
                signal,
                assignmentId,
            ),
        enabled: user?.role === 'teacher',
    });

    useEffect(() => {
        reconciliationInFlight.current = false;
        setReconciliationState('idle');
    }, [contextIdentity]);

    useEffect(() => {
        setSuccessMessage(null);
    }, [contextScopeIdentity]);

    const decision = useMutation({
        mutationFn: ({
            action,
            enrollmentId,
            operationId,
        }: {
            action: TeacherEnrollmentDecision;
            enrollmentId: string;
            operationId: string;
        }) => decideTeacherEnrollment(
            enrollmentId,
            action,
            operationId,
            action === 'accept'
                ? 'Teacher accepted the enrollment request.'
                : 'Teacher declined the enrollment request.',
        ),
        onSettled: () => {
            decisionInFlight.current = false;
        },
        onSuccess: async (_data, variables) => {
            setSuccessMessage(
                variables.action === 'accept'
                    ? 'تم قبول طلب الالتحاق.'
                    : 'تم رفض طلب الالتحاق.',
            );
            await reconcileEnrollmentQueries();
        },
    });

    async function fetchAuthoritativePage(
        context: EnrollmentQueryContext,
        targetPage: number,
    ) {
        return queryClient.fetchQuery({
            queryKey: teacherEnrollmentsKey(
                context.userId,
                context.status,
                targetPage,
                context.assignmentId,
            ),
            queryFn: ({ signal }) =>
                fetchTeacherEnrollments(
                    context.status,
                    targetPage,
                    signal,
                    context.assignmentId,
                ),
            retry: false,
            staleTime: 0,
        });
    }

    async function reconcileEnrollmentQueries() {
        if (
            reconciliationInFlight.current
            || !user
            || user.role !== 'teacher'
        ) {
            return;
        }

        const generation =
            reconciliationGeneration.current + 1;
        reconciliationGeneration.current = generation;
        const context: EnrollmentQueryContext = {
            assignmentId,
            identity: contextIdentity,
            page,
            status,
            userId: user.id,
        };
        const isCurrentContext = () =>
            reconciliationGeneration.current === generation
            && activeContextIdentity.current
                === context.identity;

        reconciliationInFlight.current = true;
        setReconciliationState('refreshing');

        try {
            await queryClient.cancelQueries(
                {
                    queryKey:
                        teacherEnrollmentsRootKey(
                            context.userId,
                        ),
                },
                {
                    revert: true,
                },
            );

            if (!isCurrentContext()) {
                return;
            }

            await queryClient.invalidateQueries({
                queryKey:
                    teacherEnrollmentsRootKey(
                        context.userId,
                    ),
                refetchType: 'none',
            });

            if (!isCurrentContext()) {
                return;
            }

            const refreshedPage =
                await fetchAuthoritativePage(
                    context,
                    context.page,
                );

            if (!isCurrentContext()) {
                return;
            }

            const refreshedLastPage =
                refreshedPage.pagination.total > 0
                    ? Math.max(
                        1,
                        refreshedPage.pagination.last_page,
                    )
                    : 1;

            if (context.page > refreshedLastPage) {
                await fetchAuthoritativePage(
                    context,
                    refreshedLastPage,
                );

                if (!isCurrentContext()) {
                    return;
                }

                setPage(refreshedLastPage);
            }

            setReconciliationState('idle');
        } catch {
            if (isCurrentContext()) {
                setReconciliationState('failed');
            }
        } finally {
            if (isCurrentContext()) {
                reconciliationInFlight.current = false;
            }
        }
    }

    function handleDecision(
        enrollment: TeacherEnrollment,
        action: TeacherEnrollmentDecision,
    ) {
        if (decisionInFlight.current || decision.isPending) {
            return;
        }

        const verb = action === 'accept'
            ? 'قبول'
            : 'رفض';

        if (!window.confirm(
            'هل تريد '
            + verb
            + ' طلب التحاق '
            + enrollment.student.name
            + '؟',
        )) {
            return;
        }

        decisionInFlight.current = true;
        setSuccessMessage(null);
        decision.reset();
        decision.mutate({
            action,
            enrollmentId: enrollment.id,
            operationId: crypto.randomUUID(),
        });
    }

    const data = enrollments.data;
    const items = data?.items ?? [];
    const normalizedLastPage =
        data && data.pagination.total > 0
            ? Math.max(1, data.pagination.last_page)
            : 1;
    const isPageOutOfRange =
        data !== undefined
        && page > normalizedLastPage;

    useEffect(() => {
        if (
            reconciliationState !== 'idle'
            || !enrollments.isSuccess
            || !data
            || !isPageOutOfRange
        ) {
            return;
        }

        setPage(normalizedLastPage);
    }, [
        data,
        enrollments.isSuccess,
        isPageOutOfRange,
        normalizedLastPage,
        reconciliationState,
    ]);

    return (
        <section
            className="teacher-enrollment-page"
            aria-labelledby={
                mode === 'requests'
                    ? 'teacher-enrollment-requests-title'
                    : 'teacher-students-title'
            }
        >
            <header className="teacher-enrollment-page__heading">
                <p>مساحة المعلم</p>
                <h1
                    id={
                        mode === 'requests'
                            ? 'teacher-enrollment-requests-title'
                            : 'teacher-students-title'
                    }
                >
                    {title}
                </h1>
                <p>{description}</p>
            </header>

            {successMessage ? (
                <Feedback tone="success">
                    {successMessage}
                </Feedback>
            ) : null}

            {decision.isError ? (
                <Feedback tone="danger">
                    {decision.error instanceof Error
                        ? decision.error.message
                        : 'تعذر إكمال قرار الالتحاق.'}
                </Feedback>
            ) : null}

            {enrollments.isPending ? (
                <Feedback>جارٍ تحميل البيانات...</Feedback>
            ) : null}

            {enrollments.isError
            && reconciliationState === 'idle' ? (
                <Feedback tone="danger">
                    {enrollments.error instanceof Error
                        ? enrollments.error.message
                        : 'تعذر تحميل بيانات الالتحاق.'}
                </Feedback>
            ) : null}

            {reconciliationState === 'refreshing' ? (
                <Feedback>
                    جارٍ تحديث قائمة الالتحاق المعتمدة...
                </Feedback>
            ) : null}

            {reconciliationState === 'failed' ? (
                <Feedback tone="danger">
                    <p>
                        تم تنفيذ قرار الالتحاق، لكن تعذر
                        تحديث القائمة من الخادم.
                    </p>
                    <Button
                        variant="secondary"
                        onClick={() => {
                            void reconcileEnrollmentQueries();
                        }}
                    >
                        إعادة تحميل القائمة
                    </Button>
                </Feedback>
            ) : null}

            {reconciliationState === 'idle'
            && isPageOutOfRange ? (
                <Feedback>
                    جارٍ الانتقال إلى آخر صفحة متاحة...
                </Feedback>
            ) : null}

            {enrollments.isSuccess
            && reconciliationState === 'idle'
            && !isPageOutOfRange
            && items.length === 0 ? (
                <Surface>
                    <Feedback>
                        {mode === 'requests'
                            ? 'لا توجد طلبات التحاق معلّقة.'
                            : 'لا يوجد طلاب ملتحقون حاليًا.'}
                    </Feedback>
                </Surface>
            ) : null}

            {enrollments.isSuccess
            && reconciliationState === 'idle'
            && !isPageOutOfRange
            && items.length > 0 ? (
                <div className="teacher-enrollment-list">
                    {items.map((enrollment) => (
                        <TeacherEnrollmentCard
                            key={enrollment.id}
                            enrollment={enrollment}
                            isDeciding={
                                decision.isPending
                                && decision.variables
                                    ?.enrollmentId
                                    === enrollment.id
                            }
                            onDecision={handleDecision}
                            showActions={
                                mode === 'requests'
                            }
                        />
                    ))}
                </div>
            ) : null}

            {data
            && enrollments.isSuccess
            && reconciliationState === 'idle'
            && data.pagination.total > 0
            && !isPageOutOfRange ? (
                <nav
                    className="teacher-enrollment-pagination"
                    aria-label="صفحات بيانات الالتحاق"
                >
                    <Button
                        variant="secondary"
                        disabled={page <= 1 || enrollments.isFetching}
                        onClick={() => {
                            setPage((current) => current - 1);
                        }}
                    >
                        الصفحة السابقة
                    </Button>
                    <span>
                        الصفحة {page} من {normalizedLastPage}
                    </span>
                    <Button
                        variant="secondary"
                        disabled={
                            page >= normalizedLastPage
                            || enrollments.isFetching
                        }
                        onClick={() => {
                            setPage((current) => current + 1);
                        }}
                    >
                        الصفحة التالية
                    </Button>
                </nav>
            ) : null}
        </section>
    );
}

export function TeacherEnrollmentRequestsPage() {
    return (
        <TeacherEnrollmentPage mode="requests" />
    );
}

export function TeacherStudentsPage() {
    return (
        <TeacherEnrollmentPage mode="students" />
    );
}

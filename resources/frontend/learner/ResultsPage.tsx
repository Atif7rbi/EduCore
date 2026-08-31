import {
    useQuery,
} from '@tanstack/react-query';
import {
    Link,
} from 'react-router-dom';

import {
    apiRequest,
} from '../api/client';
import {
    EduCoreApiError,
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../ui';

interface AttemptSummary {
    answered: number;
    correct: number;
    incorrect: number;
    unanswered: number;
    total: number;
}

interface AttemptHistoryItem {
    id: string;
    exam_generation_id: string | null;
    practice_activity_id: string | null;
    curriculum_version_id: string;
    status:
        | 'in_progress'
        | 'submitted'
        | 'abandoned';
    started_at: string | null;
    finalized_at: string | null;
    summary?: AttemptSummary;
}

function resultsQueryKey() {
    return [
        'learner',
        'attempt-history',
    ] as const;
}

async function fetchAttemptHistory():
Promise<AttemptHistoryItem[]> {
    return apiRequest<AttemptHistoryItem[]>({
        method: 'GET',
        url: '/api/attempts',
    });
}

function attemptKind(
    attempt: AttemptHistoryItem,
): string {
    if (attempt.exam_generation_id !== null) {
        return 'اختبار';
    }

    if (attempt.practice_activity_id !== null) {
        return 'ممارسة';
    }

    return 'محاولة';
}

function attemptStatus(
    attempt: AttemptHistoryItem,
): string {
    switch (attempt.status) {
        case 'in_progress':
            return 'قيد التقدم';
        case 'submitted':
            return 'مكتملة';
        case 'abandoned':
            return 'منتهية دون تسليم';
    }
}

function formatDate(
    value: string | null,
): string {
    if (value === null) {
        return 'غير متاح';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'غير متاح';
    }

    return new Intl.DateTimeFormat(
        'ar-SA',
        {
            dateStyle: 'medium',
            timeStyle: 'short',
        },
    ).format(date);
}

function ResultsFailure({
    error,
    retry,
}: {
    error: unknown;
    retry: () => void;
}) {
    const apiError =
        error instanceof EduCoreApiError
            ? error
            : null;

    return (
        <Feedback tone="danger">
            <div className="learner-read-error">
                <div>
                    <strong>
                        تعذر تحميل سجل المحاولات.
                    </strong>

                    <p>
                        أعد المحاولة لاسترجاع نتائجك ومحاولاتك السابقة.
                    </p>

                    {apiError?.requestId ? (
                        <p className="learner-read-request-id">
                            رقم الطلب: {apiError.requestId}
                        </p>
                    ) : null}
                </div>

                <Button
                    size="sm"
                    variant="secondary"
                    onClick={retry}
                >
                    إعادة المحاولة
                </Button>
            </div>
        </Feedback>
    );
}

function Summary({
    summary,
}: {
    summary: AttemptSummary;
}) {
    return (
        <div
            className="learner-results__summary"
            aria-label="ملخص النتيجة"
        >
            <div>
                <strong>
                    {summary.correct}
                </strong>
                <span>
                    صحيحة
                </span>
            </div>

            <div>
                <strong>
                    {summary.incorrect}
                </strong>
                <span>
                    غير صحيحة
                </span>
            </div>

            <div>
                <strong>
                    {summary.unanswered}
                </strong>
                <span>
                    بدون إجابة
                </span>
            </div>

            <div>
                <strong>
                    {summary.total}
                </strong>
                <span>
                    الإجمالي
                </span>
            </div>
        </div>
    );
}

export function ResultsPage() {
    const historyQuery = useQuery({
        queryKey: resultsQueryKey(),
        queryFn: fetchAttemptHistory,
    });

    if (historyQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل سجل المحاولات"
            >
                <Surface className="learner-read-loading">
                    جار تحميل سجل المحاولات…
                </Surface>
            </section>
        );
    }

    if (historyQuery.isError) {
        return (
            <section className="foundation-page">
                <ResultsFailure
                    error={historyQuery.error}
                    retry={() => {
                        void historyQuery.refetch();
                    }}
                />
            </section>
        );
    }

    const attempts =
        historyQuery.data;

    return (
        <section
            className="foundation-page learner-results"
            aria-labelledby="results-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    سجل التعلم
                </p>

                <h1
                    className="foundation-page__title"
                    id="results-title"
                >
                    النتائج والمحاولات
                </h1>

                <p className="foundation-page__description">
                    راجع اختباراتك وممارساتك السابقة،
                    واستأنف أي محاولة ما زالت قيد التقدم.
                </p>
            </div>

            {attempts.length === 0 ? (
                <Surface>
                    <Feedback>
                        لا توجد محاولات مسجلة حتى الآن.
                    </Feedback>
                </Surface>
            ) : (
                <div className="learner-results__list">
                    {attempts.map(
                        (attempt) => {
                            const finalized =
                                attempt.status
                                !== 'in_progress';

                            return (
                                <Surface
                                    key={attempt.id}
                                    className="learner-results__card"
                                    data-testid={`attempt-${attempt.id}`}
                                    elevated
                                >
                                    <div className="foundation-stack">
                                        <div className="learner-results__heading">
                                            <div>
                                                <p className="foundation-page__eyebrow">
                                                    {attemptKind(
                                                        attempt,
                                                    )}
                                                </p>

                                                <h2 className="foundation-card__title">
                                                    {attemptStatus(
                                                        attempt,
                                                    )}
                                                </h2>
                                            </div>

                                            <span className="learner-results__date">
                                                {formatDate(
                                                    attempt.started_at,
                                                )}
                                            </span>
                                        </div>

                                        {finalized
                                            && attempt.summary ? (
                                            <Summary
                                                summary={
                                                    attempt.summary
                                                }
                                            />
                                        ) : null}

                                        {attempt.status
                                            === 'in_progress' ? (
                                            <Link
                                                className="foundation-link learner-results__action"
                                                to={`/app/attempts/${attempt.id}`}
                                            >
                                                استئناف المحاولة
                                            </Link>
                                        ) : (
                                            <Link
                                                className="foundation-link learner-results__action"
                                                to={`/app/attempts/${attempt.id}`}
                                            >
                                                عرض النتيجة
                                            </Link>
                                        )}
                                    </div>
                                </Surface>
                            );
                        },
                    )}
                </div>
            )}
        </section>
    );
}

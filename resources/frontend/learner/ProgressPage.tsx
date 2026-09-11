import {
    useQuery,
} from '@tanstack/react-query';

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
import {
    SkillAnalyticsPanel,
} from './SkillAnalyticsPanel';

interface ProgressOverview {
    learning: {
        started_lessons_count: number;
        completed_lessons_count: number;
    };
    assessment: {
        attempts_total: number;
        submitted_attempts: number;
        abandoned_attempts: number;
        in_progress_attempts: number;
    };
}

function progressOverviewQueryKey() {
    return [
        'learner',
        'progress',
        'overview',
    ] as const;
}

async function fetchProgressOverview():
Promise<ProgressOverview> {
    return apiRequest<ProgressOverview>({
        method: 'GET',
        url: '/api/progress/overview',
    });
}

function ProgressFailure({
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
                        تعذر تحميل ملخص التقدم.
                    </strong>

                    <p>
                        أعد المحاولة لاسترجاع بيانات تقدمك.
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

function Metric({
    value,
    label,
}: {
    value: number;
    label: string;
}) {
    return (
        <div>
            <strong>
                {value}
            </strong>

            <span>
                {label}
            </span>
        </div>
    );
}

export function ProgressPage() {
    const overviewQuery = useQuery({
        queryKey:
            progressOverviewQueryKey(),
        queryFn:
            fetchProgressOverview,
    });

    if (overviewQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل التقدم"
            >
                <Surface className="learner-read-loading">
                    جار تحميل التقدم…
                </Surface>
            </section>
        );
    }

    if (overviewQuery.isError) {
        return (
            <section
                className="foundation-page"
                aria-labelledby="progress-title"
            >
                <div className="foundation-page__heading">
                    <p className="foundation-page__eyebrow">
                        التقدم
                    </p>

                    <h1
                        id="progress-title"
                        className="foundation-page__title"
                    >
                        التقدم والتحليلات
                    </h1>
                </div>

                <ProgressFailure
                    error={
                        overviewQuery.error
                    }
                    retry={() => {
                        void overviewQuery.refetch();
                    }}
                />
            </section>
        );
    }

    const overview =
        overviewQuery.data;

    return (
        <section
            className="foundation-page"
            aria-labelledby="progress-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    التقدم
                </p>

                <h1
                    id="progress-title"
                    className="foundation-page__title"
                >
                    التقدم والتحليلات
                </h1>

                <p className="foundation-page__description">
                    ملخص نشاطك التعليمي ومحاولاتك.
                </p>
            </div>

            <div className="foundation-stack">
                <Surface elevated>
                    <div className="foundation-stack">
                        <div>
                            <h2 className="foundation-card__title">
                                التعلّم
                            </h2>

                            <p className="foundation-page__description">
                                حالة الدروس التي بدأت بها وأكملتها.
                            </p>
                        </div>

                        <div
                            className="learner-results__summary"
                            aria-label="ملخص تقدم الدروس"
                        >
                            <Metric
                                value={
                                    overview
                                        .learning
                                        .started_lessons_count
                                }
                                label="دروس بدأت"
                            />

                            <Metric
                                value={
                                    overview
                                        .learning
                                        .completed_lessons_count
                                }
                                label="دروس مكتملة"
                            />
                        </div>
                    </div>
                </Surface>

                <Surface elevated>
                    <div className="foundation-stack">
                        <div>
                            <h2 className="foundation-card__title">
                                المحاولات
                            </h2>

                            <p className="foundation-page__description">
                                توزيع محاولات الاختبارات والممارسات حسب حالتها.
                            </p>
                        </div>

                        <div
                            className="learner-results__summary"
                            aria-label="ملخص المحاولات"
                        >
                            <Metric
                                value={
                                    overview
                                        .assessment
                                        .attempts_total
                                }
                                label="إجمالي المحاولات"
                            />

                            <Metric
                                value={
                                    overview
                                        .assessment
                                        .submitted_attempts
                                }
                                label="مسلّمة"
                            />

                            <Metric
                                value={
                                    overview
                                        .assessment
                                        .in_progress_attempts
                                }
                                label="قيد التقدم"
                            />

                            <Metric
                                value={
                                    overview
                                        .assessment
                                        .abandoned_attempts
                                }
                                label="منتهية دون تسليم"
                            />
                        </div>
                    </div>
                </Surface>

                <SkillAnalyticsPanel />
            </div>
        </section>
    );
}

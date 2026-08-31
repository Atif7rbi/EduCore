import {
    useMutation,
    useQuery,
} from '@tanstack/react-query';
import {
    Link,
    useNavigate,
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

interface ExamAttemptReference {
    id: string;
    status:
        | 'in_progress'
        | 'submitted'
        | 'abandoned';
    started_at: string | null;
    finalized_at: string | null;
}

interface ExamGeneration {
    id: string;
    curriculum_version_id: string;
    exam_template_version_id: string;
    template: {
        id: string;
        name: string;
        description: string | null;
    };
    template_version: {
        id: string;
        version_number: number;
        label: string | null;
    };
    generated_at: string | null;
    item_count: number;
    current_attempt:
        | ExamAttemptReference
        | null;
}

interface CreatedAttempt {
    id: string;
    exam_generation_id: string;
    practice_activity_id: null;
    curriculum_version_id: string;
    status: 'in_progress';
    started_at: string | null;
}

function examsQueryKey() {
    return [
        'learner',
        'exam-generations',
    ] as const;
}

async function fetchExamGenerations():
Promise<ExamGeneration[]> {
    return apiRequest<ExamGeneration[]>({
        method: 'GET',
        url: '/api/exam-generations',
    });
}

async function startExam(
    examGenerationId: string,
): Promise<CreatedAttempt> {
    return apiRequest<CreatedAttempt>({
        method: 'POST',
        url:
            `/api/exam-generations/${examGenerationId}/attempts`,
        data: {},
    });
}

function Failure({
    error,
    retry,
}: {
    error: unknown;
    retry?: () => void;
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
                        تعذر تحميل الاختبارات.
                    </strong>

                    <p>
                        أعد المحاولة لاسترجاع الاختبارات المتاحة.
                    </p>

                    {apiError?.requestId ? (
                        <p className="learner-read-request-id">
                            رقم الطلب: {apiError.requestId}
                        </p>
                    ) : null}
                </div>

                {retry ? (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={retry}
                    >
                        إعادة المحاولة
                    </Button>
                ) : null}
            </div>
        </Feedback>
    );
}

export function ExamsPage() {
    const navigate = useNavigate();

    const examsQuery = useQuery({
        queryKey: examsQueryKey(),
        queryFn: fetchExamGenerations,
    });

    const startMutation =
        useMutation({
            mutationFn: (
                examGenerationId: string,
            ) =>
                startExam(
                    examGenerationId,
                ),
            onSuccess: (attempt) => {
                navigate(
                    `/app/attempts/${attempt.id}`,
                );
            },
        });

    if (examsQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل الاختبارات"
            >
                <Surface className="learner-read-loading">
                    جار تحميل الاختبارات…
                </Surface>
            </section>
        );
    }

    if (examsQuery.isError) {
        return (
            <section className="foundation-page">
                <Failure
                    error={examsQuery.error}
                    retry={() => {
                        void examsQuery.refetch();
                    }}
                />
            </section>
        );
    }

    const generations =
        examsQuery.data;

    return (
        <section
            className="foundation-page learner-exams"
            aria-labelledby="exams-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    التقييم
                </p>

                <h1
                    className="foundation-page__title"
                    id="exams-title"
                >
                    الاختبارات
                </h1>

                <p className="foundation-page__description">
                    ابدأ اختبارًا متاحًا أو استأنف محاولتك الحالية.
                </p>
            </div>

            {startMutation.isError ? (
                <Failure
                    error={startMutation.error}
                />
            ) : null}

            {generations.length === 0 ? (
                <Surface>
                    <Feedback>
                        لا توجد اختبارات متاحة حاليًا.
                    </Feedback>
                </Surface>
            ) : (
                <div className="learner-exams__grid">
                    {generations.map(
                        (generation) => {
                            const attempt =
                                generation
                                    .current_attempt;

                            return (
                                <Surface
                                    key={
                                        generation.id
                                    }
                                    className="learner-exam-card"
                                    elevated
                                >
                                    <div className="foundation-stack">
                                        <div>
                                            <p className="foundation-page__eyebrow">
                                                اختبار
                                            </p>

                                            <h2 className="foundation-card__title">
                                                {
                                                    generation
                                                        .template
                                                        .name
                                                }
                                            </h2>

                                            {generation
                                                .template
                                                .description ? (
                                                <p className="foundation-card__text">
                                                    {
                                                        generation
                                                            .template
                                                            .description
                                                    }
                                                </p>
                                            ) : null}
                                        </div>

                                        <div className="learner-exam-card__meta">
                                            <span>
                                                {
                                                    generation
                                                        .item_count
                                                } سؤال
                                            </span>

                                            <span>
                                                الإصدار{' '}
                                                {
                                                    generation
                                                        .template_version
                                                        .version_number
                                                }
                                            </span>
                                        </div>

                                        {attempt === null ? (
                                            <Button
                                                disabled={
                                                    startMutation
                                                        .isPending
                                                }
                                                onClick={() => {
                                                    startMutation.mutate(
                                                        generation.id,
                                                    );
                                                }}
                                            >
                                                {startMutation
                                                    .isPending
                                                    ? 'جار بدء الاختبار…'
                                                    : 'ابدأ الاختبار'}
                                            </Button>
                                        ) : attempt.status
                                            === 'in_progress' ? (
                                            <Link
                                                className="foundation-link learner-exam-card__action"
                                                to={`/app/attempts/${attempt.id}`}
                                            >
                                                استئناف الاختبار
                                            </Link>
                                        ) : (
                                            <Link
                                                className="foundation-link learner-exam-card__action"
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

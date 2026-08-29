import {
    useMutation,
    useQuery,
} from '@tanstack/react-query';
import {
    Link,
    useNavigate,
    useParams,
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

interface PracticeActivityItem {
    id: string;
    assessment_item_revision_id: string;
    assessment_item_id: string;
    display_order: number;
}

interface PracticeActivity {
    id: string;
    curriculum_version_id: string;
    lesson_id: string | null;
    name: string;
    description: string | null;
    status: 'active';
    items: PracticeActivityItem[];
}

interface CreatedAttemptItem {
    id: string;
    assessment_item_revision_id: string;
    assessment_item_id: string;
    presentation_position: number;
    presented_payload: unknown;
    presented_schema_version: number;
}

interface CreatedAttempt {
    id: string;
    practice_activity_id: string;
    curriculum_version_id: string;
    status: 'in_progress';
    started_at: string | null;
    items: CreatedAttemptItem[];
}

function practiceQueryKey(
    practiceActivityId: string,
) {
    return [
        'learner',
        'practice-activity',
        practiceActivityId,
    ] as const;
}

async function fetchPracticeActivity(
    practiceActivityId: string,
): Promise<PracticeActivity> {
    return apiRequest<PracticeActivity>({
        method: 'GET',
        url:
            `/api/practice-activities/${practiceActivityId}`,
    });
}

async function createAttempt(
    practiceActivityId: string,
): Promise<CreatedAttempt> {
    return apiRequest<CreatedAttempt>({
        method: 'POST',
        url:
            `/api/practice-activities/${practiceActivityId}/attempts`,
        data: {},
    });
}

function RequestFailure({
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
                        تعذر إكمال طلب الممارسة.
                    </strong>

                    <p>
                        تحقق من توفر النشاط ثم أعد المحاولة.
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

export function PracticeActivityPage() {
    const navigate = useNavigate();

    const {
        practiceActivityId,
    } = useParams<{
        practiceActivityId: string;
    }>();

    if (!practiceActivityId) {
        return (
            <Feedback tone="danger">
                معرّف نشاط الممارسة غير صالح.
            </Feedback>
        );
    }

    const practiceQuery = useQuery({
        queryKey:
            practiceQueryKey(practiceActivityId),
        queryFn: () =>
            fetchPracticeActivity(
                practiceActivityId,
            ),
    });

    const createAttemptMutation =
        useMutation({
            mutationFn: () =>
                createAttempt(
                    practiceActivityId,
                ),
            onSuccess: (attempt) => {
                void navigate(
                    `/app/attempts/${attempt.id}`,
                );
            },
        });

    if (practiceQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل الممارسة"
            >
                <Surface className="learner-read-loading">
                    جار تحميل نشاط الممارسة…
                </Surface>
            </section>
        );
    }

    if (practiceQuery.isError) {
        return (
            <section className="foundation-page">
                <RequestFailure
                    error={practiceQuery.error}
                    retry={() => {
                        void practiceQuery.refetch();
                    }}
                />
            </section>
        );
    }

    const activity = practiceQuery.data;

    return (
        <section
            className="foundation-page learner-practice"
            aria-labelledby="practice-title"
        >
            <div className="foundation-page__heading">
                {activity.lesson_id ? (
                    <Link
                        className="foundation-link learner-back-link"
                        to={
                            `/app/lessons/${activity.lesson_id}`
                        }
                    >
                        العودة إلى الدرس
                    </Link>
                ) : (
                    <Link
                        className="foundation-link learner-back-link"
                        to={
                            `/app/curriculum/${activity.curriculum_version_id}`
                        }
                    >
                        العودة إلى المنهج
                    </Link>
                )}

                <p className="foundation-page__eyebrow">
                    ممارسة
                </p>

                <h1
                    className="foundation-page__title"
                    id="practice-title"
                >
                    {activity.name}
                </h1>

                {activity.description ? (
                    <p className="foundation-page__description">
                        {activity.description}
                    </p>
                ) : null}
            </div>

            <Surface
                className="learner-practice__summary"
                elevated
            >
                <div className="foundation-stack">
                    <h2 className="foundation-card__title">
                        جاهز للبدء؟
                    </h2>

                    <p className="foundation-card__text">
                        يحتوي هذا النشاط على{' '}
                        {activity.items.length}{' '}
                        من الأسئلة.
                    </p>

                    {activity.items.length === 0 ? (
                        <Feedback>
                            لا يحتوي هذا النشاط على أسئلة متاحة حاليًا.
                        </Feedback>
                    ) : (
                        <Button
                            onClick={() => {
                                createAttemptMutation.mutate();
                            }}
                            disabled={
                                createAttemptMutation.isPending
                            }
                        >
                            {createAttemptMutation.isPending
                                ? 'جار بدء الممارسة…'
                                : 'ابدأ الممارسة'}
                        </Button>
                    )}

                    {createAttemptMutation.isError ? (
                        <RequestFailure
                            error={
                                createAttemptMutation.error
                            }
                        />
                    ) : null}
                </div>
            </Surface>
        </section>
    );
}

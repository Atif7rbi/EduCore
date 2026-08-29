import {
    useEffect,
    useMemo,
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';
import {
    Link,
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

interface MultipleChoicePayload {
    stem: string;
    options: unknown[];
}

interface AttemptResponse {
    id: string;
    response_payload: {
        selected_option?: unknown;
    } | null;
    answer_change_count: number;
    time_spent_ms: number;
}

interface AttemptResult {
    original_is_correct: boolean | null;
    effective_is_correct: boolean | null;
    correction_number: number | null;
}

interface AttemptItem {
    id: string;
    assessment_item_revision_id: string;
    assessment_item_id: string;
    presentation_position: number;
    presented_payload: unknown;
    presented_schema_version: number;
    response: AttemptResponse | null;
    result?: AttemptResult;
}

interface AttemptSummary {
    answered: number;
    correct: number;
    incorrect: number;
    unanswered: number;
    total: number;
}

interface Attempt {
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
    items: AttemptItem[];
    summary?: AttemptSummary;
}

function attemptQueryKey(
    attemptId: string,
) {
    return [
        'learner',
        'attempt',
        attemptId,
    ] as const;
}

async function fetchAttempt(
    attemptId: string,
): Promise<Attempt> {
    return apiRequest<Attempt>({
        method: 'GET',
        url: `/api/attempts/${attemptId}`,
    });
}

async function saveResponse({
    attemptItemId,
    selectedOption,
    timeSpentMs,
}: {
    attemptItemId: string;
    selectedOption: number;
    timeSpentMs: number;
}) {
    return apiRequest<AttemptResponse>({
        method: 'PUT',
        url:
            `/api/attempt-items/${attemptItemId}/response`,
        data: {
            response_payload: {
                selected_option:
                    selectedOption,
            },
            time_spent_ms:
                timeSpentMs,
        },
    });
}

async function finalizeAttempt(
    attemptId: string,
) {
    return apiRequest<{
        id: string;
        status: 'submitted';
        finalized_at: string | null;
    }>({
        method: 'POST',
        url:
            `/api/attempts/${attemptId}/finalize`,
        data: {
            final_status: 'submitted',
        },
    });
}

function asMultipleChoice(
    payload: unknown,
): MultipleChoicePayload | null {
    if (
        payload === null
        || typeof payload !== 'object'
        || Array.isArray(payload)
    ) {
        return null;
    }

    const candidate =
        payload as {
            stem?: unknown;
            options?: unknown;
        };

    if (
        typeof candidate.stem !== 'string'
        || !Array.isArray(candidate.options)
        || candidate.options.length === 0
    ) {
        return null;
    }

    return {
        stem: candidate.stem,
        options: candidate.options,
    };
}

function formatOption(
    option: unknown,
): string {
    if (
        typeof option === 'string'
        || typeof option === 'number'
    ) {
        return String(option);
    }

    return 'خيار غير مدعوم';
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
                        تعذر إكمال العملية.
                    </strong>

                    <p>
                        أعد المحاولة مع الحفاظ على إجابتك الحالية.
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

function AttemptResultView({
    attempt,
}: {
    attempt: Attempt;
}) {
    const summary = attempt.summary;

    return (
        <section className="learner-attempt-result">
            <Surface
                className="learner-attempt-result__summary"
                elevated
            >
                <div className="foundation-stack">
                    <p className="foundation-page__eyebrow">
                        النتيجة
                    </p>

                    <h1 className="foundation-page__title">
                        اكتملت الممارسة
                    </h1>

                    {summary ? (
                        <div className="learner-attempt-result__metrics">
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
                    ) : null}

                    <Link
                        className="foundation-link"
                        to="/app/curriculum"
                    >
                        العودة إلى المناهج
                    </Link>
                </div>
            </Surface>

            <div className="learner-attempt-result__items">
                {attempt.items.map(
                    (item, index) => {
                        const question =
                            asMultipleChoice(
                                item.presented_payload,
                            );

                        return (
                            <Surface
                                key={item.id}
                                className="learner-attempt-result__item"
                            >
                                <div className="foundation-stack">
                                    <strong>
                                        السؤال {index + 1}
                                    </strong>

                                    {question ? (
                                        <p>
                                            {question.stem}
                                        </p>
                                    ) : null}

                                    <p>
                                        {item.result
                                            ?.effective_is_correct === true
                                            ? 'الإجابة صحيحة'
                                            : item.result
                                                ?.effective_is_correct === false
                                              ? 'الإجابة غير صحيحة'
                                              : 'لم تتم الإجابة'}
                                    </p>
                                </div>
                            </Surface>
                        );
                    },
                )}
            </div>
        </section>
    );
}

export function AttemptPage() {
    const queryClient =
        useQueryClient();

    const {
        attemptId,
    } = useParams<{
        attemptId: string;
    }>();

    const [
        currentIndex,
        setCurrentIndex,
    ] = useState(0);

    const [
        selectedOption,
        setSelectedOption,
    ] = useState<number | null>(
        null,
    );

    const [
        itemStartedAt,
        setItemStartedAt,
    ] = useState(() => Date.now());

    if (!attemptId) {
        return (
            <Feedback tone="danger">
                معرّف المحاولة غير صالح.
            </Feedback>
        );
    }

    const attemptQuery = useQuery({
        queryKey:
            attemptQueryKey(attemptId),
        queryFn: () =>
            fetchAttempt(attemptId),
    });

    const attempt =
        attemptQuery.data;

    const currentItem =
        attempt?.items[currentIndex];

    useEffect(() => {
        if (!currentItem) {
            return;
        }

        const storedSelection =
            currentItem.response
                ?.response_payload
                ?.selected_option;

        setSelectedOption(
            typeof storedSelection === 'number'
                ? storedSelection
                : null,
        );

        setItemStartedAt(Date.now());
    }, [
        currentItem?.id,
        currentItem?.response
            ?.response_payload
            ?.selected_option,
    ]);

    const saveMutation =
        useMutation({
            mutationFn: ({
                item,
                option,
            }: {
                item: AttemptItem;
                option: number;
            }) =>
                saveResponse({
                    attemptItemId:
                        item.id,
                    selectedOption:
                        option,
                    timeSpentMs:
                        Math.max(
                            0,
                            Date.now()
                            - itemStartedAt
                            + (
                                item.response
                                    ?.time_spent_ms
                                ?? 0
                            ),
                        ),
                }),
            onSuccess: async () => {
                await queryClient.invalidateQueries({
                    queryKey:
                        attemptQueryKey(
                            attemptId,
                        ),
                });
            },
        });

    const finalizeMutation =
        useMutation({
            mutationFn: () =>
                finalizeAttempt(
                    attemptId,
                ),
            onSuccess: async () => {
                await queryClient.invalidateQueries({
                    queryKey:
                        attemptQueryKey(
                            attemptId,
                        ),
                });
            },
        });

    const question =
        useMemo(
            () =>
                currentItem
                    ? asMultipleChoice(
                        currentItem.presented_payload,
                    )
                    : null,
            [currentItem],
        );

    if (attemptQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل المحاولة"
            >
                <Surface className="learner-read-loading">
                    جار تحميل المحاولة…
                </Surface>
            </section>
        );
    }

    if (attemptQuery.isError) {
        return (
            <section className="foundation-page">
                <Failure
                    error={attemptQuery.error}
                    retry={() => {
                        void attemptQuery.refetch();
                    }}
                />
            </section>
        );
    }

    if (!attempt) {
        return null;
    }

    if (
        attempt.status === 'submitted'
        || attempt.status === 'abandoned'
    ) {
        return (
            <section className="foundation-page">
                <AttemptResultView
                    attempt={attempt}
                />
            </section>
        );
    }

    if (
        attempt.items.length === 0
        || !currentItem
    ) {
        return (
            <section className="foundation-page">
                <Feedback>
                    لا تحتوي هذه المحاولة على أسئلة.
                </Feedback>
            </section>
        );
    }

    const isLast =
        currentIndex
        === attempt.items.length - 1;

    const saveCurrentResponse =
        async (): Promise<boolean> => {
            if (
                selectedOption === null
            ) {
                return true;
            }

            try {
                await saveMutation.mutateAsync({
                    item: currentItem,
                    option:
                        selectedOption,
                });

                return true;
            } catch {
                return false;
            }
        };

    const saveAndContinue =
        async () => {
            if (
                selectedOption === null
            ) {
                return;
            }

            const saved =
                await saveCurrentResponse();

            if (!saved) {
                return;
            }

            if (!isLast) {
                setCurrentIndex(
                    (index) =>
                        index + 1,
                );

                return;
            }

            try {
                await finalizeMutation.mutateAsync();
            } catch {
                // Mutation state renders the recoverable
                // error without advancing the workflow.
            }
        };

    const saveAndGoPrevious =
        async () => {
            if (currentIndex === 0) {
                return;
            }

            const saved =
                await saveCurrentResponse();

            if (!saved) {
                return;
            }

            setCurrentIndex(
                (index) =>
                    Math.max(
                        0,
                        index - 1,
                    ),
            );
        };

    return (
        <section
            className="foundation-page learner-attempt"
            aria-labelledby="attempt-question"
        >
            <div className="learner-attempt__header">
                <div>
                    <p className="foundation-page__eyebrow">
                        ممارسة
                    </p>

                    <p className="learner-attempt__progress">
                        السؤال {currentIndex + 1}
                        {' '}من{' '}
                        {attempt.items.length}
                    </p>
                </div>

                <div
                    className="learner-attempt__progress-bar"
                    aria-hidden="true"
                >
                    <span
                        style={{
                            width:
                                `${
                                    ((currentIndex + 1)
                                        / attempt.items.length)
                                    * 100
                                }%`,
                        }}
                    />
                </div>
            </div>

            <Surface
                className="learner-attempt__question"
                elevated
            >
                {!question ? (
                    <Feedback tone="danger">
                        صيغة هذا السؤال غير مدعومة في واجهة المتعلم الحالية.
                    </Feedback>
                ) : (
                    <div className="foundation-stack">
                        <h1
                            className="learner-attempt__stem"
                            id="attempt-question"
                        >
                            {question.stem}
                        </h1>

                        <div
                            className="learner-attempt__options"
                            role="radiogroup"
                            aria-label="خيارات الإجابة"
                        >
                            {question.options.map(
                                (
                                    option,
                                    index,
                                ) => (
                                    <label
                                        key={index}
                                        className={
                                            selectedOption
                                            === index
                                                ? 'learner-attempt__option learner-attempt__option--selected'
                                                : 'learner-attempt__option'
                                        }
                                    >
                                        <input
                                            type="radio"
                                            name="answer"
                                            value={index}
                                            checked={
                                                selectedOption
                                                === index
                                            }
                                            onChange={() => {
                                                setSelectedOption(
                                                    index,
                                                );
                                            }}
                                        />

                                        <span>
                                            {formatOption(
                                                option,
                                            )}
                                        </span>
                                    </label>
                                ),
                            )}
                        </div>
                    </div>
                )}
            </Surface>

            {saveMutation.isError ? (
                <Failure
                    error={
                        saveMutation.error
                    }
                />
            ) : null}

            {finalizeMutation.isError ? (
                <Failure
                    error={
                        finalizeMutation.error
                    }
                />
            ) : null}

            <div className="learner-attempt__actions">
                <Button
                    variant="secondary"
                    disabled={
                        currentIndex === 0
                        || saveMutation.isPending
                        || finalizeMutation.isPending
                    }
                    onClick={() => {
                        void saveAndGoPrevious();
                    }}
                >
                    السابق
                </Button>

                <Button
                    disabled={
                        selectedOption === null
                        || !question
                        || saveMutation.isPending
                        || finalizeMutation.isPending
                    }
                    onClick={() => {
                        void saveAndContinue();
                    }}
                >
                    {saveMutation.isPending
                    || finalizeMutation.isPending
                        ? 'جار الحفظ…'
                        : isLast
                          ? 'إنهاء الممارسة'
                          : 'حفظ والتالي'}
                </Button>
            </div>
        </section>
    );
}

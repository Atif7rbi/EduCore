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

interface LessonPracticeActivity {
    id: string;
    name: string;
    description: string | null;
    status: 'active';
}

interface ContentBlock {
    type?: unknown;
    value?: unknown;
}

interface LessonContentPayload {
    blocks?: unknown;
}

interface PublishedRevision {
    id: string;
    revision_number: number;
    primary_topic_id: string;
    content_payload: unknown;
    content_schema_version: number;
    released_at: string | null;
}

interface Lesson {
    id: string;
    curriculum_version_id: string;
    title: string;
    description: string | null;
    status: 'published';
    display_order: number;
    published_revision: PublishedRevision;
    practice_activities: LessonPracticeActivity[];
}

interface LessonProgress {
    id: string;
    lesson_revision_id: string;
    status:
        | 'in_progress'
        | 'completed';
    started_at: string | null;
    completed_at: string | null;
}

function lessonQueryKey(
    lessonId: string,
) {
    return [
        'learner',
        'lesson',
        lessonId,
    ] as const;
}

function lessonProgressQueryKey(
    lessonId: string,
) {
    return [
        'learner',
        'lesson-progress',
        lessonId,
    ] as const;
}

async function fetchLesson(
    lessonId: string,
): Promise<Lesson> {
    return apiRequest<Lesson>({
        method: 'GET',
        url: `/api/lessons/${lessonId}`,
    });
}

async function fetchLessonProgress(
    lessonId: string,
): Promise<LessonProgress | null> {
    return apiRequest<LessonProgress | null>({
        method: 'GET',
        url:
            `/api/lessons/${lessonId}/progress`,
    });
}

async function startLessonProgress(
    lessonId: string,
): Promise<LessonProgress> {
    return apiRequest<LessonProgress>({
        method: 'POST',
        url:
            `/api/lessons/${lessonId}/progress`,
        data: {},
    });
}

async function completeLessonProgress(
    lessonId: string,
): Promise<LessonProgress> {
    return apiRequest<LessonProgress>({
        method: 'POST',
        url:
            `/api/lessons/${lessonId}/complete`,
        data: {},
    });
}

function extractTextBlocks(
    payload: unknown,
): string[] {
    if (
        payload === null
        || typeof payload !== 'object'
        || Array.isArray(payload)
    ) {
        return [];
    }

    const candidate =
        payload as LessonContentPayload;

    if (!Array.isArray(candidate.blocks)) {
        return [];
    }

    return candidate.blocks
        .map((block): string | null => {
            if (
                block === null
                || typeof block !== 'object'
                || Array.isArray(block)
            ) {
                return null;
            }

            const candidateBlock =
                block as ContentBlock;

            if (
                candidateBlock.type === 'text'
                && typeof candidateBlock.value
                    === 'string'
            ) {
                return candidateBlock.value;
            }

            return null;
        })
        .filter(
            (value): value is string =>
                value !== null,
        );
}

function ProgressFailure({
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
                        تعذر تحديث حالة تقدم الدرس.
                    </strong>

                    <p>
                        يمكنك متابعة قراءة الدرس ثم إعادة المحاولة.
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

export function LessonPage() {
    const queryClient =
        useQueryClient();

    const {
        lessonId,
    } = useParams<{
        lessonId: string;
    }>();

    if (!lessonId) {
        return (
            <Feedback tone="danger">
                معرّف الدرس غير صالح.
            </Feedback>
        );
    }

    const lessonQuery = useQuery({
        queryKey: lessonQueryKey(lessonId),
        queryFn: () =>
            fetchLesson(lessonId),
    });

    const progressQuery = useQuery({
        queryKey:
            lessonProgressQueryKey(
                lessonId,
            ),
        queryFn: () =>
            fetchLessonProgress(
                lessonId,
            ),
    });

    const startMutation =
        useMutation({
            mutationFn: () =>
                startLessonProgress(
                    lessonId,
                ),
            onSuccess: (progress) => {
                queryClient.setQueryData(
                    lessonProgressQueryKey(
                        lessonId,
                    ),
                    progress,
                );
            },
        });

    const completeMutation =
        useMutation({
            mutationFn: () =>
                completeLessonProgress(
                    lessonId,
                ),
            onSuccess: (progress) => {
                queryClient.setQueryData(
                    lessonProgressQueryKey(
                        lessonId,
                    ),
                    progress,
                );
            },
        });

    if (lessonQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل الدرس"
            >
                <Surface className="learner-read-loading">
                    جار تحميل الدرس…
                </Surface>
            </section>
        );
    }

    if (lessonQuery.isError) {
        const apiError =
            lessonQuery.error
                instanceof EduCoreApiError
                ? lessonQuery.error
                : null;

        return (
            <section className="foundation-page">
                <Feedback tone="danger">
                    <div className="learner-read-error">
                        <div>
                            <strong>
                                تعذر تحميل الدرس.
                            </strong>

                            <p>
                                أعد المحاولة لاسترجاع النسخة المنشورة.
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
                            onClick={() => {
                                void lessonQuery.refetch();
                            }}
                        >
                            إعادة المحاولة
                        </Button>
                    </div>
                </Feedback>
            </section>
        );
    }

    const lesson = lessonQuery.data;

    const textBlocks =
        extractTextBlocks(
            lesson.published_revision
                .content_payload,
        );

    const progress =
        progressQuery.data ?? null;

    const progressPending =
        progressQuery.isPending;

    const mutationPending =
        startMutation.isPending
        || completeMutation.isPending;

    return (
        <section
            className="foundation-page learner-lesson"
            aria-labelledby="lesson-title"
        >
            <div className="foundation-page__heading">
                <Link
                    className="foundation-link learner-back-link"
                    to={
                        `/app/curriculum/${lesson.curriculum_version_id}`
                    }
                >
                    العودة إلى المنهج
                </Link>

                <p className="foundation-page__eyebrow">
                    درس
                </p>

                <h1
                    className="foundation-page__title"
                    id="lesson-title"
                >
                    {lesson.title}
                </h1>

                {lesson.description ? (
                    <p className="foundation-page__description">
                        {lesson.description}
                    </p>
                ) : null}
            </div>

            <Surface
                className="learner-lesson__progress"
            >
                <div className="foundation-stack">
                    <div>
                        <p className="foundation-page__eyebrow">
                            تقدم الدرس
                        </p>

                        <h2 className="foundation-card__title">
                            {progressPending
                                ? 'جار تحميل الحالة…'
                                : progress?.status
                                    === 'completed'
                                  ? 'مكتمل'
                                  : progress?.status
                                      === 'in_progress'
                                    ? 'قيد التقدم'
                                    : 'لم يبدأ'}
                        </h2>
                    </div>

                    {progressQuery.isError ? (
                        <ProgressFailure
                            error={
                                progressQuery.error
                            }
                            retry={() => {
                                void progressQuery.refetch();
                            }}
                        />
                    ) : null}

                    {startMutation.isError ? (
                        <ProgressFailure
                            error={
                                startMutation.error
                            }
                        />
                    ) : null}

                    {completeMutation.isError ? (
                        <ProgressFailure
                            error={
                                completeMutation.error
                            }
                        />
                    ) : null}

                    {!progressPending
                    && !progressQuery.isError
                    && progress === null ? (
                        <Button
                            disabled={
                                mutationPending
                            }
                            onClick={() => {
                                startMutation.mutate();
                            }}
                        >
                            {startMutation.isPending
                                ? 'جار بدء الدرس…'
                                : 'ابدأ الدرس'}
                        </Button>
                    ) : null}

                    {!progressPending
                    && !progressQuery.isError
                    && progress?.status
                        === 'in_progress' ? (
                        <Button
                            disabled={
                                mutationPending
                            }
                            onClick={() => {
                                completeMutation.mutate();
                            }}
                        >
                            {completeMutation.isPending
                                ? 'جار إكمال الدرس…'
                                : 'إكمال الدرس'}
                        </Button>
                    ) : null}

                    {progress?.status
                        === 'completed' ? (
                        <Feedback tone="success">
                            تم تسجيل إكمال النسخة المنشورة الحالية من الدرس.
                        </Feedback>
                    ) : null}
                </div>
            </Surface>

            <Surface
                className="learner-lesson__content"
                elevated
            >
                <article className="learner-lesson__article">
                    {textBlocks.length > 0 ? (
                        textBlocks.map(
                            (text, index) => (
                                <p key={index}>
                                    {text}
                                </p>
                            ),
                        )
                    ) : (
                        <Feedback>
                            محتوى هذا الدرس يستخدم تنسيقًا لم يتم عرضه بعد في واجهة المتعلم.
                        </Feedback>
                    )}
                </article>
            </Surface>

            {lesson.practice_activities.length > 0 ? (
                <Surface className="learner-lesson__practice">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            ممارسة مرتبطة بالدرس
                        </h2>

                        <p className="foundation-card__text">
                            اختر نشاطًا تدريبيًا لبدء ممارسة مرتبطة بهذا الدرس.
                        </p>

                        <ul className="learner-practice-list">
                            {lesson.practice_activities.map(
                                (activity) => (
                                    <li
                                        key={activity.id}
                                    >
                                        <Link
                                            className="learner-practice-item learner-practice-item--link"
                                            to={`/app/practice/${activity.id}`}
                                        >
                                            <strong>
                                                {activity.name}
                                            </strong>

                                            {activity.description ? (
                                                <span>
                                                    {activity.description}
                                                </span>
                                            ) : null}

                                            <span className="learner-practice-item__action">
                                                فتح الممارسة
                                            </span>
                                        </Link>
                                    </li>
                                ),
                            )}
                        </ul>
                    </div>
                </Surface>
            ) : null}
        </section>
    );
}

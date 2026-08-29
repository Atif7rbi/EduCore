import {
    useQuery,
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

function lessonQueryKey(
    lessonId: string,
) {
    return [
        'learner',
        'lesson',
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
                && typeof candidateBlock.value === 'string'
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

export function LessonPage() {
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
                            توجد أنشطة تدريبية متاحة لهذا الدرس. سيتم تفعيل بدء الممارسة في المرحلة التالية.
                        </p>

                        <ul className="learner-practice-list">
                            {lesson.practice_activities.map(
                                (activity) => (
                                    <li
                                        key={activity.id}
                                        className="learner-practice-item"
                                    >
                                        <strong>
                                            {activity.name}
                                        </strong>

                                        {activity.description ? (
                                            <span>
                                                {activity.description}
                                            </span>
                                        ) : null}
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

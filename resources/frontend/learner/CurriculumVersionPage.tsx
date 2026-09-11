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

interface Topic {
    id: string;
    name: string;
    display_order: number;
}

interface CurriculumVersion {
    id: string;
    curriculum_id: string;
    version_number: number;
    label: string;
    status: 'published';
    topics: Topic[];
}

interface LessonSummary {
    id: string;
    curriculum_version_id: string;
    title: string;
    description: string | null;
    status: 'published';
    display_order: number;
    published_revision_id: string;
}

function versionQueryKey(
    curriculumVersionId: string,
) {
    return [
        'learner',
        'curriculum-version',
        curriculumVersionId,
    ] as const;
}

function lessonsQueryKey(
    curriculumVersionId: string,
) {
    return [
        'learner',
        'curriculum-version',
        curriculumVersionId,
        'lessons',
    ] as const;
}

async function fetchVersion(
    curriculumVersionId: string,
): Promise<CurriculumVersion> {
    return apiRequest<CurriculumVersion>({
        method: 'GET',
        url:
            `/api/curriculum-versions/${curriculumVersionId}`,
    });
}

async function fetchLessons(
    curriculumVersionId: string,
): Promise<LessonSummary[]> {
    return apiRequest<LessonSummary[]>({
        method: 'GET',
        url:
            `/api/curriculum-versions/${curriculumVersionId}/lessons`,
    });
}

function QueryFailure({
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
                        تعذر تحميل محتوى المنهج.
                    </strong>

                    <p>
                        أعد المحاولة لاسترجاع المحتوى المنشور.
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

export function CurriculumVersionPage() {
    const {
        curriculumVersionId,
    } = useParams<{
        curriculumVersionId: string;
    }>();

    if (!curriculumVersionId) {
        return (
            <Feedback tone="danger">
                معرّف إصدار المنهج غير صالح.
            </Feedback>
        );
    }

    const versionQuery = useQuery({
        queryKey:
            versionQueryKey(curriculumVersionId),
        queryFn: () =>
            fetchVersion(curriculumVersionId),
    });

    const lessonsQuery = useQuery({
        queryKey:
            lessonsQueryKey(curriculumVersionId),
        queryFn: () =>
            fetchLessons(curriculumVersionId),
    });

    const isPending =
        versionQuery.isPending
        || lessonsQuery.isPending;

    const error =
        versionQuery.error
        ?? lessonsQuery.error;

    if (isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل المنهج"
            >
                <Surface className="learner-read-loading">
                    جار تحميل محتوى المنهج…
                </Surface>
            </section>
        );
    }

    if (
        versionQuery.isError
        || lessonsQuery.isError
    ) {
        return (
            <section className="foundation-page">
                <QueryFailure
                    error={error}
                    retry={() => {
                        void Promise.all([
                            versionQuery.refetch(),
                            lessonsQuery.refetch(),
                        ]);
                    }}
                />
            </section>
        );
    }

    const version = versionQuery.data;
    const lessons = lessonsQuery.data;

    return (
        <section
            className="foundation-page learner-curriculum-version"
            aria-labelledby="curriculum-version-title"
        >
            <div className="foundation-page__heading">
                <Link
                    className="foundation-link learner-back-link"
                    to="/app/curriculum"
                >
                    العودة إلى المناهج
                </Link>

                <p className="foundation-page__eyebrow">
                    المنهج
                </p>

                <h1
                    className="foundation-page__title"
                    id="curriculum-version-title"
                >
                    {version.label}
                </h1>

                <p className="foundation-page__description">
                    الإصدار {version.version_number}
                </p>
            </div>

            <div className="learner-content-grid">
                <Surface className="learner-content-panel">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            الموضوعات
                        </h2>

                        {version.topics.length === 0 ? (
                            <Feedback>
                                لا توجد موضوعات منشورة في هذا الإصدار.
                            </Feedback>
                        ) : (
                            <ol className="learner-topic-list">
                                {version.topics.map(
                                    (topic) => (
                                        <li
                                            key={topic.id}
                                            className="learner-topic-item"
                                        >
                                            {topic.name}
                                        </li>
                                    ),
                                )}
                            </ol>
                        )}
                    </div>
                </Surface>

                <Surface className="learner-content-panel">
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            الدروس
                        </h2>

                        {lessons.length === 0 ? (
                            <Feedback>
                                لا توجد دروس منشورة في هذا الإصدار حاليًا.
                            </Feedback>
                        ) : (
                            <div className="learner-lesson-list">
                                {lessons.map(
                                    (lesson) => (
                                        <Link
                                            key={lesson.id}
                                            className="learner-lesson-card"
                                            to={`/app/lessons/${lesson.id}`}
                                        >
                                            <strong>
                                                {lesson.title}
                                            </strong>

                                            {lesson.description ? (
                                                <span>
                                                    {lesson.description}
                                                </span>
                                            ) : null}

                                            <span className="learner-lesson-card__action">
                                                فتح الدرس
                                            </span>
                                        </Link>
                                    ),
                                )}
                            </div>
                        )}
                    </div>
                </Surface>
            </div>
        </section>
    );
}

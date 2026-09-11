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

interface CurriculumSubject {
    id: string;
    name: string;
}

interface CurriculumSummary {
    id: string;
    name: string;
}

interface PublishedCurriculumVersion {
    id: string;
    version_number: number;
    label: string;
}

export interface CurriculumDiscoveryEntry {
    subject: CurriculumSubject;
    curriculum: CurriculumSummary;
    published_versions: PublishedCurriculumVersion[];
}

export const curriculumDiscoveryQueryKey = [
    'learner',
    'curricula',
] as const;

async function fetchCurricula(): Promise<
    CurriculumDiscoveryEntry[]
> {
    return apiRequest<CurriculumDiscoveryEntry[]>({
        method: 'GET',
        url: '/api/curricula',
    });
}

function CurriculumLoadingState() {
    return (
        <div
            className="curriculum-discovery__grid"
            aria-label="جار تحميل المناهج"
            aria-busy="true"
        >
            {[1, 2, 3].map((item) => (
                <Surface
                    key={item}
                    className="curriculum-card curriculum-card--loading"
                >
                    <div
                        className="curriculum-skeleton curriculum-skeleton--short"
                        aria-hidden="true"
                    />
                    <div
                        className="curriculum-skeleton curriculum-skeleton--title"
                        aria-hidden="true"
                    />
                    <div
                        className="curriculum-skeleton"
                        aria-hidden="true"
                    />
                </Surface>
            ))}
        </div>
    );
}

function CurriculumErrorState({
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
            <div className="curriculum-discovery__feedback">
                <div>
                    <strong>
                        تعذر تحميل المناهج.
                    </strong>

                    <p>
                        حاول مرة أخرى لإعادة تحميل المحتوى المتاح.
                    </p>

                    {apiError?.requestId ? (
                        <p className="curriculum-discovery__request-id">
                            رقم الطلب: {apiError.requestId}
                        </p>
                    ) : null}
                </div>

                <Button
                    variant="secondary"
                    size="sm"
                    onClick={retry}
                >
                    إعادة المحاولة
                </Button>
            </div>
        </Feedback>
    );
}

function CurriculumEmptyState() {
    return (
        <Surface className="curriculum-discovery__empty">
            <div className="foundation-stack">
                <h2 className="foundation-card__title">
                    لا توجد مناهج منشورة حاليًا
                </h2>

                <p className="foundation-card__text">
                    ستظهر المناهج هنا عندما تصبح إصداراتها التعليمية متاحة للمتعلمين.
                </p>
            </div>
        </Surface>
    );
}

export function CurriculumDiscoveryPage() {
    const curriculaQuery = useQuery({
        queryKey: curriculumDiscoveryQueryKey,
        queryFn: fetchCurricula,
    });

    return (
        <section
            className="foundation-page curriculum-discovery"
            aria-labelledby="curriculum-discovery-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    التعلم
                </p>

                <h1
                    className="foundation-page__title"
                    id="curriculum-discovery-title"
                >
                    المناهج
                </h1>

                <p className="foundation-page__description">
                    استعرض المناهج والإصدارات المنشورة والمتاحة للتعلم.
                </p>
            </div>

            {curriculaQuery.isPending ? (
                <CurriculumLoadingState />
            ) : null}

            {curriculaQuery.isError ? (
                <CurriculumErrorState
                    error={curriculaQuery.error}
                    retry={() => {
                        void curriculaQuery.refetch();
                    }}
                />
            ) : null}

            {curriculaQuery.isSuccess
            && curriculaQuery.data.length === 0 ? (
                <CurriculumEmptyState />
            ) : null}

            {curriculaQuery.isSuccess
            && curriculaQuery.data.length > 0 ? (
                <div className="curriculum-discovery__grid">
                    {curriculaQuery.data.map((entry) => (
                        <Surface
                            key={entry.curriculum.id}
                            className="curriculum-card"
                            elevated
                        >
                            <div className="curriculum-card__content">
                                <p className="curriculum-card__subject">
                                    {entry.subject.name}
                                </p>

                                <h2 className="curriculum-card__title">
                                    {entry.curriculum.name}
                                </h2>

                                <div className="curriculum-card__versions">
                                    <p className="curriculum-card__versions-label">
                                        الإصدارات المتاحة
                                    </p>

                                    <ul className="curriculum-card__version-list">
                                        {entry.published_versions.map(
                                            (version) => (
                                                <li
                                                    key={version.id}
                                                >
                                                    <Link
                                                        className="curriculum-card__version"
                                                        to={`/app/curriculum/${version.id}`}
                                                    >
                                                        <span>
                                                            {version.label}
                                                        </span>

                                                        <span className="curriculum-card__version-number">
                                                            الإصدار {version.version_number}
                                                        </span>
                                                    </Link>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            </div>
                        </Surface>
                    ))}
                </div>
            ) : null}
        </section>
    );
}

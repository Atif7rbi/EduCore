import {
    FormEvent,
    useMemo,
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';

import {
    EduCoreApiError,
} from '../../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../../ui';

import {
    adminLessonRevisionsKey,
    adminLessonsKey,
    adminTopicsKey,
    createLessonRevision,
    fetchLessonRevisions,
    fetchTopics,
    publishLesson,
    releaseLessonRevision,
    retireLesson,
} from './api';

import {
    RevisionSkillsPanel,
} from './RevisionSkillsPanel';

import type {
    CurriculumVersion,
    Lesson,
    LessonRevision,
} from './types';

interface LessonRevisionsPanelProps {
    version: CurriculumVersion;
    lesson: Lesson;
    onClose: () => void;
}

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function RevisionFailure({
    children,
    error,
}: {
    children: string;
    error: unknown;
}) {
    const id = requestId(error);

    return (
        <Feedback tone="danger">
            <div>
                <strong>
                    {children}
                </strong>

                {id ? (
                    <p className="learner-read-request-id">
                        رقم الطلب: {id}
                    </p>
                ) : null}
            </div>
        </Feedback>
    );
}

function releasedLabel(
    revision: LessonRevision,
) {
    return revision.released_at
        ? 'محررة'
        : 'غير محررة';
}

export function LessonRevisionsPanel({
    version,
    lesson,
    onClose,
}: LessonRevisionsPanelProps) {
    const queryClient =
        useQueryClient();

    const authoringAllowed =
        version.status === 'draft'
        && lesson.status === 'draft';

    const [
        revisionNumber,
        setRevisionNumber,
    ] = useState('');

    const [
        primaryTopicId,
        setPrimaryTopicId,
    ] = useState('');

    const [
        schemaVersion,
        setSchemaVersion,
    ] = useState('1');

    const [
        contentJson,
        setContentJson,
    ] = useState(
        JSON.stringify(
            [],
            null,
            2,
        ),
    );

    const [
        contentError,
        setContentError,
    ] = useState<string | null>(
        null,
    );

    const [
        classifyingRevision,
        setClassifyingRevision,
    ] = useState<LessonRevision | null>(
        null,
    );

    const revisionsQuery =
        useQuery({
            queryKey:
                adminLessonRevisionsKey(
                    lesson.id,
                ),
            queryFn: () =>
                fetchLessonRevisions(
                    lesson.id,
                ),
        });

    const topicsQuery =
        useQuery({
            queryKey:
                adminTopicsKey(
                    version.id,
                ),
            queryFn: () =>
                fetchTopics(
                    version.id,
                ),
        });

    const releasedRevisions =
        useMemo(
            () =>
                revisionsQuery.data
                    ?.filter(
                        (revision) =>
                            revision.released_at
                            !== null,
                    )
                ?? [],
            [
                revisionsQuery.data,
            ],
        );

    async function invalidateRevisions() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminLessonRevisionsKey(
                        lesson.id,
                    ),
            });
    }

    async function invalidateLessons() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminLessonsKey(
                        version.id,
                    ),
            });
    }

    const createMutation =
        useMutation({
            mutationFn: (
                payload: {
                    revisionNumber:
                        number;
                    primaryTopicId:
                        string;
                    schemaVersion:
                        number;
                    content:
                        | unknown[]
                        | Record<
                            string,
                            unknown
                        >;
                },
            ) =>
                createLessonRevision(
                    lesson.id,
                    {
                        revision_number:
                            payload
                                .revisionNumber,
                        primary_topic_id:
                            payload
                                .primaryTopicId,
                        content_payload:
                            payload.content,
                        content_schema_version:
                            payload
                                .schemaVersion,
                    },
                ),
            onSuccess: async () => {
                setRevisionNumber('');
                setPrimaryTopicId('');
                setSchemaVersion('1');
                setContentJson(
                    JSON.stringify(
                        [],
                        null,
                        2,
                    ),
                );
                setContentError(null);

                await invalidateRevisions();
            },
        });

    const releaseMutation =
        useMutation({
            mutationFn: (
                revisionId: string,
            ) =>
                releaseLessonRevision(
                    revisionId,
                ),
            onSuccess:
                invalidateRevisions,
        });

    const publishMutation =
        useMutation({
            mutationFn: (
                revisionId: string,
            ) =>
                publishLesson(
                    lesson.id,
                    revisionId,
                ),
            onSuccess: async () => {
                await invalidateLessons();
                await invalidateRevisions();
            },
        });

    const retireMutation =
        useMutation({
            mutationFn: () =>
                retireLesson(
                    lesson.id,
                ),
            onSuccess:
                invalidateLessons,
        });

    const lifecyclePending =
        releaseMutation.isPending
        || publishMutation.isPending
        || retireMutation.isPending;

    function submitRevision(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !authoringAllowed
            || createMutation.isPending
            || primaryTopicId === ''
        ) {
            return;
        }

        const parsedRevisionNumber =
            Number(revisionNumber);

        const parsedSchemaVersion =
            Number(schemaVersion);

        if (
            !Number.isInteger(
                parsedRevisionNumber,
            )
            || parsedRevisionNumber < 1
            || !Number.isInteger(
                parsedSchemaVersion,
            )
            || parsedSchemaVersion < 1
        ) {
            return;
        }

        let parsedContent:
            unknown;

        try {
            parsedContent =
                JSON.parse(
                    contentJson,
                );
        } catch {
            setContentError(
                'صيغة JSON غير صحيحة.',
            );
            return;
        }

        if (
            parsedContent === null
            || typeof parsedContent
                !== 'object'
        ) {
            setContentError(
                'يجب أن يكون المحتوى JSON array أو object.',
            );
            return;
        }

        setContentError(null);

        createMutation.mutate({
            revisionNumber:
                parsedRevisionNumber,
            primaryTopicId,
            schemaVersion:
                parsedSchemaVersion,
            content:
                parsedContent as
                    | unknown[]
                    | Record<
                        string,
                        unknown
                    >,
        });
    }

    return (
        <Surface
            className="admin-content-revisions"
            elevated
        >
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <p className="foundation-page__eyebrow">
                            Lesson Authoring
                        </p>

                        <h2 className="foundation-card__title">
                            مراجعات: {
                                lesson.title
                            }
                        </h2>

                        <p className="foundation-page__description">
                            إنشاء نسخة محتوى،
                            تحريرها، ثم نشر
                            الدرس باستخدام
                            نسخة محررة.
                        </p>
                    </div>

                    <Button
                        size="sm"
                        variant="secondary"
                        type="button"
                        onClick={
                            onClose
                        }
                    >
                        إغلاق
                    </Button>
                </div>

                {authoringAllowed ? (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitRevision
                        }
                    >
                        <label>
                            رقم المراجعة

                            <input
                                aria-label="رقم مراجعة الدرس"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    revisionNumber
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setRevisionNumber(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            Primary Topic

                            <select
                                aria-label="الموضوع الرئيسي للمراجعة"
                                required
                                value={
                                    primaryTopicId
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setPrimaryTopicId(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            >
                                <option value="">
                                    اختر Topic
                                </option>

                                {topicsQuery.data
                                    ?.map(
                                        (
                                            topic,
                                        ) => (
                                            <option
                                                key={
                                                    topic.id
                                                }
                                                value={
                                                    topic.id
                                                }
                                            >
                                                {
                                                    topic.name
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        <label>
                            Content Schema Version

                            <input
                                aria-label="إصدار مخطط المحتوى"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    schemaVersion
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setSchemaVersion(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            Content Payload

                            <textarea
                                aria-label="محتوى مراجعة الدرس"
                                rows={10}
                                value={
                                    contentJson
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setContentJson(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        {contentError ? (
                            <Feedback tone="danger">
                                {
                                    contentError
                                }
                            </Feedback>
                        ) : null}

                        <Button
                            type="submit"
                            disabled={
                                createMutation
                                    .isPending
                            }
                        >
                            إنشاء Revision
                        </Button>
                    </form>
                ) : (
                    <Feedback>
                        إنشاء مراجعات جديدة
                        متاح فقط للدرس المسودة
                        داخل CurriculumVersion
                        مسودة.
                    </Feedback>
                )}

                {topicsQuery.isError ? (
                    <RevisionFailure
                        error={
                            topicsQuery.error
                        }
                    >
                        تعذر تحميل Topics.
                    </RevisionFailure>
                ) : null}

                {createMutation.isError ? (
                    <RevisionFailure
                        error={
                            createMutation.error
                        }
                    >
                        تعذر إنشاء المراجعة.
                    </RevisionFailure>
                ) : null}

                {releaseMutation.isError ? (
                    <RevisionFailure
                        error={
                            releaseMutation.error
                        }
                    >
                        تعذر تحرير المراجعة.
                    </RevisionFailure>
                ) : null}

                {publishMutation.isError ? (
                    <RevisionFailure
                        error={
                            publishMutation.error
                        }
                    >
                        تعذر نشر الدرس.
                    </RevisionFailure>
                ) : null}

                {retireMutation.isError ? (
                    <RevisionFailure
                        error={
                            retireMutation.error
                        }
                    >
                        تعذر تقاعد الدرس.
                    </RevisionFailure>
                ) : null}

                {revisionsQuery.isPending ? (
                    <p>
                        جار تحميل المراجعات…
                    </p>
                ) : revisionsQuery.isError ? (
                    <RevisionFailure
                        error={
                            revisionsQuery.error
                        }
                    >
                        تعذر تحميل المراجعات.
                    </RevisionFailure>
                ) : revisionsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد مراجعات لهذا
                        الدرس.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {revisionsQuery.data.map(
                            (
                                revision,
                            ) => (
                                <article
                                    key={
                                        revision.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            Revision{' '}
                                            {
                                                revision.revision_number
                                            }
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            {
                                                releasedLabel(
                                                    revision,
                                                )
                                            }
                                            {' · '}
                                            Schema{' '}
                                            {
                                                revision.content_schema_version
                                            }
                                        </p>
                                    </div>

                                    <div className="admin-content-actions">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            type="button"
                                            disabled={
                                                lifecyclePending
                                            }
                                            onClick={() =>
                                                setClassifyingRevision(
                                                    revision,
                                                )
                                            }
                                        >
                                            تصنيف المهارات
                                        </Button>

                                        {authoringAllowed
                                        && revision.released_at
                                            === null ? (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                type="button"
                                                disabled={
                                                    lifecyclePending
                                                }
                                                onClick={() =>
                                                    releaseMutation
                                                        .mutate(
                                                            revision.id,
                                                        )
                                                }
                                            >
                                                تحرير Revision
                                            </Button>
                                        ) : null}

                                        {lesson.status
                                            === 'draft'
                                        && version.status
                                            === 'draft'
                                        && revision.released_at
                                            !== null ? (
                                            <Button
                                                size="sm"
                                                type="button"
                                                disabled={
                                                    lifecyclePending
                                                }
                                                onClick={() =>
                                                    publishMutation
                                                        .mutate(
                                                            revision.id,
                                                        )
                                                }
                                            >
                                                نشر بهذه المراجعة
                                            </Button>
                                        ) : null}
                                    </div>
                                </article>
                            ),
                        )}
                    </div>
                )}

                {lesson.status === 'published' ? (
                    <div className="admin-content-actions">
                        <Feedback tone="success">
                            الدرس منشور.
                            المراجعة المنشورة:{' '}
                            {
                                lesson.published_revision_id
                            }
                        </Feedback>

                        <Button
                            size="sm"
                            variant="secondary"
                            type="button"
                            disabled={
                                lifecyclePending
                            }
                            onClick={() =>
                                retireMutation
                                    .mutate()
                            }
                        >
                            تقاعد الدرس
                        </Button>
                    </div>
                ) : null}

                {lesson.status === 'draft'
                && releasedRevisions.length
                    === 0
                && revisionsQuery.data
                && revisionsQuery.data.length
                    > 0 ? (
                    <Feedback>
                        يجب تحرير Revision
                        قبل نشر الدرس.
                    </Feedback>
                ) : null}

                {classifyingRevision ? (
                    <RevisionSkillsPanel
                        version={version}
                        revision={
                            classifyingRevision
                        }
                        onClose={() =>
                            setClassifyingRevision(
                                null,
                            )
                        }
                    />
                ) : null}
            </div>
        </Surface>
    );
}

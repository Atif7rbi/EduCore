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

function requestId(error: unknown) {
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
                <strong>{children}</strong>
                {id ? (
                    <p className="learner-read-request-id">
                        رقم الطلب: {id}
                    </p>
                ) : null}
            </div>
        </Feedback>
    );
}

function approvalLabel(revision: LessonRevision) {
    return revision.released_at
        ? 'معتمدة'
        : 'غير معتمدة';
}

function textPayload(value: string) {
    return {
        blocks: value
            .split(/\n\s*\n/)
            .map((paragraph) => paragraph.trim())
            .filter(Boolean)
            .map((paragraph) => ({
                type: 'text',
                value: paragraph,
            })),
    };
}

export function LessonRevisionsPanel({
    version,
    lesson,
    onClose,
}: LessonRevisionsPanelProps) {
    const queryClient = useQueryClient();
    const authoringAllowed =
        version.status === 'draft'
        && lesson.status === 'draft';

    const [primaryTopicId, setPrimaryTopicId] = useState('');
    const [contentText, setContentText] = useState('');
    const [classifyingRevision, setClassifyingRevision] =
        useState<LessonRevision | null>(null);

    const revisionsQuery = useQuery({
        queryKey: adminLessonRevisionsKey(lesson.id),
        queryFn: () => fetchLessonRevisions(lesson.id),
    });

    const topicsQuery = useQuery({
        queryKey: adminTopicsKey(version.id),
        queryFn: () => fetchTopics(version.id),
    });

    const nextRevisionNumber = useMemo(() => {
        const numbers = revisionsQuery.data?.map(
            (revision) => revision.revision_number,
        ) ?? [];

        return numbers.length === 0
            ? 1
            : Math.max(...numbers) + 1;
    }, [revisionsQuery.data]);

    const approvedRevisions = useMemo(
        () =>
            revisionsQuery.data?.filter(
                (revision) => revision.released_at !== null,
            ) ?? [],
        [revisionsQuery.data],
    );

    async function invalidateRevisions() {
        await queryClient.invalidateQueries({
            queryKey: adminLessonRevisionsKey(lesson.id),
        });
    }

    async function invalidateLessons() {
        await queryClient.invalidateQueries({
            queryKey: adminLessonsKey(version.id),
        });
    }

    const createMutation = useMutation({
        mutationFn: () =>
            createLessonRevision(lesson.id, {
                revision_number: nextRevisionNumber,
                primary_topic_id: primaryTopicId,
                content_payload: textPayload(contentText),
                content_schema_version: 1,
            }),
        onSuccess: async () => {
            setPrimaryTopicId('');
            setContentText('');
            await invalidateRevisions();
        },
    });

    const releaseMutation = useMutation({
        mutationFn: (revisionId: string) =>
            releaseLessonRevision(revisionId),
        onSuccess: invalidateRevisions,
    });

    const publishMutation = useMutation({
        mutationFn: (revisionId: string) =>
            publishLesson(lesson.id, revisionId),
        onSuccess: async () => {
            await invalidateLessons();
            await invalidateRevisions();
        },
    });

    const retireMutation = useMutation({
        mutationFn: () => retireLesson(lesson.id),
        onSuccess: invalidateLessons,
    });

    const lifecyclePending =
        releaseMutation.isPending
        || publishMutation.isPending
        || retireMutation.isPending;

    function submitRevision(event: FormEvent) {
        event.preventDefault();

        if (
            !authoringAllowed
            || createMutation.isPending
            || primaryTopicId === ''
            || contentText.trim() === ''
        ) {
            return;
        }

        createMutation.mutate();
    }

    return (
        <Surface className="admin-content-revisions" elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h2 className="foundation-card__title">
                            {lesson.title}
                        </h2>
                        <p className="foundation-page__description">
                            اكتب محتوى الدرس، اربط المهارات، اعتمد النسخة ثم انشرها للطلاب عند الجاهزية.
                        </p>
                    </div>

                    <Button
                        size="sm"
                        variant="secondary"
                        type="button"
                        onClick={onClose}
                    >
                        العودة إلى الدروس
                    </Button>
                </div>

                {authoringAllowed ? (
                    <form className="admin-content-form" onSubmit={submitRevision}>
                        <div>
                            <strong>نسخة جديدة من المحتوى</strong>
                            <p className="admin-content-list__meta">
                                سيحفظ النظام هذه النسخة تلقائيًا برقم {nextRevisionNumber}.
                            </p>
                        </div>

                        <label>
                            الموضوع الرئيسي
                            <select
                                aria-label="الموضوع الرئيسي للنسخة"
                                required
                                value={primaryTopicId}
                                onChange={(event) => setPrimaryTopicId(event.target.value)}
                            >
                                <option value="">اختر الموضوع</option>
                                {topicsQuery.data?.map((topic) => (
                                    <option key={topic.id} value={topic.id}>
                                        {topic.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label>
                            محتوى الدرس
                            <textarea
                                aria-label="محتوى الدرس"
                                rows={12}
                                required
                                placeholder="اكتب محتوى الدرس هنا. افصل بين الفقرات بسطر فارغ."
                                value={contentText}
                                onChange={(event) => setContentText(event.target.value)}
                            />
                        </label>

                        <Feedback>
                            يمكنك كتابة النص بصورة طبيعية؛ يتولى النظام تنسيق المحتوى للطالب دون الحاجة إلى أي صيغة تقنية.
                        </Feedback>

                        <Button
                            type="submit"
                            disabled={
                                createMutation.isPending
                                || primaryTopicId === ''
                                || contentText.trim() === ''
                            }
                        >
                            حفظ نسخة جديدة
                        </Button>
                    </form>
                ) : (
                    <Feedback>
                        لا يمكن إنشاء نسخة محتوى جديدة لهذا الدرس في حالته الحالية.
                    </Feedback>
                )}

                {topicsQuery.isError ? (
                    <RevisionFailure error={topicsQuery.error}>
                        تعذر تحميل الموضوعات.
                    </RevisionFailure>
                ) : null}
                {createMutation.isError ? (
                    <RevisionFailure error={createMutation.error}>
                        تعذر حفظ نسخة المحتوى.
                    </RevisionFailure>
                ) : null}
                {releaseMutation.isError ? (
                    <RevisionFailure error={releaseMutation.error}>
                        تعذر اعتماد النسخة.
                    </RevisionFailure>
                ) : null}
                {publishMutation.isError ? (
                    <RevisionFailure error={publishMutation.error}>
                        تعذر نشر الدرس.
                    </RevisionFailure>
                ) : null}
                {retireMutation.isError ? (
                    <RevisionFailure error={retireMutation.error}>
                        تعذر إيقاف الدرس.
                    </RevisionFailure>
                ) : null}

                <div>
                    <h3 className="foundation-card__title">
                        نسخ المحتوى
                    </h3>
                </div>

                {revisionsQuery.isPending ? (
                    <p>جار تحميل نسخ المحتوى…</p>
                ) : revisionsQuery.isError ? (
                    <RevisionFailure error={revisionsQuery.error}>
                        تعذر تحميل نسخ المحتوى.
                    </RevisionFailure>
                ) : revisionsQuery.data.length === 0 ? (
                    <Feedback>
                        لم تُحفظ أي نسخة محتوى لهذا الدرس بعد.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {revisionsQuery.data.map((revision) => (
                            <article
                                key={revision.id}
                                className="admin-content-list__item"
                            >
                                <div>
                                    <strong>
                                        النسخة {revision.revision_number}
                                    </strong>
                                    <p className="admin-content-list__meta">
                                        {approvalLabel(revision)}
                                        {lesson.published_revision_id === revision.id
                                            ? ' · النسخة المنشورة حاليًا'
                                            : ''}
                                    </p>
                                </div>

                                <div className="admin-content-actions">
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        type="button"
                                        disabled={lifecyclePending}
                                        onClick={() => setClassifyingRevision(revision)}
                                    >
                                        ربط المهارات
                                    </Button>

                                    {authoringAllowed && revision.released_at === null ? (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            type="button"
                                            disabled={lifecyclePending}
                                            onClick={() => releaseMutation.mutate(revision.id)}
                                        >
                                            اعتماد النسخة
                                        </Button>
                                    ) : null}

                                    {lesson.status === 'draft'
                                    && version.status === 'draft'
                                    && revision.released_at !== null ? (
                                        <Button
                                            size="sm"
                                            type="button"
                                            disabled={lifecyclePending}
                                            onClick={() => publishMutation.mutate(revision.id)}
                                        >
                                            نشر الدرس بهذه النسخة
                                        </Button>
                                    ) : null}
                                </div>
                            </article>
                        ))}
                    </div>
                )}

                {lesson.status === 'published' ? (
                    <div className="foundation-stack">
                        <Feedback tone="success">
                            هذا الدرس منشور للطلاب بالنسخة المعتمدة الحالية.
                        </Feedback>
                        <div className="admin-content-actions">
                            <Button
                                size="sm"
                                variant="secondary"
                                type="button"
                                disabled={lifecyclePending}
                                onClick={() => retireMutation.mutate()}
                            >
                                إيقاف الدرس
                            </Button>
                        </div>
                    </div>
                ) : null}

                {lesson.status === 'draft'
                && approvedRevisions.length === 0
                && revisionsQuery.data
                && revisionsQuery.data.length > 0 ? (
                    <Feedback>
                        اعتمد إحدى نسخ المحتوى قبل نشر الدرس.
                    </Feedback>
                ) : null}

                {classifyingRevision ? (
                    <RevisionSkillsPanel
                        version={version}
                        revision={classifyingRevision}
                        onClose={() => setClassifyingRevision(null)}
                    />
                ) : null}
            </div>
        </Surface>
    );
}

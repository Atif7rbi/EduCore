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

function contentText(revision: LessonRevision | null) {
    if (!revision) return '';

    const payload = revision.content_payload;
    if (!payload || Array.isArray(payload) || typeof payload !== 'object') {
        return '';
    }

    const blocks = (payload as Record<string, unknown>).blocks;
    if (!Array.isArray(blocks)) return '';

    return blocks
        .map((block) => {
            if (!block || typeof block !== 'object') return '';
            const record = block as Record<string, unknown>;
            const value = record.value ?? record.text;
            return typeof value === 'string' ? value : '';
        })
        .filter(Boolean)
        .join('\n\n');
}

export function LessonRevisionsPanel({
    version,
    lesson,
    onClose,
}: LessonRevisionsPanelProps) {
    const queryClient = useQueryClient();
    const authoringAllowed =
        version.status === 'draft'
        && lesson.status !== 'retired';

    const [showEditor, setShowEditor] = useState(false);
    const [primaryTopicId, setPrimaryTopicId] = useState('');
    const [contentTextValue, setContentTextValue] = useState('');
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

    const publishedRevision = useMemo(
        () => revisionsQuery.data?.find(
            (revision) => revision.id === lesson.published_revision_id,
        ) ?? null,
        [lesson.published_revision_id, revisionsQuery.data],
    );

    const pendingRevision = useMemo(() => {
        const candidates = (revisionsQuery.data ?? [])
            .filter((revision) => revision.id !== lesson.published_revision_id)
            .sort((a, b) => b.revision_number - a.revision_number);

        return candidates[0] ?? null;
    }, [lesson.published_revision_id, revisionsQuery.data]);

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
        mutationFn: () => createLessonRevision(lesson.id, {
            revision_number: nextRevisionNumber,
            primary_topic_id: primaryTopicId,
            content_payload: textPayload(contentTextValue),
            content_schema_version: 1,
        }),
        onSuccess: async () => {
            setShowEditor(false);
            setPrimaryTopicId('');
            setContentTextValue('');
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

    function startEditing() {
        const source = pendingRevision ?? publishedRevision;
        setPrimaryTopicId(source?.primary_topic_id ?? '');
        setContentTextValue(contentText(source));
        setShowEditor(true);
    }

    function submitRevision(event: FormEvent) {
        event.preventDefault();

        if (
            !authoringAllowed
            || createMutation.isPending
            || primaryTopicId === ''
            || contentTextValue.trim() === ''
        ) return;

        createMutation.mutate();
    }

    const publishedText = contentText(publishedRevision);
    const pendingText = contentText(pendingRevision);

    return (
        <Surface className="admin-content-revisions" elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h2 className="foundation-card__title">
                            محتوى الدرس
                        </h2>
                        <p className="foundation-page__description">
                            حرر المحتوى واربط المهارات ثم انشر التعديلات عندما تصبح جاهزة للطلاب.
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

                {revisionsQuery.isPending ? (
                    <p>جار تحميل محتوى الدرس…</p>
                ) : revisionsQuery.isError ? (
                    <RevisionFailure error={revisionsQuery.error}>
                        تعذر تحميل محتوى الدرس.
                    </RevisionFailure>
                ) : (
                    <>
                        {lesson.status === 'published' && publishedRevision ? (
                            <section className="foundation-stack">
                                <div>
                                    <h3 className="foundation-card__title">
                                        المحتوى المنشور
                                    </h3>
                                    <p className="admin-content-list__meta">
                                        هذا هو المحتوى الذي يراه الطلاب حاليًا.
                                    </p>
                                </div>
                                <div className="admin-lesson-content-preview">
                                    {publishedText || 'لا يوجد نص قابل للعرض.'}
                                </div>
                            </section>
                        ) : null}

                        {lesson.status === 'draft'
                        && !pendingRevision
                        && !publishedRevision ? (
                            <Feedback>
                                لم يُضف محتوى لهذا الدرس بعد.
                            </Feedback>
                        ) : null}

                        {pendingRevision ? (
                            <section className="foundation-stack admin-lesson-pending-content">
                                <div>
                                    <h3 className="foundation-card__title">
                                        تعديلات غير منشورة
                                    </h3>
                                    <p className="admin-content-list__meta">
                                        {pendingRevision.released_at
                                            ? 'التعديلات معتمدة وجاهزة للنشر.'
                                            : 'التعديلات محفوظة ويمكن مراجعتها وربط المهارات قبل اعتمادها.'}
                                    </p>
                                </div>

                                {pendingText ? (
                                    <div className="admin-lesson-content-preview">
                                        {pendingText}
                                    </div>
                                ) : null}

                                <div className="admin-content-actions">
                                    {pendingRevision.released_at === null ? (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                type="button"
                                                disabled={lifecyclePending}
                                                onClick={() => setClassifyingRevision(pendingRevision)}
                                            >
                                                ربط المهارات
                                            </Button>
                                            <Button
                                                size="sm"
                                                type="button"
                                                disabled={lifecyclePending}
                                                onClick={() => releaseMutation.mutate(pendingRevision.id)}
                                            >
                                                اعتماد التعديلات
                                            </Button>
                                        </>
                                    ) : (
                                        <Button
                                            size="sm"
                                            type="button"
                                            disabled={lifecyclePending}
                                            onClick={() => publishMutation.mutate(pendingRevision.id)}
                                        >
                                            {lesson.status === 'published'
                                                ? 'نشر التعديلات'
                                                : 'نشر الدرس'}
                                        </Button>
                                    )}
                                </div>
                            </section>
                        ) : null}

                        {authoringAllowed && !showEditor ? (
                            <div className="admin-content-actions">
                                <Button type="button" onClick={startEditing}>
                                    {publishedRevision || pendingRevision
                                        ? 'تعديل محتوى الدرس'
                                        : 'إضافة محتوى الدرس'}
                                </Button>
                            </div>
                        ) : null}

                        {showEditor && authoringAllowed ? (
                            <form className="admin-content-form" onSubmit={submitRevision}>
                                <h3 className="foundation-card__title">
                                    تحرير محتوى الدرس
                                </h3>
                                <label>
                                    الوحدة الرئيسية
                                    <select
                                        aria-label="الوحدة الرئيسية للدرس"
                                        required
                                        value={primaryTopicId}
                                        onChange={(event) => setPrimaryTopicId(event.target.value)}
                                    >
                                        <option value="">اختر الوحدة</option>
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
                                        value={contentTextValue}
                                        onChange={(event) => setContentTextValue(event.target.value)}
                                    />
                                </label>

                                <div className="admin-content-actions">
                                    <Button
                                        type="submit"
                                        disabled={
                                            createMutation.isPending
                                            || primaryTopicId === ''
                                            || contentTextValue.trim() === ''
                                        }
                                    >
                                        حفظ التعديلات
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setShowEditor(false)}
                                    >
                                        إلغاء
                                    </Button>
                                </div>
                            </form>
                        ) : null}
                    </>
                )}

                {topicsQuery.isError ? (
                    <RevisionFailure error={topicsQuery.error}>
                        تعذر تحميل الوحدات.
                    </RevisionFailure>
                ) : null}
                {createMutation.isError ? (
                    <RevisionFailure error={createMutation.error}>
                        تعذر حفظ تعديلات المحتوى.
                    </RevisionFailure>
                ) : null}
                {releaseMutation.isError ? (
                    <RevisionFailure error={releaseMutation.error}>
                        تعذر اعتماد التعديلات.
                    </RevisionFailure>
                ) : null}
                {publishMutation.isError ? (
                    <RevisionFailure error={publishMutation.error}>
                        تعذر نشر التعديلات.
                    </RevisionFailure>
                ) : null}
                {retireMutation.isError ? (
                    <RevisionFailure error={retireMutation.error}>
                        تعذر إيقاف النشر.
                    </RevisionFailure>
                ) : null}

                {lesson.status === 'published' ? (
                    <div className="foundation-stack">
                        <Feedback tone="success">
                            الدرس منشور حاليًا للطلاب.
                        </Feedback>
                        <div className="admin-content-actions">
                            <Button
                                size="sm"
                                variant="secondary"
                                type="button"
                                disabled={lifecyclePending}
                                onClick={() => retireMutation.mutate()}
                            >
                                إيقاف النشر
                            </Button>
                        </div>
                    </div>
                ) : null}

                {lesson.status === 'retired' ? (
                    <Feedback>
                        هذا الدرس موقوف ولا يمكن تعديل محتواه.
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

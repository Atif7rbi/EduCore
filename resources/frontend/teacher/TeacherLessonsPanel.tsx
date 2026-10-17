import {
    FormEvent,
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
    EduCoreApiError,
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../ui';
import {
    createTeacherLesson,
    fetchTeacherLessonRevisions,
    fetchTeacherLessons,
    publishTeacherLesson,
    teacherLessonRevisionsKey,
    teacherLessonsKey,
    unpublishTeacherLesson,
    updateTeacherLesson,
} from './api';
import {
    TeacherLessonRevisionEditor,
} from './TeacherLessonRevisionEditor';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherLesson,
} from './types';

interface TeacherLessonsPanelProps {
    authenticatedUserId: string;
    context: TeacherContext;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
}

function errorMessage(error: unknown): string {
    return error instanceof EduCoreApiError
        ? error.message
        : 'تعذر إكمال عملية الدرس.';
}

function isUnavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

export function TeacherLessonsPanel({
    authenticatedUserId,
    context,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
}: TeacherLessonsPanelProps) {
    const queryClient = useQueryClient();
    const editable = context.versionStatus === 'draft';
    const [createOpen, setCreateOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [displayOrder, setDisplayOrder] = useState('0');
    const [editingLessonId, setEditingLessonId] = useState<string | null>(null);
    const [selectedLessonId, setSelectedLessonId] = useState<string | null>(null);
    const [editorDirty, setEditorDirty] = useState(false);
    const [publishTargetId, setPublishTargetId] = useState<string | null>(null);
    const [publishedRevisionId, setPublishedRevisionId] = useState('');

    const key = teacherLessonsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
    );
    const lessonsQuery = useQuery({
        queryKey: key,
        queryFn: ({ signal }) => fetchTeacherLessons(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const publishableRevisions = useQuery({
        queryKey: teacherLessonRevisionsKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            publishTargetId ?? '',
        ),
        queryFn: ({ signal }) => fetchTeacherLessonRevisions(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            publishTarget!.id,
            signal,
        ),
        enabled: publishTargetId !== null,
    });
    const lessons = useMemo(
        () => (lessonsQuery.data ?? []).filter(
            (lesson) => lesson.curriculum_version_id === context.curriculumVersionId,
        ),
        [context.curriculumVersionId, lessonsQuery.data],
    );
    const selectedLesson = useMemo(
        () => lessons.find((lesson) => lesson.id === selectedLessonId) ?? null,
        [lessons, selectedLessonId],
    );
    const publishTarget = useMemo(
        () => lessons.find((lesson) => lesson.id === publishTargetId) ?? null,
        [lessons, publishTargetId],
    );
    const editingLesson = useMemo(
        () => lessons.find((lesson) => lesson.id === editingLessonId) ?? null,
        [editingLessonId, lessons],
    );
    const dirty = createOpen
        || editingLesson !== null
        || editorDirty
        || title !== ''
        || description !== ''
        || displayOrder !== '0';

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (isUnavailable(lessonsQuery.error)) {
            onContextUnavailable();
        }
    }, [lessonsQuery.error, onContextUnavailable]);

    useEffect(() => {
        if (lessonsQuery.isSuccess && selectedLessonId !== null && selectedLesson === null) {
            setSelectedLessonId(null);
        }

        if (lessonsQuery.isSuccess && publishTargetId !== null && publishTarget === null) {
            setPublishTargetId(null);
            setPublishedRevisionId('');
        }

        if (editingLessonId !== null && editingLesson === null) {
            setEditingLessonId(null);
            setTitle('');
            setDescription('');
            setDisplayOrder('0');
        }
    }, [editingLesson, editingLessonId, lessonsQuery.isSuccess, publishTarget, publishTargetId, selectedLesson, selectedLessonId]);

    async function refresh() {
        await queryClient.invalidateQueries({ queryKey: key });
    }

    async function handleError(error: unknown) {
        if (isUnavailable(error)) {
            onContextUnavailable();

            return;
        }

        if (error instanceof EduCoreApiError && error.status === 409) {
            await onLifecycleConflict();
            await refresh();
        }
    }

    const create = useMutation({
        mutationFn: () => createTeacherLesson(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            {
                title: title.trim(),
                description: description.trim() || null,
                display_order: Number(displayOrder),
            },
        ),
        onSuccess: async () => {
            setCreateOpen(false);
            setTitle('');
            setDescription('');
            setDisplayOrder('0');
            await refresh();
        },
        onError: handleError,
    });
    const update = useMutation({
        mutationFn: () => updateTeacherLesson(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            editingLesson!.id,
            {
                title: title.trim(),
                description: description.trim() || null,
                display_order: Number(displayOrder),
            },
        ),
        onSuccess: async () => {
            setEditingLessonId(null);
            setTitle('');
            setDescription('');
            setDisplayOrder('0');
            await refresh();
        },
        onError: handleError,
    });
    const publish = useMutation({
        mutationFn: () => publishTeacherLesson(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            publishTarget!.id,
            publishedRevisionId,
        ),
        onSuccess: async () => {
            setPublishTargetId(null);
            setPublishedRevisionId('');
            await refresh();
        },
        onError: handleError,
    });
    const unpublish = useMutation({
        mutationFn: (lessonId: string) => unpublishTeacherLesson(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            lessonId,
        ),
        onSuccess: refresh,
        onError: handleError,
    });

    function startEditing(lesson: TeacherLesson) {
        setEditingLessonId(lesson.id);
        setTitle(lesson.title);
        setDescription(lesson.description ?? '');
        setDisplayOrder(String(lesson.display_order));
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || title.trim() === ''
            || !Number.isInteger(Number(displayOrder))
            || Number(displayOrder) < 0
        ) {
            return;
        }

        if (editingLesson) {
            update.mutate();
        } else {
            create.mutate();
        }
    }

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">الدروس ونسخها</h2>
                    <p className="foundation-page__description">
                        أنشئ مسودات الدروس ثم حرّر النسخ وأصدرها قبل النشر.
                    </p>
                </div>

                {!editable ? <Feedback>هذا الإصدار للقراءة فقط.</Feedback> : (
                    <Button type="button" onClick={() => {
                        setCreateOpen((value) => !value);
                        setEditingLessonId(null);
                    }}>
                        {createOpen ? 'إغلاق نموذج الدرس' : 'إضافة درس'}
                    </Button>
                )}

                {(createOpen || editingLesson) && editable ? (
                    <form className="admin-content-form" onSubmit={submit}>
                        <label>العنوان<input aria-label="عنوان الدرس" value={title} onChange={(event) => setTitle(event.target.value)} required maxLength={255} /></label>
                        <label>الوصف<textarea aria-label="وصف الدرس" value={description} onChange={(event) => setDescription(event.target.value)} /></label>
                        <label>ترتيب العرض<input aria-label="ترتيب عرض الدرس" value={displayOrder} type="number" min="0" step="1" onChange={(event) => setDisplayOrder(event.target.value)} required /></label>
                        {(create.isError || update.isError) ? <Feedback tone="danger">{errorMessage(create.error ?? update.error)}</Feedback> : null}
                        <Button type="submit" disabled={create.isPending || update.isPending}>{editingLesson ? 'حفظ الدرس' : 'إنشاء الدرس'}</Button>
                        {editingLesson ? <Button type="button" variant="secondary" onClick={() => {
                            setEditingLessonId(null);
                            setTitle('');
                            setDescription('');
                            setDisplayOrder('0');
                        }}>إلغاء</Button> : null}
                    </form>
                ) : null}

                {lessonsQuery.isPending ? <p>جار تحميل الدروس…</p> : null}
                {lessonsQuery.isError ? <Feedback tone="danger">{errorMessage(lessonsQuery.error)}</Feedback> : null}
                {lessonsQuery.isSuccess && lessons.length === 0 ? <Feedback>لا توجد دروس في هذا الإصدار.</Feedback> : null}

                {lessons.map((lesson) => (
                    <article className="admin-content-list__item" key={lesson.id}>
                        <div>
                            <strong>{lesson.title}</strong>
                            <p className="admin-content-list__meta">الحالة: {lesson.status} · ترتيب العرض: {lesson.display_order}</p>
                            {lesson.description ? <p>{lesson.description}</p> : null}
                        </div>
                        <div className="foundation-stack">
                            <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedLessonId(lesson.id)}>
                                {selectedLessonId === lesson.id ? 'إخفاء النسخ' : 'النسخ'}
                            </Button>
                            {editable ? <Button type="button" size="sm" variant="secondary" onClick={() => startEditing(lesson)}>تعديل</Button> : null}
                            {editable && lesson.status !== 'published' ? <Button type="button" size="sm" onClick={() => {
                                setPublishTargetId(lesson.id);
                                setPublishedRevisionId('');
                            }}>نشر</Button> : null}
                            {editable && lesson.status === 'published' ? <Button type="button" size="sm" variant="secondary" onClick={() => {
                                if (window.confirm('هل تريد إلغاء نشر الدرس؟')) {
                                    unpublish.mutate(lesson.id);
                                }
                            }}>إلغاء النشر</Button> : null}
                        </div>
                    </article>
                ))}

                {publishTarget && editable ? (
                    <form className="admin-content-form" onSubmit={(event) => {
                        event.preventDefault();
                        if (publishedRevisionId !== '' && window.confirm('هل تريد نشر الدرس بهذه النسخة؟')) {
                            publish.mutate();
                        }
                    }}>
                        <label>النسخة المُصدرة<select aria-label="نسخة الدرس المنشورة" value={publishedRevisionId} onChange={(event) => setPublishedRevisionId(event.target.value)}>
                            <option value="">اختر نسخة مُصدرة</option>
                            {(publishableRevisions.data ?? []).filter((revision) => revision.lesson_id === publishTarget.id && revision.curriculum_version_id === context.curriculumVersionId && revision.released_at !== null).map((revision) => <option key={revision.id} value={revision.id}>نسخة {revision.revision_number}</option>)}
                        </select></label>
                        {publishableRevisions.isError || publish.isError ? <Feedback tone="danger">{errorMessage(publishableRevisions.error ?? publish.error)}</Feedback> : null}
                        <Button type="submit" disabled={publish.isPending || publishedRevisionId === ''}>تأكيد النشر</Button>
                        <Button type="button" variant="secondary" onClick={() => setPublishTargetId(null)}>إلغاء</Button>
                    </form>
                ) : null}

                {selectedLesson ? (
                    <TeacherLessonRevisionEditor
                        authenticatedUserId={authenticatedUserId}
                        context={context}
                        lesson={selectedLesson}
                        onContextUnavailable={onContextUnavailable}
                        onDirtyChange={setEditorDirty}
                        onLifecycleConflict={onLifecycleConflict}
                    />
                ) : null}
            </div>
        </Surface>
    );
}

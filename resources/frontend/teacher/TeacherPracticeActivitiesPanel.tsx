import {
    FormEvent,
    useEffect,
    useMemo,
    useRef,
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
    createTeacherPracticeActivity,
    fetchTeacherLessons,
    fetchTeacherPracticeActivities,
    setTeacherPracticeActivityStatus,
    teacherLessonsKey,
    teacherPracticeActivitiesKey,
    updateTeacherPracticeActivity,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import {
    TeacherPracticeMembershipEditor,
} from './TeacherPracticeMembershipEditor';
import type {
    TeacherPracticeActivity,
} from './types';

interface Props {
    authenticatedUserId: string;
    context: TeacherContext;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
}

function unavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

function message(error: unknown): string {
    if (!(error instanceof EduCoreApiError)) {
        return 'تعذر إكمال عملية التدريب.';
    }

    const details = Object.values(error.details ?? {}).flat();

    return details.length > 0 ? details.join(' ') : error.message;
}

export function TeacherPracticeActivitiesPanel({
    authenticatedUserId,
    context,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
}: Props) {
    const queryClient = useQueryClient();
    const mountedRef = useRef(false);

    useEffect(() => {
        mountedRef.current = true;

        return () => {
            mountedRef.current = false;
        };
    }, []);

    const editable = context.versionStatus === 'draft';
    const [createOpen, setCreateOpen] = useState(false);
    const [editingPracticeId, setEditingPracticeId] = useState<string | null>(null);
    const [selectedPracticeId, setSelectedPracticeId] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [lessonId, setLessonId] = useState('');
    const [membershipDirty, setMembershipDirty] = useState(false);
    const key = teacherPracticeActivitiesKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
    );
    const practicesQuery = useQuery({
        queryKey: key,
        queryFn: ({ signal }) => fetchTeacherPracticeActivities(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const lessonsQuery = useQuery({
        queryKey: teacherLessonsKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
        ),
        queryFn: ({ signal }) => fetchTeacherLessons(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const practices = useMemo(
        () => (practicesQuery.data ?? []).filter(
            (practice) => practice.curriculum_version_id
                === context.curriculumVersionId,
        ),
        [context.curriculumVersionId, practicesQuery.data],
    );
    const lessons = useMemo(
        () => (lessonsQuery.data ?? []).filter(
            (lesson) => lesson.curriculum_version_id === context.curriculumVersionId,
        ),
        [context.curriculumVersionId, lessonsQuery.data],
    );
    const selectedPractice = practices.find(
        (practice) => practice.id === selectedPracticeId,
    ) ?? null;
    const editingPractice = practices.find(
        (practice) => practice.id === editingPracticeId,
    ) ?? null;
    const dirty = createOpen
        || editingPractice !== null
        || name !== ''
        || description !== ''
        || lessonId !== ''
        || membershipDirty;

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        const error = practicesQuery.error ?? lessonsQuery.error;
        if (unavailable(error)) {
            setSelectedPracticeId(null);
            setEditingPracticeId(null);
            onContextUnavailable();
        }
    }, [lessonsQuery.error, onContextUnavailable, practicesQuery.error]);

    useEffect(() => {
        if (
            practicesQuery.isSuccess
            && selectedPracticeId !== null
            && selectedPractice === null
        ) {
            setSelectedPracticeId(null);
            setMembershipDirty(false);
        }

        if (
            practicesQuery.isSuccess
            && editingPracticeId !== null
            && editingPractice === null
        ) {
            clearForm();
        }
    });


    function selectPractice(resourceId: string) {
        const nextId = selectedPracticeId === resourceId
            ? null
            : resourceId;

        if (nextId === selectedPracticeId) return;

        if (
            membershipDirty
            && !window.confirm(
                'لديك تغييرات غير محفوظة في عضويات التدريب. هل تريد تجاهلها؟',
            )
        ) {
            return;
        }

        setSelectedPracticeId(nextId);
    }

    async function refresh() {
        await queryClient.invalidateQueries({ queryKey: key });
    }

    async function handleError(error: unknown) {
        if (!mountedRef.current) return;

        if (unavailable(error)) {
            clearForm();
            setSelectedPracticeId(null);
            onContextUnavailable();

            return;
        }

        if (error instanceof EduCoreApiError && error.status === 409) {
            await onLifecycleConflict();
            await refresh();
        }
    }

    function clearForm() {
        setCreateOpen(false);
        setEditingPracticeId(null);
        setName('');
        setDescription('');
        setLessonId('');
    }

    const create = useMutation({
        mutationFn: () => createTeacherPracticeActivity(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            {
                lesson_id: lessonId || null,
                name: name.trim(),
                description: description.trim() || null,
            },
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            clearForm();
            await refresh();
        },
        onError: handleError,
    });
    const update = useMutation({
        mutationFn: () => updateTeacherPracticeActivity(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            editingPractice!.id,
            {
                lesson_id: lessonId || null,
                name: name.trim(),
                description: description.trim() || null,
            },
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            clearForm();
            await refresh();
        },
        onError: handleError,
    });
    const lifecycle = useMutation({
        mutationFn: ({
            practice,
            status,
        }: {
            practice: TeacherPracticeActivity;
            status: 'active' | 'archived';
        }) => setTeacherPracticeActivityStatus(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            practice.id,
            status,
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            await refresh();
        },
        onError: handleError,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (!editable || name.trim() === '') return;
        if (editingPractice) update.mutate();
        else create.mutate();
    }


    function hasUnsavedPracticeFormChanges(): boolean {
        if (createOpen) {
            return name !== ''
                || description !== ''
                || lessonId !== '';
        }

        if (editingPractice) {
            return name !== editingPractice.name
                || description !== (editingPractice.description ?? '')
                || lessonId !== (editingPractice.lesson_id ?? '');
        }

        return false;
    }

    function startEditing(practice: TeacherPracticeActivity) {
        if (practice.status !== 'archived') return;
        if (editingPracticeId === practice.id) return;

        if (
            hasUnsavedPracticeFormChanges()
            && !window.confirm(
                'لديك تغييرات غير محفوظة في التدريب الحالي. هل تريد تجاهلها؟',
            )
        ) {
            return;
        }

        setCreateOpen(false);
        setEditingPracticeId(practice.id);
        setName(practice.name);
        setDescription(practice.description ?? '');
        setLessonId(practice.lesson_id ?? '');
    }

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">أنشطة التدريب</h2>
                    <p className="foundation-page__description">
                        أنشئ تدريبًا مؤرشفًا وأضف نسخ تقييم مُصدرة قبل تفعيله.
                    </p>
                </div>

                {!editable ? <Feedback>أنشطة هذا الإصدار للقراءة فقط.</Feedback> : (
                    <Button type="button" onClick={() => {
                        if (createOpen) {
                            if (hasUnsavedPracticeFormChanges() && !window.confirm(
                                'هل تريد إغلاق النموذج وتجاهل مدخلاته؟',
                            )) return;
                            clearForm();
                        } else {
                            if (hasUnsavedPracticeFormChanges() && !window.confirm(
                                'هل تريد تجاهل التعديلات الحالية؟',
                            )) return;
                            clearForm();
                            setCreateOpen(true);
                        }
                    }}>
                        {createOpen ? 'إغلاق نموذج التدريب' : 'إضافة تدريب'}
                    </Button>
                )}

                {(createOpen || editingPractice) && editable ? (
                    <form className="admin-content-form" onSubmit={submit}>
                        <label>
                            اسم التدريب
                            <input aria-label="اسم التدريب" value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} />
                        </label>
                        <label>
                            الوصف
                            <textarea aria-label="وصف التدريب" value={description} onChange={(event) => setDescription(event.target.value)} />
                        </label>
                        <label>
                            الدرس الاختياري
                            <select aria-label="درس التدريب" value={lessonId} onChange={(event) => setLessonId(event.target.value)}>
                                <option value="">بدون درس</option>
                                {lessons.map((lesson) => <option key={lesson.id} value={lesson.id}>{lesson.title}</option>)}
                            </select>
                        </label>
                        {create.isError || update.isError ? <Feedback tone="danger">{message(create.error ?? update.error)}</Feedback> : null}
                        <Button type="submit" disabled={create.isPending || update.isPending}>{editingPractice ? 'حفظ التدريب' : 'إنشاء التدريب'}</Button>
                        <Button type="button" variant="secondary" onClick={clearForm}>إلغاء</Button>
                    </form>
                ) : null}

                {practicesQuery.isPending ? <p>جار تحميل أنشطة التدريب…</p> : null}
                {practicesQuery.isError ? <Feedback tone="danger">{message(practicesQuery.error)}</Feedback> : null}
                {practicesQuery.isSuccess && practices.length === 0 ? <Feedback>لا توجد أنشطة تدريب.</Feedback> : null}

                {practices.map((practice) => (
                    <article className="admin-content-list__item" key={practice.id}>
                        <div>
                            <strong>{practice.name}</strong>
                            <p className="admin-content-list__meta">الحالة: {practice.status}</p>
                            {practice.description ? <p>{practice.description}</p> : null}
                        </div>
                        <div className="foundation-stack">
                            <Button type="button" size="sm" variant="secondary" onClick={() => selectPractice(practice.id)}>
                                {selectedPracticeId === practice.id ? 'إخفاء العضويات' : 'العضويات'}
                            </Button>
                            {editable && practice.status === 'archived' ? (
                                <>
                                    <Button type="button" size="sm" variant="secondary" onClick={() => startEditing(practice)}>تعديل التدريب</Button>
                                    <Button type="button" size="sm" onClick={() => {
                                        if (window.confirm('هل تريد تفعيل التدريب؟')) {
                                            lifecycle.mutate({ practice, status: 'active' });
                                        }
                                    }}>تفعيل التدريب</Button>
                                </>
                            ) : null}
                            {editable && practice.status === 'active' ? (
                                <Button type="button" size="sm" variant="secondary" onClick={() => {
                                    if (window.confirm('هل تريد أرشفة التدريب؟')) {
                                        lifecycle.mutate({ practice, status: 'archived' });
                                    }
                                }}>أرشفة التدريب</Button>
                            ) : null}
                        </div>
                    </article>
                ))}

                {lifecycle.isError ? <Feedback tone="danger">{message(lifecycle.error)}</Feedback> : null}

                {selectedPractice ? (
                    <TeacherPracticeMembershipEditor
                        key={selectedPractice.id}
                        authenticatedUserId={authenticatedUserId}
                        context={context}
                        practice={selectedPractice}
                        onContextUnavailable={onContextUnavailable}
                        onDirtyChange={setMembershipDirty}
                        onLifecycleConflict={onLifecycleConflict}
                        onParentRefresh={refresh}
                    />
                ) : null}
            </div>
        </Surface>
    );
}

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
    createTeacherTopic,
    fetchTeacherTopics,
    teacherTopicsKey,
    updateTeacherTopic,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherTopic,
} from './types';

interface TeacherTopicsPanelProps {
    authenticatedUserId: string;
    context: TeacherContext;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
}

function message(error: unknown): string {
    if (error instanceof EduCoreApiError) {
        return error.message;
    }

    return 'تعذر إكمال عملية الموضوع.';
}

function fields(error: unknown, field: string): string[] {
    return error instanceof EduCoreApiError
        ? error.details?.[field] ?? []
        : [];
}

function isUnavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

export function TeacherTopicsPanel({
    authenticatedUserId,
    context,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
}: TeacherTopicsPanelProps) {
    const queryClient = useQueryClient();
    const editable = context.versionStatus === 'draft';
    const [createOpen, setCreateOpen] = useState(false);
    const [name, setName] = useState('');
    const [displayOrder, setDisplayOrder] = useState('0');
    const [editing, setEditing] = useState<TeacherTopic | null>(null);
    const [editName, setEditName] = useState('');
    const [editOrder, setEditOrder] = useState('');

    const key = teacherTopicsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
    );
    const topics = useQuery({
        queryKey: key,
        queryFn: ({ signal }) => fetchTeacherTopics(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });

    const validTopics = useMemo(
        () => (topics.data ?? []).filter(
            (topic) => topic.curriculum_version_id
                === context.curriculumVersionId,
        ),
        [context.curriculumVersionId, topics.data],
    );
    const dirty = name !== ''
        || displayOrder !== '0'
        || (
            editing !== null
            && (
                editName !== editing.name
                || editOrder !== String(editing.display_order)
            )
        );

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (
            topics.isError
            && topics.error instanceof EduCoreApiError
            && (
                topics.error.status === 403
                || topics.error.status === 404
            )
        ) {
            onContextUnavailable();
        }
    }, [
        onContextUnavailable,
        topics.error,
        topics.isError,
    ]);

    async function refresh() {
        await queryClient.invalidateQueries({
            queryKey: key,
        });
    }

    function order(value: string): number | null {
        const parsed = Number(value);

        return Number.isInteger(parsed) && parsed >= 0
            ? parsed
            : null;
    }

    const create = useMutation({
        mutationFn: () => createTeacherTopic(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            {
                name: name.trim(),
                display_order: order(displayOrder)!,
            },
        ),
        onSuccess: async () => {
            setName('');
            setDisplayOrder('0');
            setCreateOpen(false);
            await refresh();
        },
        onError: async (error) => {
            if (isUnavailable(error)) {
                onContextUnavailable();

                return;
            }

            if (
                error instanceof EduCoreApiError
                && error.status === 409
            ) {
                await onLifecycleConflict();
                await refresh();
            }
        },
    });
    const update = useMutation({
        mutationFn: () => updateTeacherTopic(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            editing!.id,
            {
                name: editName.trim(),
                display_order: order(editOrder)!,
            },
        ),
        onSuccess: async () => {
            setEditing(null);
            await refresh();
        },
        onError: async (error) => {
            if (isUnavailable(error)) {
                onContextUnavailable();

                return;
            }

            if (
                error instanceof EduCoreApiError
                && error.status === 409
            ) {
                await onLifecycleConflict();
                await refresh();
            }
        },
    });

    function submitCreate(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || name.trim() === ''
            || order(displayOrder) === null
        ) return;
        create.mutate();
    }

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">الموضوعات</h2>
                    <p className="foundation-page__description">
                        موضوعات مسطحة مرتبة كما يعيدها الخادم.
                    </p>
                </div>
                {!editable ? <Feedback>هذا الإصدار للقراءة فقط.</Feedback> : (
                    <Button type="button" onClick={() => setCreateOpen((value) => !value)}>
                        {createOpen ? 'إغلاق' : 'إضافة موضوع'}
                    </Button>
                )}
                {createOpen && editable ? <form onSubmit={submitCreate} className="admin-content-form">
                    <label>اسم الموضوع<input aria-label="اسم الموضوع" value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} /></label>
                    <label>ترتيب العرض<input aria-label="ترتيب عرض الموضوع" value={displayOrder} type="number" min="0" step="1" onChange={(event) => setDisplayOrder(event.target.value)} required /></label>
                    {fields(create.error, 'name').map((item) => <Feedback key={item} tone="danger">{item}</Feedback>)}
                    {fields(create.error, 'display_order').map((item) => <Feedback key={item} tone="danger">{item}</Feedback>)}
                    {create.isError ? <Feedback tone="danger">{message(create.error)}</Feedback> : null}
                    <Button type="submit" disabled={create.isPending}>حفظ الموضوع</Button>
                </form> : null}
                {topics.isPending ? <p>جار تحميل الموضوعات…</p> : null}
                {topics.isError ? <Feedback tone="danger">{message(topics.error)}</Feedback> : null}
                {topics.isSuccess && validTopics.length === 0 ? <Feedback>لا توجد موضوعات في هذا الإصدار.</Feedback> : null}
                {validTopics.map((topic) => <article key={topic.id} className="admin-content-list__item">
                    {editing?.id === topic.id ? <form onSubmit={(event) => {
                        event.preventDefault();
                        if (editable && editName.trim() !== '' && order(editOrder) !== null) update.mutate();
                    }} className="admin-content-form">
                        <label>اسم الموضوع<input aria-label="تعديل اسم الموضوع" value={editName} onChange={(event) => setEditName(event.target.value)} required /></label>
                        <label>ترتيب العرض<input aria-label="تعديل ترتيب عرض الموضوع" type="number" min="0" step="1" value={editOrder} onChange={(event) => setEditOrder(event.target.value)} required /></label>
                        {fields(update.error, 'name').map((item) => <Feedback key={item} tone="danger">{item}</Feedback>)}
                        {fields(update.error, 'display_order').map((item) => <Feedback key={item} tone="danger">{item}</Feedback>)}
                        {update.isError ? <Feedback tone="danger">{message(update.error)}</Feedback> : null}
                        <Button type="submit" disabled={update.isPending}>حفظ</Button>
                        <Button type="button" variant="secondary" onClick={() => setEditing(null)}>إلغاء</Button>
                    </form> : <><div><strong>{topic.name}</strong><p className="admin-content-list__meta">ترتيب العرض: {topic.display_order}</p></div>{editable ? <Button type="button" size="sm" variant="secondary" onClick={() => {
                        setEditing(topic);
                        setEditName(topic.name);
                        setEditOrder(String(topic.display_order));
                    }}>تعديل</Button> : null}</>}
                </article>)}
            </div>
        </Surface>
    );
}

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
    createTeacherAssessmentItem,
    fetchTeacherAssessmentItems,
    fetchTeacherAssessmentRevisions,
    publishTeacherAssessmentItem,
    retireTeacherAssessmentItem,
    teacherAssessmentItemsKey,
    teacherAssessmentRevisionsKey,
    updateTeacherAssessmentItem,
} from './api';
import {
    TeacherAssessmentRevisionEditor,
} from './TeacherAssessmentRevisionEditor';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherAssessmentItem,
} from './types';

interface TeacherAssessmentItemsPanelProps {
    authenticatedUserId: string;
    context: TeacherContext;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
}

function errorMessage(error: unknown): string {
    return error instanceof EduCoreApiError
        ? error.message
        : 'تعذر إكمال عملية عنصر التقييم.';
}

function unavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

export function TeacherAssessmentItemsPanel({
    authenticatedUserId,
    context,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
}: TeacherAssessmentItemsPanelProps) {
    const queryClient = useQueryClient();
    const editable = context.versionStatus === 'draft';
    const [createOpen, setCreateOpen] = useState(false);
    const [itemType, setItemType] = useState('');
    const [label, setLabel] = useState('');
    const [editingItemId, setEditingItemId] = useState<string | null>(null);
    const [selectedItemId, setSelectedItemId] = useState<string | null>(null);
    const [editorDirty, setEditorDirty] = useState(false);
    const [publishTargetId, setPublishTargetId] = useState<string | null>(null);
    const [publishedRevisionId, setPublishedRevisionId] = useState('');
    const [editRecovery, setEditRecovery] = useState<{
        itemId: string;
        itemType: string;
        label: string;
        status: 'published' | 'retired';
    } | null>(null);

    const key = teacherAssessmentItemsKey(authenticatedUserId, context.assignmentId, context.curriculumId, context.curriculumVersionId);
    const itemsQuery = useQuery({
        queryKey: key,
        queryFn: ({ signal }) => fetchTeacherAssessmentItems(context.assignmentId, context.curriculumId, context.curriculumVersionId, signal),
    });
    const publishableRevisions = useQuery({
        queryKey: teacherAssessmentRevisionsKey(authenticatedUserId, context.assignmentId, context.curriculumId, context.curriculumVersionId, publishTargetId ?? ''),
        queryFn: ({ signal }) => fetchTeacherAssessmentRevisions(context.assignmentId, context.curriculumId, context.curriculumVersionId, publishTarget!.id, signal),
        enabled: publishTargetId !== null,
    });
    const items = useMemo(
        () => (itemsQuery.data ?? []).filter((item) => item.curriculum_version_id === context.curriculumVersionId),
        [context.curriculumVersionId, itemsQuery.data],
    );
    const selectedItem = useMemo(
        () => items.find((item) => item.id === selectedItemId) ?? null,
        [items, selectedItemId],
    );
    const publishTarget = useMemo(
        () => items.find((item) => item.id === publishTargetId) ?? null,
        [items, publishTargetId],
    );
    const editingItem = useMemo(
        () => items.find((item) => item.id === editingItemId) ?? null,
        [editingItemId, items],
    );
    const dirty = createOpen
        || editingItem !== null
        || editRecovery !== null
        || editorDirty
        || itemType !== ''
        || label !== '';

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (unavailable(itemsQuery.error)) {
            setEditingItemId(null);
            setSelectedItemId(null);
            setPublishTargetId(null);
            setEditRecovery(null);
            setItemType('');
            setLabel('');
            onContextUnavailable();
        }
    }, [itemsQuery.error, onContextUnavailable]);

    useEffect(() => {
        if (itemsQuery.isSuccess && selectedItemId !== null && selectedItem === null) {
            setSelectedItemId(null);
        }

        if (itemsQuery.isSuccess && publishTargetId !== null && publishTarget === null) {
            setPublishTargetId(null);
            setPublishedRevisionId('');
        }

        if (editingItemId !== null && editingItem === null && itemsQuery.isSuccess) {
            setEditingItemId(null);
            setEditRecovery(null);
            setItemType('');
            setLabel('');
        } else if (editingItem !== null && editingItem.status !== 'draft') {
            setEditRecovery({
                itemId: editingItem.id,
                itemType,
                label,
                status: editingItem.status,
            });
            setEditingItemId(null);
        }

        if (
            editRecovery !== null
            && itemsQuery.isSuccess
            && !items.some((item) => item.id === editRecovery.itemId)
        ) {
            setEditRecovery(null);
            setItemType('');
            setLabel('');
        }
    }, [editRecovery, editingItem, editingItemId, itemType, items, itemsQuery.isSuccess, label, publishTarget, publishTargetId, selectedItem, selectedItemId]);

    async function refresh() {
        await queryClient.invalidateQueries({ queryKey: key });
    }

    async function handleError(error: unknown) {
        if (unavailable(error)) {
            setCreateOpen(false);
            setEditingItemId(null);
            setSelectedItemId(null);
            setPublishTargetId(null);
            setEditRecovery(null);
            setItemType('');
            setLabel('');
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
        setEditingItemId(null);
        setEditRecovery(null);
        setItemType('');
        setLabel('');
    }

    const create = useMutation({
        mutationFn: () => createTeacherAssessmentItem(context.assignmentId, context.curriculumId, context.curriculumVersionId, {
            item_type: itemType.trim(),
            internal_label: label.trim() || null,
        }),
        onSuccess: async () => {
            clearForm();
            await refresh();
        },
        onError: handleError,
    });
    const update = useMutation({
        mutationFn: () => updateTeacherAssessmentItem(context.assignmentId, context.curriculumId, context.curriculumVersionId, editingItem!.id, {
            item_type: itemType.trim(),
            internal_label: label.trim() || null,
        }),
        onSuccess: async () => {
            clearForm();
            await refresh();
        },
        onError: handleError,
    });
    const publish = useMutation({
        mutationFn: () => publishTeacherAssessmentItem(context.assignmentId, context.curriculumId, context.curriculumVersionId, publishTarget!.id, publishedRevisionId),
        onSuccess: async () => {
            setPublishTargetId(null);
            setPublishedRevisionId('');
            await refresh();
        },
        onError: handleError,
    });
    const retire = useMutation({
        mutationFn: (itemId: string) => retireTeacherAssessmentItem(context.assignmentId, context.curriculumId, context.curriculumVersionId, itemId),
        onSuccess: refresh,
        onError: handleError,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!editable || itemType.trim() === '') return;
        if (editingItem) {
            update.mutate();
        } else {
            create.mutate();
        }
    }

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">عناصر التقييم ونسخها</h2>
                    <p className="foundation-page__description">
                        أنشئ عنصر تقييم، حرر نسخه، ثم أصدر النسخة وانشر العنصر.
                    </p>
                </div>

                {!editable ? <Feedback>هذا الإصدار للقراءة فقط.</Feedback> : <Button type="button" onClick={() => {
                    setCreateOpen((open) => !open);
                    setEditingItemId(null);
                    setEditRecovery(null);
                }}>{createOpen ? 'إغلاق نموذج العنصر' : 'إضافة عنصر تقييم'}</Button>}

                {editRecovery ? <section aria-label="قيم تعديل عنصر التقييم المحفوظة" className="admin-content-form">
                    <Feedback tone="warning">
                        تغيّرت حالة عنصر التقييم إلى {editRecovery.status}. أُوقفت أدوات التعديل، ويمكنك نسخ القيم قبل تجاهلها.
                    </Feedback>
                    <label>نوع العنصر المحفوظ<input aria-label="نوع العنصر المحفوظ للاسترداد" readOnly value={editRecovery.itemType} /></label>
                    <label>التسمية الداخلية المحفوظة<input aria-label="التسمية الداخلية المحفوظة للاسترداد" readOnly value={editRecovery.label} /></label>
                    <Button type="button" variant="secondary" onClick={() => {
                        setEditRecovery(null);
                        setItemType('');
                        setLabel('');
                    }}>تجاهل القيم المحفوظة</Button>
                </section> : null}

                {(createOpen || editingItem) && editable && (editingItem === null || editingItem.status === 'draft') ? <form className="admin-content-form" onSubmit={submit}>
                    <label>نوع العنصر<input aria-label="نوع عنصر التقييم" value={itemType} onChange={(event) => setItemType(event.target.value)} required maxLength={255} /></label>
                    <label>التسمية الداخلية<input aria-label="التسمية الداخلية لعنصر التقييم" value={label} onChange={(event) => setLabel(event.target.value)} maxLength={255} /></label>
                    {create.isError || update.isError ? <Feedback tone="danger">{errorMessage(create.error ?? update.error)}</Feedback> : null}
                    <Button type="submit" disabled={create.isPending || update.isPending}>{editingItem ? 'حفظ العنصر' : 'إنشاء العنصر'}</Button>
                    <Button type="button" variant="secondary" onClick={clearForm}>إلغاء</Button>
                </form> : null}

                {itemsQuery.isPending ? <p>جار تحميل عناصر التقييم…</p> : null}
                {itemsQuery.isError ? <Feedback tone="danger">{errorMessage(itemsQuery.error)}</Feedback> : null}
                {itemsQuery.isSuccess && items.length === 0 ? <Feedback>لا توجد عناصر تقييم في هذا الإصدار.</Feedback> : null}

                {items.map((item) => <article className="admin-content-list__item" key={item.id}>
                    <div><strong>{item.internal_label ?? item.item_type}</strong><p className="admin-content-list__meta">{item.item_type} · {item.status}</p></div>
                    <div className="foundation-stack">
                        <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedItemId(item.id)}>
                            {selectedItemId === item.id ? 'إخفاء النسخ' : 'النسخ'}
                        </Button>
                        {editable && item.status === 'draft' ? <Button type="button" size="sm" variant="secondary" onClick={() => {
                            setEditingItemId(item.id);
                            setEditRecovery(null);
                            setItemType(item.item_type);
                            setLabel(item.internal_label ?? '');
                        }}>تعديل</Button> : null}
                        {editable && item.status === 'draft' ? <Button type="button" size="sm" onClick={() => {
                            setPublishTargetId(item.id);
                            setPublishedRevisionId('');
                        }}>نشر</Button> : null}
                        {editable && item.status === 'published' ? <Button type="button" size="sm" variant="secondary" onClick={() => {
                            if (window.confirm('هل تريد تقاعد عنصر التقييم؟')) retire.mutate(item.id);
                        }}>تقاعد</Button> : null}
                    </div>
                </article>)}

                {publishTarget && editable ? <form className="admin-content-form" onSubmit={(event) => {
                    event.preventDefault();
                    if (publishedRevisionId !== '' && window.confirm('هل تريد نشر العنصر بهذه النسخة؟')) publish.mutate();
                }}>
                    <label>النسخة المُصدرة<select aria-label="نسخة عنصر التقييم المنشورة" value={publishedRevisionId} onChange={(event) => setPublishedRevisionId(event.target.value)}>
                        <option value="">اختر نسخة مُصدرة</option>
                        {(publishableRevisions.data ?? []).filter((revision) => revision.assessment_item_id === publishTarget.id && revision.curriculum_version_id === context.curriculumVersionId && revision.released_at !== null).map((revision) => <option key={revision.id} value={revision.id}>نسخة {revision.revision_number}</option>)}
                    </select></label>
                    {publishableRevisions.isError || publish.isError ? <Feedback tone="danger">{errorMessage(publishableRevisions.error ?? publish.error)}</Feedback> : null}
                    <Button type="submit" disabled={publishedRevisionId === '' || publish.isPending}>تأكيد النشر</Button>
                    <Button type="button" variant="secondary" onClick={() => setPublishTargetId(null)}>إلغاء</Button>
                </form> : null}

                {selectedItem ? <TeacherAssessmentRevisionEditor authenticatedUserId={authenticatedUserId} context={context} item={selectedItem} onContextUnavailable={onContextUnavailable} onDirtyChange={setEditorDirty} onLifecycleConflict={async () => {
                    await onLifecycleConflict();
                    await refresh();
                }} /> : null}
            </div>
        </Surface>
    );
}

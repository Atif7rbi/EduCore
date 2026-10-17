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
    createTeacherExamTemplate,
    fetchTeacherExamTemplates,
    setTeacherExamTemplateStatus,
    teacherExamTemplatesKey,
    updateTeacherExamTemplate,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import {
    TeacherExamTemplateVersionEditor,
} from './TeacherExamTemplateVersionEditor';
import type {
    TeacherExamTemplate,
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
        return 'تعذر إكمال عملية نموذج الامتحان.';
    }

    const details = Object.values(error.details ?? {}).flat();

    return details.length > 0 ? details.join(' ') : error.message;
}

export function TeacherExamTemplatesPanel({
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
    const [editingTemplateId, setEditingTemplateId] = useState<string | null>(null);
    const [selectedTemplateId, setSelectedTemplateId] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [versionDirty, setVersionDirty] = useState(false);
    const key = teacherExamTemplatesKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
    );
    const templatesQuery = useQuery({
        queryKey: key,
        queryFn: ({ signal }) => fetchTeacherExamTemplates(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const templates = useMemo(
        () => (templatesQuery.data ?? []).filter(
            (template) => template.curriculum_version_id
                === context.curriculumVersionId,
        ),
        [context.curriculumVersionId, templatesQuery.data],
    );
    const selectedTemplate = templates.find(
        (template) => template.id === selectedTemplateId,
    ) ?? null;
    const editingTemplate = templates.find(
        (template) => template.id === editingTemplateId,
    ) ?? null;
    const dirty = createOpen
        || editingTemplate !== null
        || name !== ''
        || description !== ''
        || versionDirty;

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (unavailable(templatesQuery.error)) {
            clearAll();
            onContextUnavailable();
        }
    }, [onContextUnavailable, templatesQuery.error]);

    useEffect(() => {
        if (!templatesQuery.isSuccess) return;

        if (selectedTemplateId !== null && selectedTemplate === null) {
            setSelectedTemplateId(null);
            setVersionDirty(false);
        }
        if (editingTemplateId !== null && editingTemplate === null) {
            clearForm();
        }
    });


    function selectTemplate(resourceId: string) {
        const nextId = selectedTemplateId === resourceId
            ? null
            : resourceId;

        if (nextId === selectedTemplateId) return;

        if (
            versionDirty
            && !window.confirm(
                'لديك تغييرات غير محفوظة في نسخة النموذج. هل تريد تجاهلها؟',
            )
        ) {
            return;
        }

        setSelectedTemplateId(nextId);
    }

    async function refresh() {
        await queryClient.invalidateQueries({ queryKey: key });
    }

    async function handleError(error: unknown) {
        if (!mountedRef.current) return;

        if (unavailable(error)) {
            clearAll();
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
        setEditingTemplateId(null);
        setName('');
        setDescription('');
    }

    function clearAll() {
        clearForm();
        setSelectedTemplateId(null);
        setVersionDirty(false);
    }

    const create = useMutation({
        mutationFn: () => createTeacherExamTemplate(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            {
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
        mutationFn: () => updateTeacherExamTemplate(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            editingTemplate!.id,
            {
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
            template,
            status,
        }: {
            template: TeacherExamTemplate;
            status: 'active' | 'archived';
        }) => setTeacherExamTemplateStatus(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
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
        if (editingTemplate) update.mutate();
        else create.mutate();
    }


    function hasUnsavedTemplateFormChanges(): boolean {
        if (createOpen) {
            return name !== '' || description !== '';
        }

        if (editingTemplate) {
            return name !== editingTemplate.name
                || description !== (editingTemplate.description ?? '');
        }

        return false;
    }

    function startEditing(template: TeacherExamTemplate) {
        if (!editable || template.status !== 'active') return;
        if (editingTemplateId === template.id) return;

        if (
            hasUnsavedTemplateFormChanges()
            && !window.confirm(
                'لديك تغييرات غير محفوظة في النموذج الحالي. هل تريد تجاهلها؟',
            )
        ) {
            return;
        }

        setCreateOpen(false);
        setEditingTemplateId(template.id);
        setName(template.name);
        setDescription(template.description ?? '');
    }

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">نماذج الامتحان</h2>
                    <p className="foundation-page__description">
                        أنشئ نماذج ونسخ قواعد، ثم انشر نسخة لتوليد امتحان.
                    </p>
                </div>

                {!editable ? <Feedback>نماذج هذا الإصدار للقراءة فقط، ويمكن التوليد من نسخة منشورة مؤهلة.</Feedback> : (
                    <Button type="button" onClick={() => {
                        if (createOpen) {
                            if (hasUnsavedTemplateFormChanges() && !window.confirm(
                                'هل تريد إغلاق النموذج وتجاهل مدخلاته؟',
                            )) return;
                            clearForm();
                        } else {
                            if (hasUnsavedTemplateFormChanges() && !window.confirm(
                                'هل تريد تجاهل التعديلات الحالية؟',
                            )) return;
                            clearForm();
                            setCreateOpen(true);
                        }
                    }}>
                        {createOpen ? 'إغلاق نموذج الامتحان' : 'إضافة نموذج امتحان'}
                    </Button>
                )}

                {(createOpen || editingTemplate) && editable ? (
                    <form className="admin-content-form" onSubmit={submit}>
                        <label>
                            اسم نموذج الامتحان
                            <input aria-label="اسم نموذج الامتحان" value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} />
                        </label>
                        <label>
                            الوصف
                            <textarea aria-label="وصف نموذج الامتحان" value={description} onChange={(event) => setDescription(event.target.value)} />
                        </label>
                        {create.isError || update.isError ? <Feedback tone="danger">{message(create.error ?? update.error)}</Feedback> : null}
                        <Button type="submit" disabled={create.isPending || update.isPending}>{editingTemplate ? 'حفظ نموذج الامتحان' : 'إنشاء نموذج الامتحان'}</Button>
                        <Button type="button" variant="secondary" onClick={clearForm}>إلغاء</Button>
                    </form>
                ) : null}

                {templatesQuery.isPending ? <p>جار تحميل نماذج الامتحان…</p> : null}
                {templatesQuery.isError ? <Feedback tone="danger">{message(templatesQuery.error)}</Feedback> : null}
                {templatesQuery.isSuccess && templates.length === 0 ? <Feedback>لا توجد نماذج امتحان.</Feedback> : null}

                {templates.map((template) => (
                    <article className="admin-content-list__item" key={template.id}>
                        <div>
                            <strong>{template.name}</strong>
                            <p className="admin-content-list__meta">الحالة: {template.status}</p>
                            {template.description ? <p>{template.description}</p> : null}
                        </div>
                        <div className="foundation-stack">
                            <Button type="button" size="sm" variant="secondary" onClick={() => selectTemplate(template.id)}>
                                {selectedTemplateId === template.id ? 'إخفاء النسخ' : 'نسخ النموذج'}
                            </Button>
                            {editable && template.status === 'active' ? (
                                <>
                                    <Button type="button" size="sm" variant="secondary" onClick={() => startEditing(template)}>تعديل النموذج</Button>
                                    <Button type="button" size="sm" variant="secondary" onClick={() => {
                                        if (window.confirm('هل تريد أرشفة نموذج الامتحان؟')) {
                                            lifecycle.mutate({ template, status: 'archived' });
                                        }
                                    }}>أرشفة النموذج</Button>
                                </>
                            ) : null}
                            {editable && template.status === 'archived' ? (
                                <Button type="button" size="sm" onClick={() => {
                                    if (window.confirm('هل تريد تفعيل نموذج الامتحان؟')) {
                                        lifecycle.mutate({ template, status: 'active' });
                                    }
                                }}>تفعيل النموذج</Button>
                            ) : null}
                        </div>
                    </article>
                ))}

                {lifecycle.isError ? <Feedback tone="danger">{message(lifecycle.error)}</Feedback> : null}

                {selectedTemplate ? (
                    <TeacherExamTemplateVersionEditor
                        key={selectedTemplate.id}
                        authenticatedUserId={authenticatedUserId}
                        context={context}
                        template={selectedTemplate}
                        onContextUnavailable={onContextUnavailable}
                        onDirtyChange={setVersionDirty}
                        onLifecycleConflict={onLifecycleConflict}
                        onParentRefresh={refresh}
                    />
                ) : null}
            </div>
        </Surface>
    );
}

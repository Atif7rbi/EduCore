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
} from '../ui';
import {
    createTeacherExamGeneration,
    createTeacherExamTemplateVersion,
    fetchTeacherAssessmentItems,
    fetchTeacherAssessmentRevisions,
    fetchTeacherExamTemplateVersion,
    fetchTeacherExamTemplateVersions,
    publishTeacherExamTemplateVersion,
    retireTeacherExamTemplateVersion,
    teacherAssessmentItemsKey,
    teacherAssessmentRevisionsKey,
    teacherExamTemplateVersionKey,
    teacherExamTemplateVersionsKey,
    updateTeacherExamTemplateVersion,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherExamGeneration,
    TeacherExamTemplate,
    TeacherExamTemplateVersion,
    TeacherRulesPayload,
} from './types';

interface Props {
    authenticatedUserId: string;
    context: TeacherContext;
    template: TeacherExamTemplate;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
    onParentRefresh: () => Promise<void>;
}

function unavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

function message(error: unknown): string {
    if (!(error instanceof EduCoreApiError)) {
        return 'تعذر إكمال عملية نموذج الامتحان.';
    }

    if (error.status === 422) {
        const details = Object.values(error.details ?? {}).flat();

        return details.length > 0 ? details.join(' ') : error.message;
    }

    return error.message;
}

function parseRules(value: string): TeacherRulesPayload | null {
    try {
        const parsed: unknown = JSON.parse(value);

        if (
            parsed !== null
            && typeof parsed === 'object'
        ) {
            return parsed as TeacherRulesPayload;
        }
    } catch {
        return null;
    }

    return null;
}

function prettyRules(value: TeacherRulesPayload): string {
    return JSON.stringify(value, null, 2);
}

export function TeacherExamTemplateVersionEditor({
    authenticatedUserId,
    context,
    template,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
    onParentRefresh,
}: Props) {
    const queryClient = useQueryClient();
    const mountedRef = useRef(false);

    useEffect(() => {
        mountedRef.current = true;

        return () => {
            mountedRef.current = false;
        };
    }, []);

    const structurallyEditable = context.versionStatus === 'draft'
        && template.status === 'active';
    const [createOpen, setCreateOpen] = useState(false);
    const [editingVersionId, setEditingVersionId] = useState<string | null>(null);
    const [selectedVersionId, setSelectedVersionId] = useState<string | null>(null);
    const [versionNumber, setVersionNumber] = useState('1');
    const [label, setLabel] = useState('');
    const [rulesText, setRulesText] = useState('{}');
    const [rulesSchemaVersion, setRulesSchemaVersion] = useState('1');
    const [generationTargetId, setGenerationTargetId] = useState<string | null>(null);
    const [generatorVersion, setGeneratorVersion] = useState('');
    const [seed, setSeed] = useState('');
    const [assessmentId, setAssessmentId] = useState('');
    const [candidateRevisionId, setCandidateRevisionId] = useState('');
    const [submittedRevisionIds, setSubmittedRevisionIds] = useState<string[]>([]);
    const [lastSubmittedOrder, setLastSubmittedOrder] = useState<string[]>([]);
    const [generationResult, setGenerationResult] = useState<TeacherExamGeneration | null>(null);
    const versionsKey = teacherExamTemplateVersionsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
        template.id,
    );
    const versionsQuery = useQuery({
        queryKey: versionsKey,
        queryFn: ({ signal }) => fetchTeacherExamTemplateVersions(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
            signal,
        ),
    });
    const versions = useMemo(
        () => (versionsQuery.data ?? []).filter(
            (version) => version.exam_template_id === template.id
                && version.curriculum_version_id === context.curriculumVersionId,
        ),
        [context.curriculumVersionId, template.id, versionsQuery.data],
    );
    const selectedVersionSummary = versions.find(
        (version) => version.id === selectedVersionId,
    ) ?? null;
    const editingVersion = versions.find(
        (version) => version.id === editingVersionId,
    ) ?? null;
    const generationTarget = versions.find(
        (version) => version.id === generationTargetId,
    ) ?? null;
    const detailQuery = useQuery({
        queryKey: teacherExamTemplateVersionKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            selectedVersionId ?? '',
        ),
        queryFn: ({ signal }) => fetchTeacherExamTemplateVersion(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
            selectedVersionId!,
            signal,
        ),
        enabled: selectedVersionId !== null,
    });
    const selectedVersion = detailQuery.data
        && detailQuery.data.id === selectedVersionId
        && detailQuery.data.exam_template_id === template.id
        && detailQuery.data.curriculum_version_id === context.curriculumVersionId
        ? detailQuery.data
        : null;
    const assessmentsQuery = useQuery({
        queryKey: teacherAssessmentItemsKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
        ),
        queryFn: ({ signal }) => fetchTeacherAssessmentItems(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
        enabled: generationTargetId !== null,
    });
    const revisionsQuery = useQuery({
        queryKey: teacherAssessmentRevisionsKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            assessmentId,
        ),
        queryFn: ({ signal }) => fetchTeacherAssessmentRevisions(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            assessmentId,
            signal,
        ),
        enabled: generationTargetId !== null && assessmentId !== '',
    });
    const assessments = useMemo(
        () => (assessmentsQuery.data ?? []).filter(
            (item) => item.curriculum_version_id === context.curriculumVersionId,
        ),
        [assessmentsQuery.data, context.curriculumVersionId],
    );
    const releasedRevisions = useMemo(
        () => (revisionsQuery.data ?? []).filter(
            (revision) => revision.assessment_item_id === assessmentId
                && revision.curriculum_version_id === context.curriculumVersionId
                && revision.released_at !== null,
        ),
        [assessmentId, context.curriculumVersionId, revisionsQuery.data],
    );
    const dirty = createOpen
        || editingVersion !== null
        || generationTarget !== null
        || label !== ''
        || rulesText !== '{}'
        || rulesSchemaVersion !== '1'
        || generatorVersion !== ''
        || seed !== ''
        || submittedRevisionIds.length > 0;

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        const error = versionsQuery.error
            ?? detailQuery.error
            ?? assessmentsQuery.error
            ?? revisionsQuery.error;

        if (unavailable(error)) {
            clearAll();
            onContextUnavailable();
        }
    }, [
        assessmentsQuery.error,
        detailQuery.error,
        onContextUnavailable,
        revisionsQuery.error,
        versionsQuery.error,
    ]);

    useEffect(() => {
        if (!versionsQuery.isSuccess) return;

        if (selectedVersionId !== null && selectedVersionSummary === null) {
            setSelectedVersionId(null);
        }
        if (editingVersionId !== null && editingVersion === null) {
            clearVersionForm();
        }
        if (generationTargetId !== null && generationTarget === null) {
            clearGeneration();
        }
    });

    async function refreshVersions() {
        await queryClient.invalidateQueries({ queryKey: versionsKey });
        if (selectedVersionId !== null) {
            await queryClient.invalidateQueries({
                queryKey: teacherExamTemplateVersionKey(
                    authenticatedUserId,
                    context.assignmentId,
                    context.curriculumId,
                    context.curriculumVersionId,
                    selectedVersionId,
                ),
            });
        }
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
            await onParentRefresh();
            await refreshVersions();
        }
    }

    function clearVersionForm() {
        setCreateOpen(false);
        setEditingVersionId(null);
        setVersionNumber('1');
        setLabel('');
        setRulesText('{}');
        setRulesSchemaVersion('1');
    }

    function clearGeneration() {
        setGenerationTargetId(null);
        setGeneratorVersion('');
        setSeed('');
        setAssessmentId('');
        setCandidateRevisionId('');
        setSubmittedRevisionIds([]);
    }

    function clearAll() {
        clearVersionForm();
        clearGeneration();
        setSelectedVersionId(null);
        setGenerationResult(null);
        setLastSubmittedOrder([]);
    }

    const createVersion = useMutation({
        mutationFn: (rules: TeacherRulesPayload) => createTeacherExamTemplateVersion(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
            {
                version_number: Number(versionNumber),
                label: label.trim() || null,
                rules_payload: rules,
                rules_schema_version: Number(rulesSchemaVersion),
            },
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            clearVersionForm();
            await refreshVersions();
        },
        onError: handleError,
    });
    const updateVersion = useMutation({
        mutationFn: (rules: TeacherRulesPayload) => updateTeacherExamTemplateVersion(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
            editingVersion!.id,
            {
                label: label.trim() || null,
                rules_payload: rules,
                rules_schema_version: Number(rulesSchemaVersion),
            },
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            clearVersionForm();
            await refreshVersions();
        },
        onError: handleError,
    });
    const publish = useMutation({
        mutationFn: (versionId: string) => publishTeacherExamTemplateVersion(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
            versionId,
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            await onParentRefresh();
            await refreshVersions();
        },
        onError: handleError,
    });
    const retire = useMutation({
        mutationFn: (versionId: string) => retireTeacherExamTemplateVersion(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
            versionId,
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            await refreshVersions();
        },
        onError: handleError,
    });
    const generation = useMutation({
        mutationFn: () => createTeacherExamGeneration(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            template.id,
            generationTarget!.id,
            {
                generator_version: generatorVersion.trim(),
                seed: seed.trim(),
                assessment_item_revision_ids: submittedRevisionIds,
            },
        ),
        onSuccess: (result) => {
            if (!mountedRef.current) return;
            setGenerationResult(result);
            setLastSubmittedOrder([...submittedRevisionIds]);
            clearGeneration();
        },
        onError: handleError,
    });

    function submitVersion(event: FormEvent) {
        event.preventDefault();
        const rules = parseRules(rulesText);
        if (
            !structurallyEditable
            || rules === null
            || !Number.isInteger(Number(rulesSchemaVersion))
            || Number(rulesSchemaVersion) < 1
            || (!editingVersion && (
                !Number.isInteger(Number(versionNumber))
                || Number(versionNumber) < 1
            ))
        ) {
            return;
        }

        if (editingVersion) updateVersion.mutate(rules);
        else createVersion.mutate(rules);
    }


    function hasUnsavedVersionFormChanges(): boolean {
        if (createOpen) {
            return versionNumber !== '1'
                || label !== ''
                || rulesText !== '{}'
                || rulesSchemaVersion !== '1';
        }

        if (editingVersion) {
            return versionNumber !== String(editingVersion.version_number)
                || label !== (editingVersion.label ?? '')
                || rulesText !== prettyRules(editingVersion.rules_payload)
                || rulesSchemaVersion !== String(editingVersion.rules_schema_version);
        }

        return false;
    }

    function startEditing(version: TeacherExamTemplateVersion) {
        if (!structurallyEditable || version.status !== 'draft') return;
        if (editingVersionId === version.id) return;

        if (
            hasUnsavedVersionFormChanges()
            && !window.confirm(
                'لديك تغييرات غير محفوظة في النسخة الحالية. هل تريد تجاهلها؟',
            )
        ) {
            return;
        }

        setCreateOpen(false);
        setEditingVersionId(version.id);
        setVersionNumber(String(version.version_number));
        setLabel(version.label ?? '');
        setRulesText(prettyRules(version.rules_payload));
        setRulesSchemaVersion(String(version.rules_schema_version));
    }

    function submitGeneration(event: FormEvent) {
        event.preventDefault();
        if (
            context.versionStatus === 'retired'
            || generationTarget?.status !== 'published'
            || generatorVersion.trim() === ''
            || seed.trim() === ''
            || submittedRevisionIds.length === 0
        ) {
            return;
        }

        generation.mutate();
    }

    return (
        <section className="foundation-stack" aria-label={'نسخ نموذج الامتحان: ' + template.name}>
            <h3>نسخ نموذج الامتحان: {template.name}</h3>

            {structurallyEditable ? (
                <Button type="button" onClick={() => {
                    if (createOpen) {
                        if (hasUnsavedVersionFormChanges() && !window.confirm(
                            'هل تريد إغلاق نموذج النسخة وتجاهل مدخلاته؟',
                        )) return;
                        clearVersionForm();
                    } else {
                        if (hasUnsavedVersionFormChanges() && !window.confirm(
                            'هل تريد تجاهل تعديل النسخة الحالية؟',
                        )) return;
                        clearVersionForm();
                        setCreateOpen(true);
                    }
                }}>
                    {createOpen ? 'إغلاق نموذج النسخة' : 'إضافة نسخة نموذج'}
                </Button>
            ) : <Feedback>نسخ هذا النموذج للقراءة فقط.</Feedback>}

            {(createOpen || editingVersion) && structurallyEditable ? (
                <form className="admin-content-form" onSubmit={submitVersion}>
                    {!editingVersion ? (
                        <label>
                            رقم النسخة
                            <input aria-label="رقم نسخة نموذج الامتحان" type="number" min="1" step="1" value={versionNumber} onChange={(event) => setVersionNumber(event.target.value)} />
                        </label>
                    ) : null}
                    <label>
                        تسمية النسخة
                        <input aria-label="تسمية نسخة نموذج الامتحان" value={label} onChange={(event) => setLabel(event.target.value)} maxLength={255} />
                    </label>
                    <label>
                        قواعد JSON
                        <textarea aria-label="قواعد نموذج الامتحان" value={rulesText} onChange={(event) => setRulesText(event.target.value)} />
                    </label>
                    <label>
                        إصدار مخطط القواعد
                        <input aria-label="إصدار مخطط قواعد نموذج الامتحان" type="number" min="1" step="1" value={rulesSchemaVersion} onChange={(event) => setRulesSchemaVersion(event.target.value)} />
                    </label>
                    {parseRules(rulesText) === null ? <Feedback tone="danger">يجب أن تكون القواعد كائن JSON أو مصفوفة JSON.</Feedback> : null}
                    {createVersion.isError || updateVersion.isError ? <Feedback tone="danger">{message(createVersion.error ?? updateVersion.error)}</Feedback> : null}
                    <Button type="submit" disabled={createVersion.isPending || updateVersion.isPending || parseRules(rulesText) === null}>
                        {editingVersion ? 'حفظ نسخة النموذج' : 'إنشاء نسخة النموذج'}
                    </Button>
                    <Button type="button" variant="secondary" onClick={clearVersionForm}>إلغاء</Button>
                </form>
            ) : null}

            {versionsQuery.isPending ? <p>جار تحميل نسخ النموذج…</p> : null}
            {versionsQuery.isError ? <Feedback tone="danger">{message(versionsQuery.error)}</Feedback> : null}
            {versionsQuery.isSuccess && versions.length === 0 ? <Feedback>لا توجد نسخ لهذا النموذج.</Feedback> : null}

            {versions.map((version) => (
                <article className="admin-content-list__item" key={version.id}>
                    <div>
                        <strong>نسخة {version.version_number}</strong>
                        <p className="admin-content-list__meta">الحالة: {version.status} · مخطط: {version.rules_schema_version}</p>
                        {version.label ? <p>{version.label}</p> : null}
                    </div>
                    <div className="foundation-stack">
                        <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedVersionId(
                            selectedVersionId === version.id ? null : version.id,
                        )}>التفاصيل</Button>
                        {structurallyEditable && version.status === 'draft' ? (
                            <>
                                <Button type="button" size="sm" variant="secondary" onClick={() => startEditing(version)}>تعديل النسخة</Button>
                                <Button type="button" size="sm" onClick={() => {
                                    if (window.confirm('هل تريد نشر نسخة النموذج؟')) publish.mutate(version.id);
                                }}>نشر النسخة</Button>
                            </>
                        ) : null}
                        {structurallyEditable
                            && version.status === 'published'
                            && template.published_version_id !== version.id ? (
                            <Button type="button" size="sm" variant="secondary" onClick={() => {
                                if (window.confirm('هل تريد تقاعد نسخة النموذج؟')) retire.mutate(version.id);
                            }}>تقاعد النسخة</Button>
                        ) : null}
                        {context.versionStatus !== 'retired' && version.status === 'published' ? (
                            <Button type="button" size="sm" onClick={() => {
                                clearGeneration();
                                setGenerationTargetId(version.id);
                                setGenerationResult(null);
                            }}>إنشاء توليد امتحان</Button>
                        ) : null}
                    </div>
                </article>
            ))}

            {selectedVersionId !== null && detailQuery.isPending ? <p>جار تحميل تفاصيل النسخة…</p> : null}
            {detailQuery.isError ? <Feedback tone="danger">{message(detailQuery.error)}</Feedback> : null}
            {selectedVersion ? (
                <section aria-label="تفاصيل نسخة نموذج الامتحان" className="admin-content-form">
                    <strong>{selectedVersion.id}</strong>
                    <pre>{prettyRules(selectedVersion.rules_payload)}</pre>
                    <p>إصدار المخطط: {selectedVersion.rules_schema_version}</p>
                </section>
            ) : null}

            {generationTarget ? (
                <form className="admin-content-form" onSubmit={submitGeneration}>
                    <h4>إنشاء امتحان من النسخة {generationTarget.version_number}</h4>
                    <label>
                        إصدار المولد
                        <input aria-label="إصدار مولد الامتحان" value={generatorVersion} onChange={(event) => setGeneratorVersion(event.target.value)} required />
                    </label>
                    <label>
                        البذرة
                        <input aria-label="بذرة توليد الامتحان" value={seed} onChange={(event) => setSeed(event.target.value)} required />
                    </label>
                    <label>
                        عنصر التقييم
                        <select aria-label="عنصر تقييم توليد الامتحان" value={assessmentId} onChange={(event) => {
                            setAssessmentId(event.target.value);
                            setCandidateRevisionId('');
                        }}>
                            <option value="">اختر عنصرًا</option>
                            {assessments.map((assessment) => <option key={assessment.id} value={assessment.id}>{assessment.internal_label ?? assessment.item_type}</option>)}
                        </select>
                    </label>
                    <label>
                        النسخة المُصدرة
                        <select aria-label="نسخة تقييم توليد الامتحان" value={candidateRevisionId} onChange={(event) => setCandidateRevisionId(event.target.value)}>
                            <option value="">اختر نسخة مُصدرة</option>
                            {releasedRevisions.map((revision) => <option key={revision.id} value={revision.id}>نسخة {revision.revision_number}</option>)}
                        </select>
                    </label>
                    <Button type="button" variant="secondary" disabled={candidateRevisionId === ''} onClick={() => {
                        if (candidateRevisionId !== '' && !submittedRevisionIds.includes(candidateRevisionId)) {
                            setSubmittedRevisionIds((ids) => [...ids, candidateRevisionId]);
                        }
                        setCandidateRevisionId('');
                    }}>أضف إلى ترتيب الطلب</Button>
                    <ol aria-label="ترتيب نسخ التقييم المطلوب">
                        {submittedRevisionIds.map((revisionId) => (
                            <li key={revisionId}>
                                {revisionId}
                                <Button type="button" size="sm" variant="secondary" onClick={() => setSubmittedRevisionIds(
                                    (ids) => ids.filter((id) => id !== revisionId),
                                )}>حذف من الطلب</Button>
                            </li>
                        ))}
                    </ol>
                    {generation.isError || assessmentsQuery.isError || revisionsQuery.isError ? (
                        <Feedback tone="danger">{message(generation.error ?? assessmentsQuery.error ?? revisionsQuery.error)}</Feedback>
                    ) : null}
                    <Button type="submit" disabled={generation.isPending || generatorVersion.trim() === '' || seed.trim() === '' || submittedRevisionIds.length === 0}>إنشاء الامتحان</Button>
                    <Button type="button" variant="secondary" onClick={clearGeneration}>إلغاء</Button>
                </form>
            ) : null}

            {generationResult ? (
                <section className="admin-content-form" aria-label="نتيجة توليد الامتحان">
                    <h4>تم إنشاء الامتحان</h4>
                    <p>المعرف: {generationResult.id}</p>
                    <p>وقت الإنشاء: {generationResult.generated_at ?? 'غير متاح'}</p>
                    <p>نسخة النموذج: {generationResult.exam_template_version_id}</p>
                    <p>إصدار المنهج: {generationResult.curriculum_version_id}</p>
                    <p>ترتيب الطلب المرسل — ليس إثباتًا لترتيب التخزين:</p>
                    <ol aria-label="ترتيب الطلب المرسل">
                        {lastSubmittedOrder.map((revisionId) => <li key={revisionId}>{revisionId}</li>)}
                    </ol>
                    <p>هويات العناصر المعادة:</p>
                    <ul>
                        {generationResult.items.map((item) => (
                            <li key={item.assessment_item_revision_id}>
                                {item.assessment_item_revision_id} / {item.assessment_item_id}
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </section>
    );
}

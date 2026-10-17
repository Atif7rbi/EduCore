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
    createTeacherAssessmentRevision,
    createTeacherAssessmentRevisionSkill,
    deleteTeacherAssessmentRevisionSkill,
    fetchTeacherAssessmentRevisions,
    fetchTeacherAssessmentRevisionSkills,
    fetchTeacherSkillPlacements,
    fetchTeacherTopics,
    releaseTeacherAssessmentRevision,
    teacherAssessmentRevisionsKey,
    teacherAssessmentRevisionSkillsKey,
    teacherSkillPlacementsKey,
    teacherTopicsKey,
    updateTeacherAssessmentRevision,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherAssessmentItem,
    TeacherAssessmentItemRevision,
} from './types';

interface TeacherAssessmentRevisionEditorProps {
    authenticatedUserId: string;
    context: TeacherContext;
    item: TeacherAssessmentItem;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
}

function errorMessage(error: unknown): string {
    return error instanceof EduCoreApiError
        ? error.message
        : 'تعذر إكمال عملية نسخة التقييم.';
}

function fieldErrors(error: unknown, field: string): string[] {
    return error instanceof EduCoreApiError
        ? error.details?.[field] ?? []
        : [];
}

function unavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

function jsonObject(value: string): Record<string, unknown> | null {
    try {
        const parsed: unknown = JSON.parse(value);

        return parsed !== null
            && typeof parsed === 'object'
            && !Array.isArray(parsed)
            ? parsed as Record<string, unknown>
            : null;
    } catch {
        return null;
    }
}

export function TeacherAssessmentRevisionEditor({
    authenticatedUserId,
    context,
    item,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
}: TeacherAssessmentRevisionEditorProps) {
    const queryClient = useQueryClient();
    const editable = context.versionStatus === 'draft'
        && item.status === 'draft';
    const key = teacherAssessmentRevisionsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
        item.id,
    );
    const revisions = useQuery({
        queryKey: key,
        queryFn: ({ signal }) => fetchTeacherAssessmentRevisions(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            item.id,
            signal,
        ),
    });
    const topics = useQuery({
        queryKey: teacherTopicsKey(authenticatedUserId, context.assignmentId, context.curriculumId, context.curriculumVersionId),
        queryFn: ({ signal }) => fetchTeacherTopics(context.assignmentId, context.curriculumId, context.curriculumVersionId, signal),
    });
    const placements = useQuery({
        queryKey: teacherSkillPlacementsKey(authenticatedUserId, context.assignmentId, context.curriculumId, context.curriculumVersionId),
        queryFn: ({ signal }) => fetchTeacherSkillPlacements(context.assignmentId, context.curriculumId, context.curriculumVersionId, signal),
    });
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<TeacherAssessmentItemRevision | null>(null);
    const [selectedRevisionId, setSelectedRevisionId] = useState<string | null>(null);
    const [revisionNumber, setRevisionNumber] = useState('1');
    const [topicId, setTopicId] = useState('');
    const [difficulty, setDifficulty] = useState<'easy' | 'medium' | 'hard'>('medium');
    const [content, setContent] = useState('{\n  \n}');
    const [contentSchemaVersion, setContentSchemaVersion] = useState('1');
    const [scoring, setScoring] = useState('{\n  \n}');
    const [scoringSchemaVersion, setScoringSchemaVersion] = useState('1');
    const [placementId, setPlacementId] = useState('');
    const [role, setRole] = useState<'primary' | 'supporting'>('primary');
    const selectedRevision = useMemo(
        () => (revisions.data ?? []).find((revision) => revision.id === selectedRevisionId) ?? null,
        [revisions.data, selectedRevisionId],
    );
    const skills = useQuery({
        queryKey: teacherAssessmentRevisionSkillsKey(authenticatedUserId, context.assignmentId, context.curriculumId, context.curriculumVersionId, selectedRevisionId ?? ''),
        queryFn: ({ signal }) => fetchTeacherAssessmentRevisionSkills(context.assignmentId, context.curriculumId, context.curriculumVersionId, item.id, selectedRevisionId!, signal),
        enabled: selectedRevisionId !== null,
    });
    const validRevisions = useMemo(
        () => (revisions.data ?? []).filter((revision) => revision.assessment_item_id === item.id && revision.curriculum_version_id === context.curriculumVersionId),
        [context.curriculumVersionId, item.id, revisions.data],
    );
    const validTopics = useMemo(
        () => (topics.data ?? []).filter((topic) => topic.curriculum_version_id === context.curriculumVersionId),
        [context.curriculumVersionId, topics.data],
    );
    const validPlacements = useMemo(
        () => (placements.data ?? []).filter((placement) => placement.curriculum_version_id === context.curriculumVersionId),
        [context.curriculumVersionId, placements.data],
    );
    const dirty = createOpen || editing !== null || revisionNumber !== '1' || topicId !== '' || content !== '{\n  \n}' || scoring !== '{\n  \n}' || placementId !== '';

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        if (unavailable(revisions.error)) {
            onContextUnavailable();
        }
    }, [onContextUnavailable, revisions.error]);

    async function refresh() {
        await queryClient.invalidateQueries({ queryKey: key });
        if (selectedRevisionId) {
            await queryClient.invalidateQueries({
                queryKey: teacherAssessmentRevisionSkillsKey(authenticatedUserId, context.assignmentId, context.curriculumId, context.curriculumVersionId, selectedRevisionId),
            });
        }
    }

    async function conflict(error: unknown) {
        if (unavailable(error)) {
            onContextUnavailable();

            return;
        }

        if (error instanceof EduCoreApiError && error.status === 409) {
            await onLifecycleConflict();
            await refresh();
        }
    }

    function payload() {
        return {
            revision_number: Number(revisionNumber),
            primary_topic_id: topicId || null,
            difficulty,
            content_payload: jsonObject(content)!,
            content_schema_version: Number(contentSchemaVersion),
            scoring_payload: jsonObject(scoring)!,
            scoring_schema_version: Number(scoringSchemaVersion),
        };
    }

    function clearEditor() {
        setCreateOpen(false);
        setEditing(null);
        setRevisionNumber('1');
        setTopicId('');
        setDifficulty('medium');
        setContent('{\n  \n}');
        setContentSchemaVersion('1');
        setScoring('{\n  \n}');
        setScoringSchemaVersion('1');
    }

    const create = useMutation({
        mutationFn: () => createTeacherAssessmentRevision(context.assignmentId, context.curriculumId, context.curriculumVersionId, item.id, payload()),
        onSuccess: async () => {
            clearEditor();
            await refresh();
        },
        onError: conflict,
    });
    const update = useMutation({
        mutationFn: () => updateTeacherAssessmentRevision(context.assignmentId, context.curriculumId, context.curriculumVersionId, item.id, editing!.id, payload()),
        onSuccess: async () => {
            clearEditor();
            await refresh();
        },
        onError: conflict,
    });
    const addSkill = useMutation({
        mutationFn: () => createTeacherAssessmentRevisionSkill(context.assignmentId, context.curriculumId, context.curriculumVersionId, item.id, selectedRevisionId!, placementId, role),
        onSuccess: async () => {
            setPlacementId('');
            await refresh();
        },
        onError: conflict,
    });
    const removeSkill = useMutation({
        mutationFn: (associationId: string) => deleteTeacherAssessmentRevisionSkill(context.assignmentId, context.curriculumId, context.curriculumVersionId, item.id, selectedRevisionId!, associationId),
        onSuccess: refresh,
        onError: conflict,
    });
    const release = useMutation({
        mutationFn: (revisionId: string) => releaseTeacherAssessmentRevision(context.assignmentId, context.curriculumId, context.curriculumVersionId, item.id, revisionId),
        onSuccess: refresh,
        onError: conflict,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || !Number.isInteger(Number(revisionNumber))
            || Number(revisionNumber) < 1
            || jsonObject(content) === null
            || jsonObject(scoring) === null
            || Number(contentSchemaVersion) < 1
            || Number(scoringSchemaVersion) < 1
        ) {
            return;
        }

        if (editing) {
            update.mutate();
        } else {
            create.mutate();
        }
    }

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <h3 className="foundation-card__title">نسخ عنصر التقييم</h3>
                {!editable ? <Feedback>هذه النسخ للقراءة فقط.</Feedback> : <Button type="button" onClick={() => {
                    setCreateOpen((open) => !open);
                    setEditing(null);
                }}>{createOpen ? 'إغلاق نموذج النسخة' : 'إضافة نسخة'}</Button>}

                {(createOpen || editing) && editable ? <form className="admin-content-form" onSubmit={submit}>
                    <label>رقم النسخة<input aria-label="رقم نسخة التقييم" type="number" min="1" value={revisionNumber} onChange={(event) => setRevisionNumber(event.target.value)} /></label>
                    <label>الموضوع الرئيس<select aria-label="موضوع نسخة التقييم" value={topicId} onChange={(event) => setTopicId(event.target.value)}><option value="">بدون موضوع</option>{validTopics.map((topic) => <option key={topic.id} value={topic.id}>{topic.name}</option>)}</select></label>
                    <label>الصعوبة<select aria-label="صعوبة نسخة التقييم" value={difficulty} onChange={(event) => setDifficulty(event.target.value as 'easy' | 'medium' | 'hard')}><option value="easy">سهل</option><option value="medium">متوسط</option><option value="hard">صعب</option></select></label>
                    <label>محتوى JSON<textarea aria-label="محتوى نسخة التقييم" value={content} onChange={(event) => setContent(event.target.value)} /></label>
                    <label>إصدار مخطط المحتوى<input aria-label="إصدار مخطط محتوى التقييم" type="number" min="1" value={contentSchemaVersion} onChange={(event) => setContentSchemaVersion(event.target.value)} /></label>
                    <label>تصحيح JSON<textarea aria-label="تصحيح نسخة التقييم" value={scoring} onChange={(event) => setScoring(event.target.value)} /></label>
                    <label>إصدار مخطط التصحيح<input aria-label="إصدار مخطط تصحيح التقييم" type="number" min="1" value={scoringSchemaVersion} onChange={(event) => setScoringSchemaVersion(event.target.value)} /></label>
                    {jsonObject(content) === null || jsonObject(scoring) === null ? <Feedback tone="danger">يجب أن تكون حقول JSON كائنات صالحة.</Feedback> : null}
                    {['revision_number', 'primary_topic_id', 'content_payload', 'content_schema_version', 'scoring_payload', 'scoring_schema_version'].flatMap((field) => fieldErrors(editing ? update.error : create.error, field)).map((item) => <Feedback key={item} tone="danger">{item}</Feedback>)}
                    {create.isError || update.isError ? <Feedback tone="danger">{errorMessage(create.error ?? update.error)}</Feedback> : null}
                    <Button type="submit" disabled={create.isPending || update.isPending}>{editing ? 'حفظ النسخة' : 'إنشاء النسخة'}</Button>
                    <Button type="button" variant="secondary" onClick={clearEditor}>إلغاء</Button>
                </form> : null}

                {revisions.isPending ? <p>جار تحميل النسخ…</p> : null}
                {revisions.isError ? <Feedback tone="danger">{errorMessage(revisions.error)}</Feedback> : null}
                {validRevisions.map((revision) => <article key={revision.id} className="admin-content-list__item">
                    <div><strong>نسخة {revision.revision_number}</strong><p className="admin-content-list__meta">{revision.released_at ? 'مُصدرة' : 'مسودة'} · {revision.difficulty}</p></div>
                    <div className="foundation-stack">
                        <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedRevisionId(revision.id)}>المهارات</Button>
                        {editable && revision.released_at === null ? <Button type="button" size="sm" variant="secondary" onClick={() => {
                            setEditing(revision);
                            setRevisionNumber(String(revision.revision_number));
                            setTopicId(revision.primary_topic_id ?? '');
                            setDifficulty(revision.difficulty);
                            setContent(JSON.stringify(revision.content_payload, null, 2));
                            setContentSchemaVersion(String(revision.content_schema_version));
                            setScoring(JSON.stringify(revision.scoring_payload, null, 2));
                            setScoringSchemaVersion(String(revision.scoring_schema_version));
                        }}>تعديل</Button> : null}
                        {editable && revision.released_at === null ? <Button type="button" size="sm" onClick={() => {
                            if (window.confirm('هل تريد إصدار هذه النسخة؟')) release.mutate(revision.id);
                        }}>إصدار</Button> : null}
                    </div>
                </article>)}

                {selectedRevision && <div className="foundation-stack">
                    <h4>مهارات النسخة {selectedRevision.revision_number}</h4>
                    {skills.isError ? <Feedback tone="danger">{errorMessage(skills.error)}</Feedback> : null}
                    {(skills.data ?? []).filter((skill) => skill.assessment_item_revision_id === selectedRevision.id && skill.curriculum_version_id === context.curriculumVersionId).map((skill) => <div key={skill.id}>{skill.role}: {skill.skill_version_placement_id}{editable && selectedRevision.released_at === null ? <Button type="button" size="sm" variant="secondary" onClick={() => removeSkill.mutate(skill.id)}>إزالة</Button> : null}</div>)}
                    {editable && selectedRevision.released_at === null ? <form className="admin-content-form" onSubmit={(event) => {
                        event.preventDefault();
                        if (placementId !== '') addSkill.mutate();
                    }}>
                        <label>مهارة الإصدار<select aria-label="مهارة نسخة التقييم" value={placementId} onChange={(event) => setPlacementId(event.target.value)}><option value="">اختر مهارة</option>{validPlacements.map((placement) => <option key={placement.id} value={placement.id}>{placement.skill?.name ?? placement.id}</option>)}</select></label>
                        <label>الدور<select aria-label="دور مهارة نسخة التقييم" value={role} onChange={(event) => setRole(event.target.value as 'primary' | 'supporting')}><option value="primary">رئيس</option><option value="supporting">داعم</option></select></label>
                        {addSkill.isError ? <Feedback tone="danger">{errorMessage(addSkill.error)}</Feedback> : null}
                        <Button type="submit" disabled={placementId === '' || addSkill.isPending}>إضافة مهارة</Button>
                    </form> : null}
                </div>}
            </div>
        </Surface>
    );
}

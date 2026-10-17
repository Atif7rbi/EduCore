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
    createTeacherLessonRevision,
    createTeacherLessonRevisionSkill,
    deleteTeacherLessonRevisionSkill,
    fetchTeacherLessonRevisionSkills,
    fetchTeacherLessonRevisions,
    fetchTeacherSkillPlacements,
    fetchTeacherTopics,
    releaseTeacherLessonRevision,
    teacherLessonRevisionsKey,
    teacherLessonRevisionSkillsKey,
    teacherSkillPlacementsKey,
    teacherTopicsKey,
    updateTeacherLessonRevision,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherLesson,
    TeacherLessonRevision,
} from './types';

interface TeacherLessonRevisionEditorProps {
    authenticatedUserId: string;
    context: TeacherContext;
    lesson: TeacherLesson;
    onContextUnavailable: () => void;
    onDirtyChange: (dirty: boolean) => void;
    onLifecycleConflict: () => Promise<void>;
}

function errorMessage(error: unknown): string {
    return error instanceof EduCoreApiError
        ? error.message
        : 'تعذر إكمال عملية نسخة الدرس.';
}

function fieldErrors(
    error: unknown,
    field: string,
): string[] {
    return error instanceof EduCoreApiError
        ? error.details?.[field] ?? []
        : [];
}

function unavailable(error: unknown): boolean {
    return error instanceof EduCoreApiError
        && (error.status === 403 || error.status === 404);
}

function objectValue(
    value: string,
): Record<string, unknown> | null {
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

export function TeacherLessonRevisionEditor({
    authenticatedUserId,
    context,
    lesson,
    onContextUnavailable,
    onDirtyChange,
    onLifecycleConflict,
}: TeacherLessonRevisionEditorProps) {
    const queryClient = useQueryClient();
    const editable = context.versionStatus === 'draft';
    const revisionsKey = teacherLessonRevisionsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
        lesson.id,
    );
    const revisions = useQuery({
        queryKey: revisionsKey,
        queryFn: ({ signal }) => fetchTeacherLessonRevisions(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            lesson.id,
            signal,
        ),
    });
    const topics = useQuery({
        queryKey: teacherTopicsKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
        ),
        queryFn: ({ signal }) => fetchTeacherTopics(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const placements = useQuery({
        queryKey: teacherSkillPlacementsKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
        ),
        queryFn: ({ signal }) => fetchTeacherSkillPlacements(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
    });
    const [createOpen, setCreateOpen] = useState(false);
    const [revisionNumber, setRevisionNumber] = useState('1');
    const [topicId, setTopicId] = useState('');
    const [content, setContent] = useState('{\n  \n}');
    const [schemaVersion, setSchemaVersion] = useState('1');
    const [editing, setEditing] =
        useState<TeacherLessonRevision | null>(null);
    const [selectedRevisionId, setSelectedRevisionId] =
        useState<string | null>(null);
    const selectedRevision = useMemo(
        () => (revisions.data ?? []).find(
            (revision) => revision.id === selectedRevisionId,
        ) ?? null,
        [revisions.data, selectedRevisionId],
    );
    const skills = useQuery({
        queryKey: teacherLessonRevisionSkillsKey(
            authenticatedUserId,
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            selectedRevisionId ?? '',
        ),
        queryFn: ({ signal }) => fetchTeacherLessonRevisionSkills(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            lesson.id,
            selectedRevisionId!,
            signal,
        ),
        enabled: selectedRevisionId !== null,
    });
    const [placementId, setPlacementId] = useState('');

    useEffect(() => {
        if (
            revisions.error instanceof EduCoreApiError
            && (revisions.error.status === 403 || revisions.error.status === 404)
        ) {
            onContextUnavailable();
        }
    }, [onContextUnavailable, revisions.error]);

    const dirty = createOpen
        || editing !== null
        || revisionNumber !== '1'
        || topicId !== ''
        || content !== '{\n  \n}'
        || schemaVersion !== '1'
        || placementId !== '';

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    async function refresh() {
        await queryClient.invalidateQueries({
            queryKey: revisionsKey,
        });
        if (selectedRevisionId !== null) {
            await queryClient.invalidateQueries({
                queryKey: teacherLessonRevisionSkillsKey(
                    authenticatedUserId,
                    context.assignmentId,
                    context.curriculumId,
                    context.curriculumVersionId,
                    selectedRevisionId,
                ),
            });
        }
    }

    async function conflict(error: unknown) {
        if (unavailable(error)) {
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
    }

    const createRevision = useMutation({
        mutationFn: () => createTeacherLessonRevision(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            lesson.id,
            {
                revision_number: Number(revisionNumber),
                primary_topic_id: topicId,
                content_payload: objectValue(content)!,
                content_schema_version: Number(schemaVersion),
            },
        ),
        onSuccess: async () => {
            setCreateOpen(false);
            setRevisionNumber('1');
            setTopicId('');
            setContent('{\n  \n}');
            setSchemaVersion('1');
            await refresh();
        },
        onError: conflict,
    });
    const updateRevision = useMutation({
        mutationFn: () => updateTeacherLessonRevision(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            lesson.id,
            editing!.id,
            {
                revision_number: Number(revisionNumber),
                primary_topic_id: topicId,
                content_payload: objectValue(content)!,
                content_schema_version: Number(schemaVersion),
            },
        ),
        onSuccess: async () => {
            setEditing(null);
            await refresh();
        },
        onError: conflict,
    });
    const addSkill = useMutation({
        mutationFn: () => createTeacherLessonRevisionSkill(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            lesson.id,
            selectedRevisionId!,
            placementId,
        ),
        onSuccess: async () => {
            setPlacementId('');
            await refresh();
        },
        onError: conflict,
    });
    const removeSkill = useMutation({
        mutationFn: (associationId: string) =>
            deleteTeacherLessonRevisionSkill(
                context.assignmentId,
                context.curriculumId,
                context.curriculumVersionId,
                lesson.id,
                selectedRevisionId!,
                associationId,
            ),
        onSuccess: refresh,
        onError: conflict,
    });
    const release = useMutation({
        mutationFn: (revisionId: string) =>
            releaseTeacherLessonRevision(
                context.assignmentId,
                context.curriculumId,
                context.curriculumVersionId,
                lesson.id,
                revisionId,
            ),
        onSuccess: refresh,
        onError: conflict,
    });

    function beginEdit(revision: TeacherLessonRevision) {
        setEditing(revision);
        setCreateOpen(false);
        setRevisionNumber(String(revision.revision_number));
        setTopicId(revision.primary_topic_id);
        setContent(JSON.stringify(revision.content_payload, null, 2));
        setSchemaVersion(String(revision.content_schema_version));
    }

    function valid(): boolean {
        return editable
            && Number.isInteger(Number(revisionNumber))
            && Number(revisionNumber) >= 1
            && topicId !== ''
            && objectValue(content) !== null
            && Number.isInteger(Number(schemaVersion))
            && Number(schemaVersion) >= 1;
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!valid()) {
            return;
        }

        if (editing !== null) {
            updateRevision.mutate();

            return;
        }

        createRevision.mutate();
    }

    const revisionsForLesson = (revisions.data ?? []).filter(
        (revision) => revision.lesson_id === lesson.id
            && revision.curriculum_version_id === context.curriculumVersionId,
    );

    return (
        <Surface className="foundation-card">
            <div className="foundation-stack">
                <div>
                    <h3 className="foundation-card__title">
                        نسخ الدرس: {lesson.title}
                    </h3>
                    <p className="foundation-page__description">
                        النسخ المنشورة للقراءة فقط.
                    </p>
                </div>

                {!editable ? (
                    <Feedback>
                        هذا الإصدار للقراءة فقط.
                    </Feedback>
                ) : (
                    <Button
                        type="button"
                        onClick={() => {
                            setEditing(null);
                            setCreateOpen((open) => !open);
                        }}
                    >
                        {createOpen ? 'إغلاق' : 'إضافة نسخة درس'}
                    </Button>
                )}

                {(createOpen || editing !== null) && editable ? (
                    <form
                        className="admin-content-form"
                        onSubmit={submit}
                    >
                        <label>
                            رقم النسخة
                            <input
                                aria-label="رقم نسخة الدرس"
                                min="1"
                                onChange={(event) => setRevisionNumber(event.target.value)}
                                required
                                step="1"
                                type="number"
                                value={revisionNumber}
                            />
                        </label>
                        <label>
                            الموضوع الأساسي
                            <select
                                aria-label="موضوع نسخة الدرس"
                                onChange={(event) => setTopicId(event.target.value)}
                                required
                                value={topicId}
                            >
                                <option value="">اختر الموضوع</option>
                                {(topics.data ?? [])
                                    .filter((topic) => topic.curriculum_version_id === context.curriculumVersionId)
                                    .map((topic) => (
                                        <option key={topic.id} value={topic.id}>
                                            {topic.name}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        <label>
                            محتوى JSON
                            <textarea
                                aria-label="محتوى نسخة الدرس JSON"
                                onChange={(event) => setContent(event.target.value)}
                                required
                                value={content}
                            />
                        </label>
                        <label>
                            إصدار مخطط المحتوى
                            <input
                                aria-label="إصدار مخطط محتوى الدرس"
                                min="1"
                                onChange={(event) => setSchemaVersion(event.target.value)}
                                required
                                step="1"
                                type="number"
                                value={schemaVersion}
                            />
                        </label>
                        {objectValue(content) === null ? (
                            <Feedback tone="danger">
                                أدخل كائن JSON صالحًا.
                            </Feedback>
                        ) : null}
                        {['revision_number', 'primary_topic_id', 'content_payload', 'content_schema_version'].flatMap(
                            (field) => fieldErrors(
                                editing === null
                                    ? createRevision.error
                                    : updateRevision.error,
                                field,
                            ),
                        ).map((item) => (
                            <Feedback key={item} tone="danger">
                                {item}
                            </Feedback>
                        ))}
                        {(createRevision.isError || updateRevision.isError) ? (
                            <Feedback tone="danger">
                                {errorMessage(
                                    editing === null
                                        ? createRevision.error
                                        : updateRevision.error,
                                )}
                            </Feedback>
                        ) : null}
                        <Button
                            disabled={
                                createRevision.isPending
                                || updateRevision.isPending
                                || !valid()
                            }
                            type="submit"
                        >
                            {editing === null ? 'حفظ النسخة' : 'حفظ التعديل'}
                        </Button>
                        <Button
                            onClick={() => {
                                setEditing(null);
                                setCreateOpen(false);
                            }}
                            type="button"
                            variant="secondary"
                        >
                            إلغاء
                        </Button>
                    </form>
                ) : null}

                {revisionsForLesson.map((revision) => (
                    <article
                        className="admin-content-list__item"
                        key={revision.id}
                    >
                        <div>
                            <strong>النسخة {revision.revision_number}</strong>
                            <p className="admin-content-list__meta">
                                {revision.released_at === null
                                    ? 'مسودة'
                                    : 'منشورة'}
                            </p>
                        </div>
                        <div className="foundation-stack">
                            <Button
                                onClick={() => setSelectedRevisionId(revision.id)}
                                size="sm"
                                type="button"
                                variant="secondary"
                            >
                                المهارات
                            </Button>
                            {editable && revision.released_at === null ? (
                                <>
                                    <Button
                                        onClick={() => beginEdit(revision)}
                                        size="sm"
                                        type="button"
                                        variant="secondary"
                                    >
                                        تعديل
                                    </Button>
                                    <Button
                                        onClick={() => {
                                            if (window.confirm('سيتم تثبيت نسخة الدرس. هل تريد المتابعة؟')) {
                                                release.mutate(revision.id);
                                            }
                                        }}
                                        size="sm"
                                        type="button"
                                    >
                                        نشر النسخة
                                    </Button>
                                </>
                            ) : null}
                        </div>
                    </article>
                ))}

                {selectedRevision !== null ? (
                    <section aria-label="مهارات نسخة الدرس">
                        <h4>مهارات النسخة {selectedRevision.revision_number}</h4>
                        {editable && selectedRevision.released_at === null ? (
                            <label>
                                إضافة مهارة
                                <select
                                    aria-label="مهارة نسخة الدرس"
                                    onChange={(event) => setPlacementId(event.target.value)}
                                    value={placementId}
                                >
                                    <option value="">اختر المهارة</option>
                                    {(placements.data ?? [])
                                        .filter((placement) => placement.curriculum_version_id === context.curriculumVersionId)
                                        .map((placement) => (
                                            <option key={placement.id} value={placement.id}>
                                                {placement.skill?.name ?? placement.id}
                                            </option>
                                        ))}
                                </select>
                                <Button
                                    disabled={placementId === '' || addSkill.isPending}
                                    onClick={() => addSkill.mutate()}
                                    type="button"
                                >
                                    إضافة مهارة
                                </Button>
                            </label>
                        ) : null}
                        {(skills.data ?? [])
                            .filter((skill) => skill.lesson_revision_id === selectedRevision.id
                                && skill.curriculum_version_id === context.curriculumVersionId)
                            .map((skill) => (
                                <div key={skill.id}>
                                    <code>{skill.skill_version_placement_id}</code>
                                    {editable && selectedRevision.released_at === null ? (
                                        <Button
                                            onClick={() => removeSkill.mutate(skill.id)}
                                            size="sm"
                                            type="button"
                                            variant="secondary"
                                        >
                                            إزالة
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                    </section>
                ) : null}
            </div>
        </Surface>
    );
}

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
    addTeacherPracticeItems,
    deleteTeacherPracticeItem,
    fetchTeacherAssessmentItems,
    fetchTeacherAssessmentRevisions,
    fetchTeacherPracticeItems,
    teacherAssessmentItemsKey,
    teacherAssessmentRevisionsKey,
    teacherPracticeItemsKey,
} from './api';
import type {
    TeacherContext,
} from './TeacherContextSelector';
import type {
    TeacherPracticeActivity,
} from './types';

interface Props {
    authenticatedUserId: string;
    context: TeacherContext;
    practice: TeacherPracticeActivity;
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
        return 'تعذر إكمال عملية عضوية التدريب.';
    }

    const details = Object.values(error.details ?? {}).flat();

    return details.length > 0
        ? details.join(' ')
        : error.message;
}

export function TeacherPracticeMembershipEditor({
    authenticatedUserId,
    context,
    practice,
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

    const editable = context.versionStatus === 'draft';
    const [assessmentId, setAssessmentId] = useState('');
    const [candidateRevisionId, setCandidateRevisionId] = useState('');
    const [submittedRevisionIds, setSubmittedRevisionIds] = useState<string[]>([]);
    const [displayOrder, setDisplayOrder] = useState('0');
    const membershipKey = teacherPracticeItemsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
        practice.id,
    );
    const assessmentKey = teacherAssessmentItemsKey(
        authenticatedUserId,
        context.assignmentId,
        context.curriculumId,
        context.curriculumVersionId,
    );
    const membershipsQuery = useQuery({
        queryKey: membershipKey,
        queryFn: ({ signal }) => fetchTeacherPracticeItems(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            practice.id,
            signal,
        ),
    });
    const assessmentsQuery = useQuery({
        queryKey: assessmentKey,
        queryFn: ({ signal }) => fetchTeacherAssessmentItems(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            signal,
        ),
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
        enabled: assessmentId !== '',
    });
    const memberships = useMemo(
        () => (membershipsQuery.data ?? []).filter(
            (item) => item.practice_activity_id === practice.id
                && item.curriculum_version_id === context.curriculumVersionId,
        ),
        [context.curriculumVersionId, membershipsQuery.data, practice.id],
    );
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
    const dirty = submittedRevisionIds.length > 0 || displayOrder !== '0';

    useEffect(() => {
        onDirtyChange(dirty);

        return () => onDirtyChange(false);
    }, [dirty, onDirtyChange]);

    useEffect(() => {
        const error = membershipsQuery.error
            ?? assessmentsQuery.error
            ?? revisionsQuery.error;

        if (unavailable(error)) {
            setSubmittedRevisionIds([]);
            onContextUnavailable();
        }
    }, [
        assessmentsQuery.error,
        membershipsQuery.error,
        onContextUnavailable,
        revisionsQuery.error,
    ]);

    async function refresh() {
        await queryClient.invalidateQueries({ queryKey: membershipKey });
    }

    async function handleError(error: unknown) {
        if (!mountedRef.current) return;

        if (unavailable(error)) {
            setSubmittedRevisionIds([]);
            onContextUnavailable();

            return;
        }

        if (error instanceof EduCoreApiError && error.status === 409) {
            await onLifecycleConflict();
            await onParentRefresh();
            await refresh();
        }
    }

    const add = useMutation({
        mutationFn: () => addTeacherPracticeItems(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            practice.id,
            {
                assessment_item_revision_ids: submittedRevisionIds,
                display_order: Number(displayOrder),
            },
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            setSubmittedRevisionIds([]);
            setAssessmentId('');
            setCandidateRevisionId('');
            setDisplayOrder('0');
            await refresh();
        },
        onError: handleError,
    });
    const remove = useMutation({
        mutationFn: (membershipId: string) => deleteTeacherPracticeItem(
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            practice.id,
            membershipId,
        ),
        onSuccess: async () => {
            if (!mountedRef.current) return;
            await refresh();
        },
        onError: handleError,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || submittedRevisionIds.length === 0
            || !Number.isInteger(Number(displayOrder))
            || Number(displayOrder) < 0
        ) {
            return;
        }

        add.mutate();
    }

    return (
        <section className="foundation-stack" aria-label={'عضويات التدريب: ' + practice.name}>
            <h3>عضويات التدريب: {practice.name}</h3>

            {membershipsQuery.isPending ? <p>جار تحميل العضويات…</p> : null}
            {membershipsQuery.isError ? <Feedback tone="danger">{message(membershipsQuery.error)}</Feedback> : null}
            {membershipsQuery.isSuccess && memberships.length === 0 ? <Feedback>لا توجد عضويات تدريب.</Feedback> : null}

            {memberships.map((membership) => (
                <article className="admin-content-list__item" key={membership.id}>
                    <div>
                        <strong>{membership.assessment_item_revision_id}</strong>
                        <p className="admin-content-list__meta">
                            العنصر: {membership.assessment_item_id} · الترتيب: {membership.display_order}
                        </p>
                    </div>
                    {editable ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            disabled={practice.status === 'active' && memberships.length <= 1}
                            onClick={() => {
                                if (window.confirm('هل تريد إزالة هذه العضوية؟')) {
                                    remove.mutate(membership.id);
                                }
                            }}
                        >
                            إزالة العضوية
                        </Button>
                    ) : null}
                </article>
            ))}

            {editable && practice.status === 'active' && memberships.length <= 1 ? (
                <Feedback>لا يمكن إزالة العضوية الأخيرة من تدريب نشط.</Feedback>
            ) : null}

            {editable ? (
                <form className="admin-content-form" onSubmit={submit}>
                    <label>
                        عنصر التقييم
                        <select
                            aria-label="عنصر تقييم عضوية التدريب"
                            value={assessmentId}
                            onChange={(event) => {
                                setAssessmentId(event.target.value);
                                setCandidateRevisionId('');
                            }}
                        >
                            <option value="">اختر عنصرًا</option>
                            {assessments.map((assessment) => (
                                <option key={assessment.id} value={assessment.id}>
                                    {assessment.internal_label ?? assessment.item_type}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        النسخة المُصدرة
                        <select
                            aria-label="نسخة تقييم عضوية التدريب"
                            value={candidateRevisionId}
                            onChange={(event) => setCandidateRevisionId(event.target.value)}
                        >
                            <option value="">اختر نسخة مُصدرة</option>
                            {releasedRevisions.map((revision) => (
                                <option key={revision.id} value={revision.id}>
                                    نسخة {revision.revision_number}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={candidateRevisionId === ''}
                        onClick={() => {
                            if (
                                candidateRevisionId !== ''
                                && !submittedRevisionIds.includes(candidateRevisionId)
                            ) {
                                setSubmittedRevisionIds((ids) => [...ids, candidateRevisionId]);
                            }
                            setCandidateRevisionId('');
                        }}
                    >
                        أضف إلى ترتيب الإرسال
                    </Button>
                    {submittedRevisionIds.length > 0 ? (
                        <ol aria-label="ترتيب نسخ التقييم المرسلة">
                            {submittedRevisionIds.map((revisionId) => (
                                <li key={revisionId}>
                                    {revisionId}
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        onClick={() => setSubmittedRevisionIds(
                                            (ids) => ids.filter((id) => id !== revisionId),
                                        )}
                                    >
                                        حذف من الطلب
                                    </Button>
                                </li>
                            ))}
                        </ol>
                    ) : null}
                    <label>
                        ترتيب العرض الأول
                        <input
                            aria-label="ترتيب عضوية التدريب"
                            type="number"
                            min="0"
                            step="1"
                            value={displayOrder}
                            onChange={(event) => setDisplayOrder(event.target.value)}
                        />
                    </label>
                    {add.isError || remove.isError || assessmentsQuery.isError || revisionsQuery.isError ? (
                        <Feedback tone="danger">
                            {message(add.error ?? remove.error ?? assessmentsQuery.error ?? revisionsQuery.error)}
                        </Feedback>
                    ) : null}
                    <Button type="submit" disabled={add.isPending || submittedRevisionIds.length === 0}>
                        إضافة العضويات
                    </Button>
                </form>
            ) : <Feedback>عضويات هذا التدريب للقراءة فقط.</Feedback>}
        </section>
    );
}

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
    adminAssessmentItemRevisionsKey,
    adminAssessmentItemsKey,
    adminPracticeActivitiesKey,
    adminPracticeActivityItemsKey,
    createPracticeActivityItem,
    deletePracticeActivityItem,
    fetchAssessmentItemRevisions,
    fetchAssessmentItems,
    fetchPracticeActivityItems,
} from './api';

import type {
    AssessmentItemRevision,
    CurriculumVersion,
    PracticeActivity,
} from './types';

interface PracticeActivityItemsPanelProps {
    version: CurriculumVersion;
    activity: PracticeActivity;
    onClose: () => void;
}

function requestId(error: unknown): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function ItemFailure({
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

function difficultyLabel(
    difficulty: AssessmentItemRevision['difficulty'],
) {
    if (difficulty === 'easy') {
        return 'سهل';
    }

    if (difficulty === 'hard') {
        return 'صعب';
    }

    return 'متوسط';
}

export function PracticeActivityItemsPanel({
    version,
    activity,
    onClose,
}: PracticeActivityItemsPanelProps) {
    const queryClient = useQueryClient();
    const editable = version.status === 'draft';

    const [assessmentItemId, setAssessmentItemId] =
        useState('');

    const itemsQuery = useQuery({
        queryKey: adminPracticeActivityItemsKey(
            activity.id,
        ),
        queryFn: () =>
            fetchPracticeActivityItems(activity.id),
    });

    const assessmentItemsQuery = useQuery({
        queryKey: adminAssessmentItemsKey(version.id),
        queryFn: () => fetchAssessmentItems(version.id),
    });

    const revisionsQuery = useQuery({
        queryKey: adminAssessmentItemRevisionsKey(
            assessmentItemId,
        ),
        queryFn: () =>
            fetchAssessmentItemRevisions(
                assessmentItemId,
            ),
        enabled: assessmentItemId !== '',
    });

    const selectedRevision = useMemo(() => {
        const revisions = [
            ...(revisionsQuery.data ?? []),
        ].sort(
            (left, right) =>
                right.revision_number
                - left.revision_number,
        );

        if (activity.status === 'active') {
            return revisions.find(
                (revision) =>
                    revision.released_at !== null,
            ) ?? null;
        }

        return revisions[0] ?? null;
    }, [activity.status, revisionsQuery.data]);

    const nextDisplayOrder = useMemo(() => {
        const items = itemsQuery.data ?? [];

        if (items.length === 0) {
            return 0;
        }

        return Math.max(
            ...items.map((item) => item.display_order),
        ) + 1;
    }, [itemsQuery.data]);

    const itemNames = useMemo(
        () => new Map(
            (assessmentItemsQuery.data ?? []).map(
                (item) => [
                    item.id,
                    item.internal_label
                    ?? 'سؤال بدون عنوان',
                ],
            ),
        ),
        [assessmentItemsQuery.data],
    );

    async function invalidate() {
        await Promise.all([
            queryClient.invalidateQueries({
                queryKey:
                    adminPracticeActivityItemsKey(
                        activity.id,
                    ),
            }),
            queryClient.invalidateQueries({
                queryKey:
                    adminPracticeActivitiesKey(
                        version.id,
                    ),
            }),
        ]);
    }

    const createMutation = useMutation({
        mutationFn: () => {
            if (!selectedRevision) {
                throw new Error(
                    'No eligible question revision.',
                );
            }

            return createPracticeActivityItem(
                activity.id,
                selectedRevision.id,
                nextDisplayOrder,
            );
        },
        onSuccess: async () => {
            setAssessmentItemId('');
            await invalidate();
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (
            practiceActivityItemId: string,
        ) =>
            deletePracticeActivityItem(
                activity.id,
                practiceActivityItemId,
            ),
        onSuccess: invalidate,
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || assessmentItemId === ''
            || !selectedRevision
        ) {
            return;
        }

        createMutation.mutate();
    }

    const currentItemCount =
        itemsQuery.data?.length
        ?? activity.items_count
        ?? 0;

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h3 className="foundation-card__title">
                            أسئلة التدريب — {activity.name}
                        </h3>
                        <p className="foundation-page__description">
                            اختر الأسئلة التي تظهر في هذا التدريب. يستخدم النظام أحدث محتوى مناسب للسؤال ويرتب الإضافات تلقائيًا.
                        </p>
                    </div>

                    <Button
                        size="sm"
                        variant="secondary"
                        type="button"
                        onClick={onClose}
                    >
                        إغلاق
                    </Button>
                </div>

                {!editable ? (
                    <Feedback>
                        أسئلة التدريب للقراءة فقط لأن المنهج غير متاح للتعديل.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={submit}
                    >
                        <label>
                            السؤال
                            <select
                                aria-label="السؤال المضاف إلى التدريب"
                                value={assessmentItemId}
                                onChange={(event) =>
                                    setAssessmentItemId(
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">
                                    اختر السؤال
                                </option>
                                {assessmentItemsQuery.data?.map(
                                    (item) => (
                                        <option
                                            key={item.id}
                                            value={item.id}
                                        >
                                            {item.internal_label
                                            ?? 'سؤال بدون عنوان'}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>

                        {assessmentItemId !== ''
                        && revisionsQuery.isPending ? (
                            <p>جار تجهيز السؤال…</p>
                        ) : null}

                        {assessmentItemId !== ''
                        && !revisionsQuery.isPending
                        && !selectedRevision ? (
                            <Feedback tone="danger">
                                {activity.status === 'active'
                                    ? 'لا توجد نسخة معتمدة من هذا السؤال يمكن إضافتها إلى تدريب متاح للطلاب.'
                                    : 'هذا السؤال لا يحتوي على محتوى يمكن إضافته إلى التدريب بعد.'}
                            </Feedback>
                        ) : null}

                        <Button
                            type="submit"
                            disabled={
                                createMutation.isPending
                                || assessmentItemId === ''
                                || revisionsQuery.isPending
                                || !selectedRevision
                            }
                        >
                            إضافة السؤال
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <ItemFailure error={createMutation.error}>
                        تعذر إضافة السؤال إلى التدريب.
                    </ItemFailure>
                ) : null}

                {deleteMutation.isError ? (
                    <ItemFailure error={deleteMutation.error}>
                        تعذر إزالة السؤال من التدريب.
                    </ItemFailure>
                ) : null}

                {assessmentItemsQuery.isError ? (
                    <ItemFailure error={assessmentItemsQuery.error}>
                        تعذر تحميل الأسئلة.
                    </ItemFailure>
                ) : null}

                {revisionsQuery.isError ? (
                    <ItemFailure error={revisionsQuery.error}>
                        تعذر تجهيز محتوى السؤال.
                    </ItemFailure>
                ) : null}

                {itemsQuery.isPending ? (
                    <p>جار تحميل أسئلة التدريب…</p>
                ) : itemsQuery.isError ? (
                    <ItemFailure error={itemsQuery.error}>
                        تعذر تحميل أسئلة التدريب.
                    </ItemFailure>
                ) : itemsQuery.data.length === 0 ? (
                    <Feedback>
                        لا توجد أسئلة في هذا التدريب حتى الآن.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {itemsQuery.data.map((item, index) => {
                            const removingLastActiveItem =
                                activity.status === 'active'
                                && currentItemCount <= 1;

                            return (
                                <article
                                    key={item.id}
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            {index + 1}.{' '}
                                            {itemNames.get(
                                                item.assessment_item_id,
                                            ) ?? 'سؤال'}
                                        </strong>
                                        {item.revision ? (
                                            <p className="admin-content-list__meta">
                                                مستوى الصعوبة:{' '}
                                                {difficultyLabel(
                                                    item.revision.difficulty,
                                                )}
                                            </p>
                                        ) : null}
                                    </div>

                                    {editable ? (
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            type="button"
                                            disabled={
                                                deleteMutation.isPending
                                                || removingLastActiveItem
                                            }
                                            onClick={() =>
                                                deleteMutation.mutate(
                                                    item.id,
                                                )
                                            }
                                        >
                                            إزالة السؤال
                                        </Button>
                                    ) : null}
                                </article>
                            );
                        })}
                    </div>
                )}

                {activity.status === 'active'
                && currentItemCount <= 1 ? (
                    <Feedback>
                        لا يمكن إزالة آخر سؤال من تدريب متاح للطلاب. أوقف الإتاحة أولًا إذا كنت تريد إفراغه.
                    </Feedback>
                ) : null}
            </div>
        </Surface>
    );
}

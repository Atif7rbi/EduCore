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

function requestId(
    error: unknown,
): string | null {
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
                <strong>
                    {children}
                </strong>

                {id ? (
                    <p className="learner-read-request-id">
                        رقم الطلب: {id}
                    </p>
                ) : null}
            </div>
        </Feedback>
    );
}

export function PracticeActivityItemsPanel({
    version,
    activity,
    onClose,
}: PracticeActivityItemsPanelProps) {
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft';

    const [
        assessmentItemId,
        setAssessmentItemId,
    ] = useState('');

    const [
        revisionId,
        setRevisionId,
    ] = useState('');

    const [
        displayOrder,
        setDisplayOrder,
    ] = useState('0');

    const itemsQuery =
        useQuery({
            queryKey:
                adminPracticeActivityItemsKey(
                    activity.id,
                ),
            queryFn: () =>
                fetchPracticeActivityItems(
                    activity.id,
                ),
        });

    const assessmentItemsQuery =
        useQuery({
            queryKey:
                adminAssessmentItemsKey(
                    version.id,
                ),
            queryFn: () =>
                fetchAssessmentItems(
                    version.id,
                ),
        });

    const revisionsQuery =
        useQuery({
            queryKey:
                adminAssessmentItemRevisionsKey(
                    assessmentItemId,
                ),
            queryFn: () =>
                fetchAssessmentItemRevisions(
                    assessmentItemId,
                ),
            enabled:
                assessmentItemId !== '',
        });

    const selectableRevisions =
        useMemo(() => {
            const revisions =
                revisionsQuery.data
                ?? [];

            if (
                activity.status
                === 'active'
            ) {
                return revisions.filter(
                    (
                        revision:
                            AssessmentItemRevision,
                    ) =>
                        revision
                            .released_at
                        !== null,
                );
            }

            return revisions;
        }, [
            activity.status,
            revisionsQuery.data,
        ]);

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

    const createMutation =
        useMutation({
            mutationFn: ({
                selectedRevisionId,
                order,
            }: {
                selectedRevisionId:
                    string;
                order: number;
            }) =>
                createPracticeActivityItem(
                    activity.id,
                    selectedRevisionId,
                    order,
                ),
            onSuccess: async () => {
                setRevisionId('');
                setDisplayOrder('0');

                await invalidate();
            },
        });

    const deleteMutation =
        useMutation({
            mutationFn: (
                practiceActivityItemId:
                    string,
            ) =>
                deletePracticeActivityItem(
                    activity.id,
                    practiceActivityItemId,
                ),
            onSuccess:
                invalidate,
        });

    function submit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || revisionId === ''
        ) {
            return;
        }

        const parsedOrder =
            Number(
                displayOrder,
            );

        if (
            !Number.isInteger(
                parsedOrder,
            )
            || parsedOrder < 0
        ) {
            return;
        }

        createMutation.mutate({
            selectedRevisionId:
                revisionId,
            order:
                parsedOrder,
        });
    }

    const currentItemCount =
        itemsQuery.data
            ?.length
        ?? activity.items_count
        ?? 0;

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h3 className="foundation-card__title">
                            عناصر التدريب — {
                                activity.name
                            }
                        </h3>

                        <p className="foundation-page__description">
                            إدارة Assessment Item
                            Revisions داخل مجموعة
                            التدريب وترتيبها.
                        </p>
                    </div>

                    <Button
                        size="sm"
                        variant="secondary"
                        type="button"
                        onClick={
                            onClose
                        }
                    >
                        إغلاق
                    </Button>
                </div>

                {!editable ? (
                    <Feedback>
                        عضوية عناصر التدريب
                        للقراءة فقط لأن
                        CurriculumVersion ليست
                        draft.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submit
                        }
                    >
                        <label>
                            Assessment Item

                            <select
                                aria-label="عنصر تقييم لمجموعة التدريب"
                                value={
                                    assessmentItemId
                                }
                                onChange={(
                                    event,
                                ) => {
                                    setAssessmentItemId(
                                        event
                                            .target
                                            .value,
                                    );
                                    setRevisionId(
                                        '',
                                    );
                                }}
                            >
                                <option value="">
                                    اختر عنصر التقييم
                                </option>

                                {assessmentItemsQuery
                                    .data
                                    ?.map(
                                        (
                                            item,
                                        ) => (
                                            <option
                                                key={
                                                    item.id
                                                }
                                                value={
                                                    item.id
                                                }
                                            >
                                                {
                                                    item.internal_label
                                                    ?? item.item_type
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        <label>
                            Revision

                            <select
                                aria-label="مراجعة عنصر التقييم لمجموعة التدريب"
                                value={
                                    revisionId
                                }
                                disabled={
                                    assessmentItemId
                                        === ''
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setRevisionId(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            >
                                <option value="">
                                    اختر Revision
                                </option>

                                {selectableRevisions
                                    .map(
                                        (
                                            revision,
                                        ) => (
                                            <option
                                                key={
                                                    revision.id
                                                }
                                                value={
                                                    revision.id
                                                }
                                            >
                                                Revision{' '}
                                                {
                                                    revision.revision_number
                                                }
                                                {' — '}
                                                {
                                                    revision.difficulty
                                                }
                                                {
                                                    revision.released_at
                                                        ? ' — released'
                                                        : ' — unreleased'
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        {activity.status
                            === 'active' ? (
                            <Feedback>
                                المجموعة النشطة
                                تقبل released
                                revisions فقط.
                            </Feedback>
                        ) : null}

                        <label>
                            Display Order

                            <input
                                aria-label="ترتيب عنصر مجموعة التدريب"
                                type="number"
                                min="0"
                                step="1"
                                required
                                value={
                                    displayOrder
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setDisplayOrder(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <Button
                            type="submit"
                            disabled={
                                createMutation
                                    .isPending
                                || revisionId
                                    === ''
                            }
                        >
                            إضافة العنصر
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <ItemFailure
                        error={
                            createMutation.error
                        }
                    >
                        تعذر إضافة عنصر التدريب.
                    </ItemFailure>
                ) : null}

                {deleteMutation.isError ? (
                    <ItemFailure
                        error={
                            deleteMutation.error
                        }
                    >
                        تعذر حذف عنصر التدريب.
                    </ItemFailure>
                ) : null}

                {assessmentItemsQuery
                    .isError ? (
                    <ItemFailure
                        error={
                            assessmentItemsQuery
                                .error
                        }
                    >
                        تعذر تحميل عناصر التقييم.
                    </ItemFailure>
                ) : null}

                {revisionsQuery.isError ? (
                    <ItemFailure
                        error={
                            revisionsQuery
                                .error
                        }
                    >
                        تعذر تحميل Assessment Revisions.
                    </ItemFailure>
                ) : null}

                {itemsQuery.isPending ? (
                    <p>
                        جار تحميل عناصر التدريب…
                    </p>
                ) : itemsQuery.isError ? (
                    <ItemFailure
                        error={
                            itemsQuery.error
                        }
                    >
                        تعذر تحميل عناصر التدريب.
                    </ItemFailure>
                ) : itemsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد عناصر في مجموعة
                        التدريب.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {itemsQuery.data.map(
                            (
                                item,
                            ) => {
                                const removingLastActiveItem =
                                    activity.status
                                        === 'active'
                                    && currentItemCount
                                        <= 1;

                                return (
                                    <article
                                        key={
                                            item.id
                                        }
                                        className="admin-content-list__item"
                                    >
                                        <div>
                                            <strong>
                                                Revision{' '}
                                                {
                                                    item.revision
                                                        ?.revision_number
                                                    ?? item
                                                        .assessment_item_revision_id
                                                }
                                            </strong>

                                            <p className="admin-content-list__meta">
                                                الترتيب:{' '}
                                                {
                                                    item.display_order
                                                }

                                                {item.revision ? (
                                                    <>
                                                        {' · '}
                                                        الصعوبة:{' '}
                                                        {
                                                            item.revision
                                                                .difficulty
                                                        }
                                                        {' · '}
                                                        {
                                                            item.revision
                                                                .released_at
                                                                ? 'released'
                                                                : 'unreleased'
                                                        }
                                                    </>
                                                ) : null}
                                            </p>
                                        </div>

                                        {editable ? (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                type="button"
                                                disabled={
                                                    deleteMutation
                                                        .isPending
                                                    || removingLastActiveItem
                                                }
                                                onClick={() =>
                                                    deleteMutation
                                                        .mutate(
                                                            item.id,
                                                        )
                                                }
                                            >
                                                إزالة العنصر
                                            </Button>
                                        ) : null}
                                    </article>
                                );
                            },
                        )}
                    </div>
                )}

                {activity.status === 'active'
                && currentItemCount <= 1 ? (
                    <Feedback>
                        لا يمكن إزالة آخر عنصر
                        من Practice Activity
                        نشطة.
                    </Feedback>
                ) : null}
            </div>
        </Surface>
    );
}

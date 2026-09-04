import {
    FormEvent,
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
    adminAssessmentItemsKey,
    createAssessmentItem,
    fetchAssessmentItems,
    updateAssessmentItem,
} from './api';

import {
    AssessmentItemRevisionsPanel,
} from './AssessmentItemRevisionsPanel';

import type {
    AssessmentItem,
    CurriculumVersion,
} from './types';

interface AssessmentItemsPanelProps {
    version: CurriculumVersion;
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

function statusLabel(
    status: AssessmentItem['status'],
) {
    switch (status) {
        case 'draft':
            return 'مسودة';
        case 'published':
            return 'منشور';
        case 'retired':
            return 'موقوف';
    }
}

export function AssessmentItemsPanel({
    version,
}: AssessmentItemsPanelProps) {
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft';

    const [
        newItemType,
        setNewItemType,
    ] = useState('');

    const [
        newInternalLabel,
        setNewInternalLabel,
    ] = useState('');

    const [
        editingItem,
        setEditingItem,
    ] = useState<AssessmentItem | null>(
        null,
    );

    const [
        authoringItem,
        setAuthoringItem,
    ] = useState<AssessmentItem | null>(
        null,
    );

    const [
        editItemType,
        setEditItemType,
    ] = useState('');

    const [
        editInternalLabel,
        setEditInternalLabel,
    ] = useState('');

    const itemsQuery =
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

    async function invalidate() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminAssessmentItemsKey(
                        version.id,
                    ),
            });
    }

    const createMutation =
        useMutation({
            mutationFn: () =>
                createAssessmentItem(
                    version.id,
                    {
                        item_type:
                            newItemType.trim(),
                        internal_label:
                            newInternalLabel
                                .trim()
                            || null,
                    },
                ),
            onSuccess: async () => {
                setNewItemType('');
                setNewInternalLabel('');

                await invalidate();
            },
        });

    const updateMutation =
        useMutation({
            mutationFn: ({
                itemId,
                itemType,
                internalLabel,
            }: {
                itemId: string;
                itemType: string;
                internalLabel:
                    string | null;
            }) =>
                updateAssessmentItem(
                    itemId,
                    {
                        item_type:
                            itemType,
                        internal_label:
                            internalLabel,
                    },
                ),
            onSuccess: async () => {
                setEditingItem(null);
                setEditItemType('');
                setEditInternalLabel('');

                await invalidate();
            },
        });

    function submitCreate(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || newItemType.trim() === ''
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(
        item: AssessmentItem,
    ) {
        if (
            !editable
            || item.status !== 'draft'
        ) {
            return;
        }

        setEditingItem(item);
        setEditItemType(
            item.item_type,
        );
        setEditInternalLabel(
            item.internal_label
            ?? '',
        );
    }

    function submitEdit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || !editingItem
            || editingItem.status
                !== 'draft'
            || updateMutation.isPending
            || editItemType.trim() === ''
        ) {
            return;
        }

        updateMutation.mutate({
            itemId:
                editingItem.id,
            itemType:
                editItemType.trim(),
            internalLabel:
                editInternalLabel
                    .trim()
                || null,
        });
    }

    return (
        <Surface>
            <div className="foundation-stack admin-content-panel">
                <div>
                    <h2 className="foundation-card__title">
                        بنك الأسئلة
                    </h2>

                    <p className="foundation-page__description">
                        أنشئ الأسئلة ونظّمها داخل المنهج ثم أضف محتوى السؤال وإجابته الصحيحة.
                    </p>
                </div>

                {!editable ? (
                    <Feedback>
                        هذا المنهج للقراءة فقط؛ لا يمكن إنشاء الأسئلة أو تعديلها.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitCreate
                        }
                    >
                        <label>
                            نوع السؤال

                            <input
                                aria-label="نوع السؤال الجديد"
                                maxLength={
                                    255
                                }
                                required
                                value={
                                    newItemType
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setNewItemType(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            عنوان السؤال في البنك

                            <input
                                aria-label="عنوان السؤال في البنك"
                                maxLength={
                                    255
                                }
                                value={
                                    newInternalLabel
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setNewInternalLabel(
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
                            }
                        >
                            إضافة سؤال
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <ItemFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إنشاء السؤال.
                    </ItemFailure>
                ) : null}

                {updateMutation.isError ? (
                    <ItemFailure
                        error={
                            updateMutation
                                .error
                        }
                    >
                        تعذر تعديل السؤال.
                    </ItemFailure>
                ) : null}

                {itemsQuery.isPending ? (
                    <p>
                        جار تحميل الأسئلة…
                    </p>
                ) : itemsQuery.isError ? (
                    <ItemFailure
                        error={
                            itemsQuery.error
                        }
                    >
                        تعذر تحميل الأسئلة.
                    </ItemFailure>
                ) : itemsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد أسئلة في هذا المنهج حتى الآن.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {itemsQuery.data.map(
                            (item) => (
                                <article
                                    key={
                                        item.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    {editingItem
                                        ?.id
                                    === item.id ? (
                                        <form
                                            className="admin-content-form admin-content-list__editor"
                                            onSubmit={
                                                submitEdit
                                            }
                                        >
                                            <label>
                                                نوع السؤال

                                                <input
                                                    aria-label="تعديل نوع السؤال"
                                                    maxLength={
                                                        255
                                                    }
                                                    required
                                                    value={
                                                        editItemType
                                                    }
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditItemType(
                                                            event
                                                                .target
                                                                .value,
                                                        )
                                                    }
                                                />
                                            </label>

                                            <label>
                                                عنوان السؤال في البنك

                                                <input
                                                    aria-label="تعديل عنوان السؤال في البنك"
                                                    maxLength={
                                                        255
                                                    }
                                                    value={
                                                        editInternalLabel
                                                    }
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditInternalLabel(
                                                            event
                                                                .target
                                                                .value,
                                                        )
                                                    }
                                                />
                                            </label>

                                            <div className="admin-content-actions">
                                                <Button
                                                    size="sm"
                                                    type="submit"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                    }
                                                >
                                                    حفظ
                                                </Button>

                                                <Button
                                                    size="sm"
                                                    type="button"
                                                    variant="secondary"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        setEditingItem(
                                                            null,
                                                        )
                                                    }
                                                >
                                                    إلغاء
                                                </Button>
                                            </div>
                                        </form>
                                    ) : (
                                        <>
                                            <div>
                                                <strong>
                                                    {
                                                        item.internal_label
                                                        ?? item.item_type
                                                    }
                                                </strong>

                                                <p className="admin-content-list__meta">
                                                    النوع:{' '}
                                                    {
                                                        item.item_type
                                                    }
                                                    {' · '}
                                                    الحالة:{' '}
                                                    {
                                                        statusLabel(
                                                            item.status,
                                                        )
                                                    }
                                                </p>
                                            </div>

                                            <div className="admin-content-actions">
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    type="button"
                                                    onClick={() =>
                                                        setAuthoringItem(
                                                            item,
                                                        )
                                                    }
                                                >
                                                    إعداد السؤال
                                                </Button>

                                                {editable
                                                && item.status
                                                    === 'draft' ? (
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        type="button"
                                                        disabled={
                                                            updateMutation
                                                                .isPending
                                                        }
                                                        onClick={() =>
                                                            beginEdit(
                                                                item,
                                                            )
                                                        }
                                                    >
                                                        تعديل
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </>
                                    )}
                                </article>
                            ),
                        )}
                    </div>
                )}
            </div>

            {authoringItem ? (
                <AssessmentItemRevisionsPanel
                    version={version}
                    item={
                        authoringItem
                    }
                    onClose={() =>
                        setAuthoringItem(
                            null,
                        )
                    }
                />
            ) : null}
        </Surface>
    );
}

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
    adminTopicsKey,
    createTopic,
    fetchTopics,
    updateTopic,
} from './api';

import type {
    CurriculumVersion,
    Topic,
} from './types';

interface TopicsPanelProps {
    version: CurriculumVersion;
}

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function TopicFailure({
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

export function TopicsPanel({
    version,
}: TopicsPanelProps) {
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft';

    const [
        newName,
        setNewName,
    ] = useState('');

    const [
        newDisplayOrder,
        setNewDisplayOrder,
    ] = useState('0');

    const [
        editingTopic,
        setEditingTopic,
    ] = useState<Topic | null>(
        null,
    );

    const [
        editName,
        setEditName,
    ] = useState('');

    const [
        editDisplayOrder,
        setEditDisplayOrder,
    ] = useState('0');

    const topicsQuery = useQuery({
        queryKey:
            adminTopicsKey(version.id),
        queryFn: () =>
            fetchTopics(version.id),
    });

    const invalidateTopics =
        async () => {
            await queryClient
                .invalidateQueries({
                    queryKey:
                        adminTopicsKey(
                            version.id,
                        ),
                });
        };

    const createMutation =
        useMutation({
            mutationFn: () =>
                createTopic(
                    version.id,
                    {
                        name:
                            newName.trim(),
                        display_order:
                            Number(
                                newDisplayOrder,
                            ),
                    },
                ),
            onSuccess: async () => {
                setNewName('');
                setNewDisplayOrder(
                    '0',
                );

                await invalidateTopics();
            },
        });

    const updateMutation =
        useMutation({
            mutationFn: ({
                topicId,
                name,
                displayOrder,
            }: {
                topicId: string;
                name: string;
                displayOrder: number;
            }) =>
                updateTopic(
                    topicId,
                    {
                        name,
                        display_order:
                            displayOrder,
                    },
                ),
            onSuccess: async () => {
                setEditingTopic(null);
                setEditName('');
                setEditDisplayOrder(
                    '0',
                );

                await invalidateTopics();
            },
        });

    function submitCreate(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || newName.trim() === ''
        ) {
            return;
        }

        const displayOrder =
            Number(newDisplayOrder);

        if (
            !Number.isInteger(
                displayOrder,
            )
            || displayOrder < 0
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(
        topic: Topic,
    ) {
        if (!editable) {
            return;
        }

        setEditingTopic(topic);
        setEditName(topic.name);
        setEditDisplayOrder(
            String(
                topic.display_order,
            ),
        );
    }

    function submitEdit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || !editingTopic
            || updateMutation.isPending
            || editName.trim() === ''
        ) {
            return;
        }

        const displayOrder =
            Number(editDisplayOrder);

        if (
            !Number.isInteger(
                displayOrder,
            )
            || displayOrder < 0
        ) {
            return;
        }

        updateMutation.mutate({
            topicId:
                editingTopic.id,
            name:
                editName.trim(),
            displayOrder,
        });
    }

    return (
        <Surface>
            <div className="foundation-stack admin-content-panel">
                <div>
                    <h2 className="foundation-card__title">
                        Topics
                    </h2>

                    <p className="foundation-page__description">
                        تنظيم موضوعات هذا
                        الإصدار وترتيب ظهورها.
                    </p>
                </div>

                {!editable ? (
                    <Feedback>
                        هذه النسخة للقراءة
                        فقط؛ لا يمكن إضافة
                        أو تعديل Topics.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitCreate
                        }
                    >
                        <label>
                            اسم الموضوع

                            <input
                                aria-label="اسم الموضوع الجديد"
                                value={
                                    newName
                                }
                                maxLength={
                                    255
                                }
                                required
                                onChange={(
                                    event,
                                ) =>
                                    setNewName(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            ترتيب الظهور

                            <input
                                aria-label="ترتيب الموضوع الجديد"
                                type="number"
                                min="0"
                                step="1"
                                value={
                                    newDisplayOrder
                                }
                                required
                                onChange={(
                                    event,
                                ) =>
                                    setNewDisplayOrder(
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
                            إضافة Topic
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <TopicFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إضافة الموضوع.
                    </TopicFailure>
                ) : null}

                {updateMutation.isError ? (
                    <TopicFailure
                        error={
                            updateMutation
                                .error
                        }
                    >
                        تعذر تعديل الموضوع.
                    </TopicFailure>
                ) : null}

                {topicsQuery.isPending ? (
                    <p>
                        جار تحميل Topics…
                    </p>
                ) : topicsQuery.isError ? (
                    <TopicFailure
                        error={
                            topicsQuery.error
                        }
                    >
                        تعذر تحميل Topics.
                    </TopicFailure>
                ) : topicsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد Topics في
                        هذا الإصدار.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {topicsQuery.data.map(
                            (topic) => (
                                <article
                                    key={
                                        topic.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    {editingTopic
                                        ?.id
                                    === topic.id ? (
                                        <form
                                            className="admin-content-form"
                                            onSubmit={
                                                submitEdit
                                            }
                                        >
                                            <label>
                                                اسم الموضوع

                                                <input
                                                    aria-label="تعديل اسم الموضوع"
                                                    value={
                                                        editName
                                                    }
                                                    maxLength={
                                                        255
                                                    }
                                                    required
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditName(
                                                            event
                                                                .target
                                                                .value,
                                                        )
                                                    }
                                                />
                                            </label>

                                            <label>
                                                ترتيب الظهور

                                                <input
                                                    aria-label="تعديل ترتيب الموضوع"
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    value={
                                                        editDisplayOrder
                                                    }
                                                    required
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditDisplayOrder(
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
                                                        setEditingTopic(
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
                                                        topic.name
                                                    }
                                                </strong>

                                                <p className="admin-content-list__meta">
                                                    ترتيب الظهور:{' '}
                                                    {
                                                        topic.display_order
                                                    }
                                                </p>
                                            </div>

                                            {editable ? (
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        beginEdit(
                                                            topic,
                                                        )
                                                    }
                                                >
                                                    تعديل
                                                </Button>
                                            ) : null}
                                        </>
                                    )}
                                </article>
                            ),
                        )}
                    </div>
                )}
            </div>
        </Surface>
    );
}

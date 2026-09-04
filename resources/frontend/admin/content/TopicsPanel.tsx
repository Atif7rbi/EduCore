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

function requestId(error: unknown) {
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
                <strong>{children}</strong>
                {id ? <p className="learner-read-request-id">رقم الطلب: {id}</p> : null}
            </div>
        </Feedback>
    );
}

export function TopicsPanel({ version }: TopicsPanelProps) {
    const queryClient = useQueryClient();
    const editable = version.status === 'draft';
    const [showCreate, setShowCreate] = useState(false);
    const [newName, setNewName] = useState('');
    const [editingTopic, setEditingTopic] = useState<Topic | null>(null);
    const [editName, setEditName] = useState('');

    const topicsQuery = useQuery({
        queryKey: adminTopicsKey(version.id),
        queryFn: () => fetchTopics(version.id),
    });

    const nextDisplayOrder = useMemo(() => {
        const orders = topicsQuery.data?.map((topic) => topic.display_order) ?? [];
        return orders.length === 0 ? 1 : Math.max(...orders) + 1;
    }, [topicsQuery.data]);

    async function invalidate() {
        await queryClient.invalidateQueries({
            queryKey: adminTopicsKey(version.id),
        });
    }

    const createMutation = useMutation({
        mutationFn: () => createTopic(version.id, {
            name: newName.trim(),
            display_order: nextDisplayOrder,
        }),
        onSuccess: async () => {
            setNewName('');
            setShowCreate(false);
            await invalidate();
        },
    });

    const updateMutation = useMutation({
        mutationFn: ({ topicId, name, displayOrder }: {
            topicId: string;
            name: string;
            displayOrder: number;
        }) => updateTopic(topicId, {
            name,
            display_order: displayOrder,
        }),
        onSuccess: async () => {
            setEditingTopic(null);
            await invalidate();
        },
    });

    function submitCreate(event: FormEvent) {
        event.preventDefault();
        if (
            !editable
            || createMutation.isPending
            || newName.trim() === ''
        ) return;
        createMutation.mutate();
    }

    function beginEdit(topic: Topic) {
        if (!editable) return;
        setEditingTopic(topic);
        setEditName(topic.name);
    }

    function submitEdit(event: FormEvent) {
        event.preventDefault();
        if (
            !editable
            || !editingTopic
            || updateMutation.isPending
            || editName.trim() === ''
        ) return;

        updateMutation.mutate({
            topicId: editingTopic.id,
            name: editName.trim(),
            displayOrder: editingTopic.display_order,
        });
    }

    return (
        <Surface>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h2 className="foundation-card__title">الوحدات</h2>
                        <p className="foundation-page__description">
                            اسم الوحدة أو العنوان العام الذي تندرج تحته مجموعة من الدروس.
                        </p>
                    </div>
                    {editable ? (
                        <Button type="button" onClick={() => setShowCreate((value) => !value)}>
                            {showCreate ? 'إغلاق' : 'إضافة وحدة'}
                        </Button>
                    ) : null}
                </div>

                {!editable ? (
                    <Feedback>
                        هذا المنهج للقراءة فقط؛ لا يمكن إضافة الوحدات أو تعديلها.
                    </Feedback>
                ) : null}

                {showCreate && editable ? (
                    <form className="admin-content-form" onSubmit={submitCreate}>
                        <label>
                            اسم الوحدة
                            <input
                                aria-label="اسم الوحدة الجديدة"
                                value={newName}
                                maxLength={255}
                                required
                                placeholder="مثال: النسب والتناسب"
                                onChange={(event) => setNewName(event.target.value)}
                            />
                        </label>
                        <div className="admin-content-actions">
                            <Button type="submit" disabled={createMutation.isPending}>
                                حفظ الوحدة
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => setShowCreate(false)}>
                                إلغاء
                            </Button>
                        </div>
                    </form>
                ) : null}

                {createMutation.isError ? <TopicFailure error={createMutation.error}>تعذر إضافة الوحدة.</TopicFailure> : null}
                {updateMutation.isError ? <TopicFailure error={updateMutation.error}>تعذر تعديل الوحدة.</TopicFailure> : null}

                {topicsQuery.isPending ? (
                    <p>جار تحميل الوحدات…</p>
                ) : topicsQuery.isError ? (
                    <TopicFailure error={topicsQuery.error}>تعذر تحميل الوحدات.</TopicFailure>
                ) : topicsQuery.data.length === 0 ? (
                    <Feedback>لا توجد وحدات في هذا المنهج حتى الآن.</Feedback>
                ) : (
                    <div className="admin-content-list">
                        {[...topicsQuery.data]
                            .sort((a, b) => a.display_order - b.display_order)
                            .map((topic, index) => (
                                <article key={topic.id} className="admin-content-list__item">
                                    {editingTopic?.id === topic.id ? (
                                        <form className="admin-content-form" onSubmit={submitEdit}>
                                            <label>
                                                اسم الوحدة
                                                <input
                                                    aria-label="تعديل اسم الوحدة"
                                                    value={editName}
                                                    maxLength={255}
                                                    required
                                                    onChange={(event) => setEditName(event.target.value)}
                                                />
                                            </label>
                                            <div className="admin-content-actions">
                                                <Button size="sm" type="submit" disabled={updateMutation.isPending}>حفظ</Button>
                                                <Button size="sm" type="button" variant="secondary" onClick={() => setEditingTopic(null)}>إلغاء</Button>
                                            </div>
                                        </form>
                                    ) : (
                                        <>
                                            <div>
                                                <strong>{topic.name}</strong>
                                                <p className="admin-content-list__meta">
                                                    الوحدة {index + 1}
                                                </p>
                                            </div>
                                            {editable ? (
                                                <Button size="sm" variant="secondary" onClick={() => beginEdit(topic)}>
                                                    تعديل
                                                </Button>
                                            ) : null}
                                        </>
                                    )}
                                </article>
                            ))}
                    </div>
                )}
            </div>
        </Surface>
    );
}

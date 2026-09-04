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
    const [newDisplayOrder, setNewDisplayOrder] = useState('0');
    const [editingTopic, setEditingTopic] = useState<Topic | null>(null);
    const [editName, setEditName] = useState('');
    const [editDisplayOrder, setEditDisplayOrder] = useState('0');

    const topicsQuery = useQuery({
        queryKey: adminTopicsKey(version.id),
        queryFn: () => fetchTopics(version.id),
    });

    async function invalidate() {
        await queryClient.invalidateQueries({
            queryKey: adminTopicsKey(version.id),
        });
    }

    const createMutation = useMutation({
        mutationFn: () => createTopic(version.id, {
            name: newName.trim(),
            display_order: Number(newDisplayOrder),
        }),
        onSuccess: async () => {
            setNewName('');
            setNewDisplayOrder('0');
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

    function validOrder(value: string) {
        const number = Number(value);
        return Number.isInteger(number) && number >= 0;
    }

    function submitCreate(event: FormEvent) {
        event.preventDefault();
        if (
            !editable
            || createMutation.isPending
            || newName.trim() === ''
            || !validOrder(newDisplayOrder)
        ) return;
        createMutation.mutate();
    }

    function beginEdit(topic: Topic) {
        if (!editable) return;
        setEditingTopic(topic);
        setEditName(topic.name);
        setEditDisplayOrder(String(topic.display_order));
    }

    function submitEdit(event: FormEvent) {
        event.preventDefault();
        if (
            !editable
            || !editingTopic
            || updateMutation.isPending
            || editName.trim() === ''
            || !validOrder(editDisplayOrder)
        ) return;

        updateMutation.mutate({
            topicId: editingTopic.id,
            name: editName.trim(),
            displayOrder: Number(editDisplayOrder),
        });
    }

    return (
        <Surface>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h2 className="foundation-card__title">الموضوعات</h2>
                        <p className="foundation-page__description">
                            نظّم موضوعات هذا الإصدار وحدد ترتيب ظهورها.
                        </p>
                    </div>
                    {editable ? (
                        <Button type="button" onClick={() => setShowCreate((value) => !value)}>
                            {showCreate ? 'إغلاق' : 'إضافة موضوع'}
                        </Button>
                    ) : null}
                </div>

                {!editable ? (
                    <Feedback>
                        هذا الإصدار للقراءة فقط؛ لا يمكن إضافة الموضوعات أو تعديلها.
                    </Feedback>
                ) : null}

                {showCreate && editable ? (
                    <form className="admin-content-form" onSubmit={submitCreate}>
                        <label>
                            اسم الموضوع
                            <input
                                aria-label="اسم الموضوع الجديد"
                                value={newName}
                                maxLength={255}
                                required
                                onChange={(event) => setNewName(event.target.value)}
                            />
                        </label>
                        <label>
                            ترتيب الظهور
                            <input
                                aria-label="ترتيب الموضوع الجديد"
                                type="number"
                                min="0"
                                step="1"
                                value={newDisplayOrder}
                                required
                                onChange={(event) => setNewDisplayOrder(event.target.value)}
                            />
                        </label>
                        <div className="admin-content-actions">
                            <Button type="submit" disabled={createMutation.isPending}>
                                حفظ الموضوع
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => setShowCreate(false)}>
                                إلغاء
                            </Button>
                        </div>
                    </form>
                ) : null}

                {createMutation.isError ? <TopicFailure error={createMutation.error}>تعذر إضافة الموضوع.</TopicFailure> : null}
                {updateMutation.isError ? <TopicFailure error={updateMutation.error}>تعذر تعديل الموضوع.</TopicFailure> : null}

                {topicsQuery.isPending ? (
                    <p>جار تحميل الموضوعات…</p>
                ) : topicsQuery.isError ? (
                    <TopicFailure error={topicsQuery.error}>تعذر تحميل الموضوعات.</TopicFailure>
                ) : topicsQuery.data.length === 0 ? (
                    <Feedback>لا توجد موضوعات في هذا الإصدار حتى الآن.</Feedback>
                ) : (
                    <div className="admin-content-list">
                        {topicsQuery.data.map((topic) => (
                            <article key={topic.id} className="admin-content-list__item">
                                {editingTopic?.id === topic.id ? (
                                    <form className="admin-content-form" onSubmit={submitEdit}>
                                        <label>
                                            اسم الموضوع
                                            <input
                                                aria-label="تعديل اسم الموضوع"
                                                value={editName}
                                                maxLength={255}
                                                required
                                                onChange={(event) => setEditName(event.target.value)}
                                            />
                                        </label>
                                        <label>
                                            ترتيب الظهور
                                            <input
                                                aria-label="تعديل ترتيب الموضوع"
                                                type="number"
                                                min="0"
                                                step="1"
                                                value={editDisplayOrder}
                                                required
                                                onChange={(event) => setEditDisplayOrder(event.target.value)}
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
                                                ترتيب الظهور: {topic.display_order}
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

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
    activatePracticeActivity,
    adminLessonsKey,
    adminPracticeActivitiesKey,
    archivePracticeActivity,
    createPracticeActivity,
    fetchLessons,
    fetchPracticeActivities,
    updatePracticeActivity,
} from './api';

import {
    PracticeActivityItemsPanel,
} from './PracticeActivityItemsPanel';

import type {
    CurriculumVersion,
    PracticeActivity,
} from './types';

interface PracticeActivitiesPanelProps {
    version: CurriculumVersion;
}

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function PracticeFailure({
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
    status:
        PracticeActivity['status'],
) {
    return status === 'active'
        ? 'متاح'
        : 'متوقف';
}

export function PracticeActivitiesPanel({
    version,
}: PracticeActivitiesPanelProps) {
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft';

    const [
        name,
        setName,
    ] = useState('');

    const [
        description,
        setDescription,
    ] = useState('');

    const [
        lessonId,
        setLessonId,
    ] = useState('');

    const [
        editingActivity,
        setEditingActivity,
    ] =
        useState<PracticeActivity | null>(
            null,
        );

    const [
        managingItemsActivityId,
        setManagingItemsActivityId,
    ] =
        useState<string | null>(
            null,
        );

    const activitiesQuery =
        useQuery({
            queryKey:
                adminPracticeActivitiesKey(
                    version.id,
                ),
            queryFn: () =>
                fetchPracticeActivities(
                    version.id,
                ),
        });

    const lessonsQuery =
        useQuery({
            queryKey:
                adminLessonsKey(
                    version.id,
                ),
            queryFn: () =>
                fetchLessons(
                    version.id,
                ),
        });

    const managingItemsActivity =
        activitiesQuery.data
            ?.find(
                (activity) =>
                    activity.id
                    === managingItemsActivityId,
            )
        ?? null;

    async function invalidate() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminPracticeActivitiesKey(
                        version.id,
                    ),
            });
    }

    const createMutation =
        useMutation({
            mutationFn: () =>
                createPracticeActivity(
                    version.id,
                    {
                        lesson_id:
                            lessonId
                            || null,
                        name:
                            name.trim(),
                        description:
                            description
                                .trim()
                            || null,
                    },
                ),
            onSuccess: async () => {
                setName('');
                setDescription('');
                setLessonId('');

                await invalidate();
            },
        });

    const updateMutation =
        useMutation({
            mutationFn: ({
                activityId,
                activityName,
                activityDescription,
                activityLessonId,
            }: {
                activityId: string;
                activityName: string;
                activityDescription:
                    string;
                activityLessonId:
                    string;
            }) =>
                updatePracticeActivity(
                    activityId,
                    {
                        lesson_id:
                            activityLessonId
                            || null,
                        name:
                            activityName
                                .trim(),
                        description:
                            activityDescription
                                .trim()
                            || null,
                    },
                ),
            onSuccess: async () => {
                setEditingActivity(
                    null,
                );

                await invalidate();
            },
        });

    const lifecycleMutation =
        useMutation({
            mutationFn: ({
                activityId,
                action,
            }: {
                activityId: string;
                action:
                    | 'activate'
                    | 'archive';
            }) =>
                action === 'activate'
                    ? activatePracticeActivity(
                        activityId,
                    )
                    : archivePracticeActivity(
                        activityId,
                    ),
            onSuccess: async () => {
                setEditingActivity(
                    null,
                );

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
            || name.trim() === ''
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(
        activity:
            PracticeActivity,
    ) {
        if (
            !editable
            || activity.status
                !== 'archived'
        ) {
            return;
        }

        setEditingActivity({
            ...activity,
        });
    }

    function submitEdit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editingActivity
            || !editable
            || editingActivity.status
                !== 'archived'
            || updateMutation.isPending
            || editingActivity
                .name
                .trim() === ''
        ) {
            return;
        }

        updateMutation.mutate({
            activityId:
                editingActivity.id,
            activityName:
                editingActivity.name,
            activityDescription:
                editingActivity
                    .description
                ?? '',
            activityLessonId:
                editingActivity
                    .lesson_id
                ?? '',
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div>
                    <h2 className="foundation-card__title">
                        التدريبات
                    </h2>

                    <p className="foundation-page__description">
                        أنشئ مجموعات تدريب واربطها بالدروس ثم أضف الأسئلة وحدد متى تكون متاحة للطلاب.
                    </p>
                </div>

                {editable ? (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitCreate
                        }
                    >
                        <label>
                            الاسم

                            <input
                                aria-label="اسم مجموعة التدريب"
                                value={name}
                                required
                                maxLength={255}
                                onChange={(
                                    event,
                                ) =>
                                    setName(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الدرس المرتبط

                            <select
                                aria-label="درس مجموعة التدريب"
                                value={
                                    lessonId
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setLessonId(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            >
                                <option value="">
                                    بدون درس
                                </option>

                                {lessonsQuery.data
                                    ?.map(
                                        (
                                            lesson,
                                        ) => (
                                            <option
                                                key={
                                                    lesson.id
                                                }
                                                value={
                                                    lesson.id
                                                }
                                            >
                                                {
                                                    lesson.title
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        <label>
                            الوصف

                            <textarea
                                aria-label="وصف مجموعة التدريب"
                                rows={4}
                                value={
                                    description
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setDescription(
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
                            إنشاء مجموعة تدريب
                        </Button>
                    </form>
                ) : (
                    <Feedback>
                        هذا المنهج للقراءة فقط؛ لا يمكن إنشاء التدريبات أو تعديلها.
                    </Feedback>
                )}

                {createMutation.isError ? (
                    <PracticeFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إنشاء مجموعة التدريب.
                    </PracticeFailure>
                ) : null}

                {updateMutation.isError ? (
                    <PracticeFailure
                        error={
                            updateMutation
                                .error
                        }
                    >
                        تعذر تعديل مجموعة التدريب.
                    </PracticeFailure>
                ) : null}

                {lifecycleMutation.isError ? (
                    <PracticeFailure
                        error={
                            lifecycleMutation
                                .error
                        }
                    >
                        تعذر تغيير حالة مجموعة التدريب.
                    </PracticeFailure>
                ) : null}

                {lessonsQuery.isError ? (
                    <PracticeFailure
                        error={
                            lessonsQuery.error
                        }
                    >
                        تعذر تحميل الدروس.
                    </PracticeFailure>
                ) : null}

                {activitiesQuery.isPending ? (
                    <p>
                        جار تحميل مجموعات
                        التدريب…
                    </p>
                ) : activitiesQuery.isError ? (
                    <PracticeFailure
                        error={
                            activitiesQuery
                                .error
                        }
                    >
                        تعذر تحميل مجموعات التدريب.
                    </PracticeFailure>
                ) : activitiesQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد مجموعات تدريب لهذا المنهج حتى الآن.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {activitiesQuery.data.map(
                            (
                                activity,
                            ) => (
                                <article
                                    key={
                                        activity.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            {
                                                activity.name
                                            }
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            الحالة:{' '}
                                            {
                                                statusLabel(
                                                    activity.status,
                                                )
                                            }
                                            {' · '}
                                            الأسئلة:{' '}
                                            {
                                                activity.items_count
                                                ?? 0
                                            }
                                        </p>

                                        {activity.description ? (
                                            <p className="admin-content-list__meta">
                                                {
                                                    activity.description
                                                }
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="admin-content-actions">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            type="button"
                                            onClick={() =>
                                                setManagingItemsActivityId(
                                                    activity.id,
                                                )
                                            }
                                        >
                                            إدارة الأسئلة
                                        </Button>

                                        {editable
                                        && activity.status
                                            === 'archived' ? (
                                            <>
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    type="button"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                        || lifecycleMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        beginEdit(
                                                            activity,
                                                        )
                                                    }
                                                >
                                                    تعديل
                                                </Button>

                                                <Button
                                                    size="sm"
                                                    type="button"
                                                    disabled={
                                                        lifecycleMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        lifecycleMutation
                                                            .mutate({
                                                                activityId:
                                                                    activity.id,
                                                                action:
                                                                    'activate',
                                                            })
                                                    }
                                                >
                                                    إتاحة للطلاب
                                                </Button>
                                            </>
                                        ) : null}

                                        {editable
                                        && activity.status
                                            === 'active' ? (
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                type="button"
                                                disabled={
                                                    lifecycleMutation
                                                        .isPending
                                                }
                                                onClick={() =>
                                                    lifecycleMutation
                                                        .mutate({
                                                            activityId:
                                                                activity.id,
                                                            action:
                                                                'archive',
                                                        })
                                                }
                                            >
                                                إيقاف الإتاحة
                                            </Button>
                                        ) : null}
                                    </div>
                                </article>
                            ),
                        )}
                    </div>
                )}

                {editingActivity ? (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitEdit
                        }
                    >
                        <h3 className="foundation-card__title">
                            تعديل مجموعة التدريب
                        </h3>

                        <label>
                            الاسم

                            <input
                                aria-label="تعديل اسم مجموعة التدريب"
                                required
                                maxLength={255}
                                value={
                                    editingActivity
                                        .name
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingActivity(
                                        {
                                            ...editingActivity,
                                            name:
                                                event
                                                    .target
                                                    .value,
                                        },
                                    )
                                }
                            />
                        </label>

                        <label>
                            الدرس المرتبط

                            <select
                                aria-label="تعديل درس مجموعة التدريب"
                                value={
                                    editingActivity
                                        .lesson_id
                                    ?? ''
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingActivity(
                                        {
                                            ...editingActivity,
                                            lesson_id:
                                                event
                                                    .target
                                                    .value
                                                || null,
                                        },
                                    )
                                }
                            >
                                <option value="">
                                    بدون درس
                                </option>

                                {lessonsQuery.data
                                    ?.map(
                                        (
                                            lesson,
                                        ) => (
                                            <option
                                                key={
                                                    lesson.id
                                                }
                                                value={
                                                    lesson.id
                                                }
                                            >
                                                {
                                                    lesson.title
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        <label>
                            الوصف

                            <textarea
                                aria-label="تعديل وصف مجموعة التدريب"
                                rows={4}
                                value={
                                    editingActivity
                                        .description
                                    ?? ''
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingActivity(
                                        {
                                            ...editingActivity,
                                            description:
                                                event
                                                    .target
                                                    .value,
                                        },
                                    )
                                }
                            />
                        </label>

                        <div className="admin-content-actions">
                            <Button
                                type="submit"
                                disabled={
                                    updateMutation
                                        .isPending
                                }
                            >
                                حفظ التعديل
                            </Button>

                            <Button
                                type="button"
                                variant="secondary"
                                disabled={
                                    updateMutation
                                        .isPending
                                }
                                onClick={() =>
                                    setEditingActivity(
                                        null,
                                    )
                                }
                            >
                                إلغاء
                            </Button>
                        </div>
                    </form>
                ) : null}
            </div>

            {managingItemsActivity ? (
                <PracticeActivityItemsPanel
                    version={version}
                    activity={
                        managingItemsActivity
                    }
                    onClose={() =>
                        setManagingItemsActivityId(
                            null,
                        )
                    }
                />
            ) : null}
        </Surface>
    );
}

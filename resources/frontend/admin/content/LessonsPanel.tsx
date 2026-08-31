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
    adminLessonsKey,
    createLesson,
    fetchLessons,
    updateLesson,
} from './api';

import {
    LessonRevisionsPanel,
} from './LessonRevisionsPanel';

import type {
    CurriculumVersion,
    Lesson,
} from './types';

interface LessonsPanelProps {
    version: CurriculumVersion;
}

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function LessonFailure({
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

function lessonStatusLabel(
    status: Lesson['status'],
) {
    switch (status) {
        case 'draft':
            return 'مسودة';
        case 'published':
            return 'منشور';
        case 'retired':
            return 'متقاعد';
    }
}

export function LessonsPanel({
    version,
}: LessonsPanelProps) {
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft';

    const [
        newTitle,
        setNewTitle,
    ] = useState('');

    const [
        newDescription,
        setNewDescription,
    ] = useState('');

    const [
        newDisplayOrder,
        setNewDisplayOrder,
    ] = useState('0');

    const [
        editingLesson,
        setEditingLesson,
    ] = useState<Lesson | null>(
        null,
    );

    const [
        authoringLesson,
        setAuthoringLesson,
    ] = useState<Lesson | null>(
        null,
    );

    const [
        editTitle,
        setEditTitle,
    ] = useState('');

    const [
        editDescription,
        setEditDescription,
    ] = useState('');

    const [
        editDisplayOrder,
        setEditDisplayOrder,
    ] = useState('0');

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

    async function invalidate() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminLessonsKey(
                        version.id,
                    ),
            });
    }

    const createMutation =
        useMutation({
            mutationFn: () =>
                createLesson(
                    version.id,
                    {
                        title:
                            newTitle.trim(),
                        description:
                            newDescription
                                .trim()
                            || null,
                        display_order:
                            Number(
                                newDisplayOrder,
                            ),
                    },
                ),
            onSuccess: async () => {
                setNewTitle('');
                setNewDescription('');
                setNewDisplayOrder(
                    '0',
                );

                await invalidate();
            },
        });

    const updateMutation =
        useMutation({
            mutationFn: ({
                lessonId,
                title,
                description,
                displayOrder,
            }: {
                lessonId: string;
                title: string;
                description:
                    string | null;
                displayOrder: number;
            }) =>
                updateLesson(
                    lessonId,
                    {
                        title,
                        description,
                        display_order:
                            displayOrder,
                    },
                ),
            onSuccess: async () => {
                setEditingLesson(
                    null,
                );
                setEditTitle('');
                setEditDescription('');
                setEditDisplayOrder(
                    '0',
                );

                await invalidate();
            },
        });

    function validOrder(
        value: string,
    ) {
        const number =
            Number(value);

        return Number.isInteger(
            number,
        ) && number >= 0;
    }

    function submitCreate(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || newTitle.trim() === ''
            || !validOrder(
                newDisplayOrder,
            )
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(
        lesson: Lesson,
    ) {
        if (
            !editable
            || lesson.status
                !== 'draft'
        ) {
            return;
        }

        setEditingLesson(
            lesson,
        );
        setEditTitle(
            lesson.title,
        );
        setEditDescription(
            lesson.description
            ?? '',
        );
        setEditDisplayOrder(
            String(
                lesson.display_order,
            ),
        );
    }

    function submitEdit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || !editingLesson
            || editingLesson.status
                !== 'draft'
            || updateMutation.isPending
            || editTitle.trim() === ''
            || !validOrder(
                editDisplayOrder,
            )
        ) {
            return;
        }

        updateMutation.mutate({
            lessonId:
                editingLesson.id,
            title:
                editTitle.trim(),
            description:
                editDescription
                    .trim()
                || null,
            displayOrder:
                Number(
                    editDisplayOrder,
                ),
        });
    }

    return (
        <Surface>
            <div className="foundation-stack admin-content-panel">
                <div>
                    <h2 className="foundation-card__title">
                        Lessons
                    </h2>

                    <p className="foundation-page__description">
                        إنشاء الدروس وترتيبها
                        داخل إصدار المنهج.
                    </p>
                </div>

                {!editable ? (
                    <Feedback>
                        هذه النسخة للقراءة
                        فقط؛ لا يمكن إنشاء
                        أو تعديل الدروس.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitCreate
                        }
                    >
                        <label>
                            عنوان الدرس

                            <input
                                aria-label="عنوان الدرس الجديد"
                                value={
                                    newTitle
                                }
                                maxLength={
                                    255
                                }
                                required
                                onChange={(
                                    event,
                                ) =>
                                    setNewTitle(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الوصف

                            <textarea
                                aria-label="وصف الدرس الجديد"
                                rows={3}
                                value={
                                    newDescription
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setNewDescription(
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
                                aria-label="ترتيب الدرس الجديد"
                                type="number"
                                min="0"
                                step="1"
                                required
                                value={
                                    newDisplayOrder
                                }
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
                            إضافة Lesson
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <LessonFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إنشاء الدرس.
                    </LessonFailure>
                ) : null}

                {updateMutation.isError ? (
                    <LessonFailure
                        error={
                            updateMutation
                                .error
                        }
                    >
                        تعذر تعديل الدرس.
                    </LessonFailure>
                ) : null}

                {lessonsQuery.isPending ? (
                    <p>
                        جار تحميل الدروس…
                    </p>
                ) : lessonsQuery.isError ? (
                    <LessonFailure
                        error={
                            lessonsQuery.error
                        }
                    >
                        تعذر تحميل الدروس.
                    </LessonFailure>
                ) : lessonsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد دروس في هذا
                        الإصدار.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {lessonsQuery.data.map(
                            (lesson) => (
                                <article
                                    key={
                                        lesson.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    {editingLesson
                                        ?.id
                                    === lesson.id ? (
                                        <form
                                            className="admin-content-form admin-content-list__editor"
                                            onSubmit={
                                                submitEdit
                                            }
                                        >
                                            <label>
                                                عنوان الدرس

                                                <input
                                                    aria-label="تعديل عنوان الدرس"
                                                    value={
                                                        editTitle
                                                    }
                                                    maxLength={
                                                        255
                                                    }
                                                    required
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditTitle(
                                                            event
                                                                .target
                                                                .value,
                                                        )
                                                    }
                                                />
                                            </label>

                                            <label>
                                                الوصف

                                                <textarea
                                                    aria-label="تعديل وصف الدرس"
                                                    rows={
                                                        3
                                                    }
                                                    value={
                                                        editDescription
                                                    }
                                                    onChange={(
                                                        event,
                                                    ) =>
                                                        setEditDescription(
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
                                                    aria-label="تعديل ترتيب الدرس"
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    required
                                                    value={
                                                        editDisplayOrder
                                                    }
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
                                                        setEditingLesson(
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
                                                        lesson.title
                                                    }
                                                </strong>

                                                <p className="admin-content-list__meta">
                                                    الحالة:{' '}
                                                    {
                                                        lessonStatusLabel(
                                                            lesson.status,
                                                        )
                                                    }
                                                    {' · '}
                                                    الترتيب:{' '}
                                                    {
                                                        lesson.display_order
                                                    }
                                                </p>

                                                {lesson.description ? (
                                                    <p className="admin-content-list__meta">
                                                        {
                                                            lesson.description
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
                                                        setAuthoringLesson(
                                                            lesson,
                                                        )
                                                    }
                                                >
                                                    المراجعات
                                                </Button>

                                                {editable
                                                && lesson.status
                                                    === 'draft' ? (
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        disabled={
                                                            updateMutation
                                                                .isPending
                                                        }
                                                        onClick={() =>
                                                            beginEdit(
                                                                lesson,
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

            {authoringLesson ? (
                <LessonRevisionsPanel
                    version={version}
                    lesson={
                        authoringLesson
                    }
                    onClose={() =>
                        setAuthoringLesson(
                            null,
                        )
                    }
                />
            ) : null}
        </Surface>
    );
}

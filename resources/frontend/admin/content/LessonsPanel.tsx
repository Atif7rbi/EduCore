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

type LessonFilter = 'all' | Lesson['status'];

function requestId(error: unknown) {
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

function lessonStatusLabel(status: Lesson['status']) {
    switch (status) {
        case 'draft':
            return 'مسودة';
        case 'published':
            return 'منشور';
        case 'retired':
            return 'موقوف';
    }
}

function formatDate(value: string | null) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('ar-SA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(date);
}

export function LessonsPanel({ version }: LessonsPanelProps) {
    const queryClient = useQueryClient();
    const editable = version.status === 'draft';

    const [showCreate, setShowCreate] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] =
        useState<LessonFilter>('all');
    const [newTitle, setNewTitle] = useState('');
    const [newDescription, setNewDescription] = useState('');
    const [editingLesson, setEditingLesson] = useState<Lesson | null>(null);
    const [authoringLessonId, setAuthoringLessonId] = useState<string | null>(null);
    const [editTitle, setEditTitle] = useState('');
    const [editDescription, setEditDescription] = useState('');

    const lessonsQuery = useQuery({
        queryKey: adminLessonsKey(version.id),
        queryFn: () => fetchLessons(version.id),
    });

    const sortedLessons = useMemo(
        () => [...(lessonsQuery.data ?? [])]
            .sort((a, b) => a.display_order - b.display_order),
        [lessonsQuery.data],
    );

    const nextDisplayOrder = useMemo(() => {
        const orders = lessonsQuery.data?.map((lesson) => lesson.display_order) ?? [];
        return orders.length === 0 ? 1 : Math.max(...orders) + 1;
    }, [lessonsQuery.data]);

    const authoringLesson = useMemo(
        () =>
            lessonsQuery.data?.find(
                (lesson) => lesson.id === authoringLessonId,
            ) ?? null,
        [authoringLessonId, lessonsQuery.data],
    );

    const filteredLessons = useMemo(() => {
        const normalizedSearch =
            searchTerm.trim().toLocaleLowerCase('ar');

        return sortedLessons.filter((lesson) => {
            const matchesStatus =
                statusFilter === 'all'
                || lesson.status === statusFilter;

            const matchesSearch =
                normalizedSearch === ''
                || lesson.title
                    .toLocaleLowerCase('ar')
                    .includes(normalizedSearch)
                || (lesson.description ?? '')
                    .toLocaleLowerCase('ar')
                    .includes(normalizedSearch);

            return matchesStatus && matchesSearch;
        });
    }, [sortedLessons, searchTerm, statusFilter]);

    async function invalidate() {
        await queryClient.invalidateQueries({
            queryKey: adminLessonsKey(version.id),
        });
    }

    const createMutation = useMutation({
        mutationFn: () =>
            createLesson(version.id, {
                title: newTitle.trim(),
                description: newDescription.trim() || null,
                display_order: nextDisplayOrder,
            }),
        onSuccess: async (lesson) => {
            setNewTitle('');
            setNewDescription('');
            setShowCreate(false);
            setAuthoringLessonId(lesson.id);
            await invalidate();
        },
    });

    const updateMutation = useMutation({
        mutationFn: ({
            lessonId,
            title,
            description,
            displayOrder,
        }: {
            lessonId: string;
            title: string;
            description: string | null;
            displayOrder: number;
        }) =>
            updateLesson(lessonId, {
                title,
                description,
                display_order: displayOrder,
            }),
        onSuccess: async () => {
            setEditingLesson(null);
            setEditTitle('');
            setEditDescription('');
            await invalidate();
        },
    });

    function submitCreate(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || newTitle.trim() === ''
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(lesson: Lesson) {
        if (!editable || lesson.status !== 'draft') {
            return;
        }

        setShowCreate(false);
        setAuthoringLessonId(lesson.id);
        setEditingLesson(lesson);
        setEditTitle(lesson.title);
        setEditDescription(lesson.description ?? '');
    }

    function submitEdit(event: FormEvent) {
        event.preventDefault();

        if (
            !editable
            || !editingLesson
            || editingLesson.status !== 'draft'
            || updateMutation.isPending
            || editTitle.trim() === ''
        ) {
            return;
        }

        updateMutation.mutate({
            lessonId: editingLesson.id,
            title: editTitle.trim(),
            description: editDescription.trim() || null,
            displayOrder: editingLesson.display_order,
        });
    }

    const inspectorOpen =
        showCreate || authoringLesson !== null;

    return (
        <div
            className={
                inspectorOpen
                    ? 'admin-lessons-layout admin-lessons-layout--inspector'
                    : 'admin-lessons-layout'
            }
        >
            <Surface className="admin-lessons-list-pane">
                <div className="admin-lessons-list-pane__header">
                    <div>
                        <h2>الدروس</h2>
                        <p>
                            أضف الدروس وأدر محتواها وحالة نشرها من مساحة واحدة.
                        </p>
                    </div>

                    {editable ? (
                        <Button
                            type="button"
                            onClick={() => {
                                setAuthoringLessonId(null);
                                setEditingLesson(null);
                                setShowCreate(true);
                            }}
                        >
                            <span aria-hidden="true">＋</span>
                            إضافة درس
                        </Button>
                    ) : null}
                </div>

                {!editable ? (
                    <Feedback>
                        هذا المنهج للقراءة فقط؛ لا يمكن إنشاء الدروس أو تعديلها.
                    </Feedback>
                ) : null}

                <div className="admin-lessons-toolbar">
                    <label className="admin-lessons-search">
                        <span className="sr-only">البحث في الدروس</span>
                        <input
                            aria-label="البحث في الدروس"
                            type="search"
                            placeholder="البحث في الدروس…"
                            value={searchTerm}
                            onChange={(event) =>
                                setSearchTerm(event.target.value)
                            }
                        />
                    </label>

                    <label className="admin-lessons-filter">
                        <span className="sr-only">تصفية حالة الدروس</span>
                        <select
                            aria-label="تصفية حالة الدروس"
                            value={statusFilter}
                            onChange={(event) => {
                                const value = event.target.value;

                                if (
                                    value === 'all'
                                    || value === 'draft'
                                    || value === 'published'
                                    || value === 'retired'
                                ) {
                                    setStatusFilter(value);
                                }
                            }}
                        >
                            <option value="all">جميع الحالات</option>
                            <option value="draft">مسودة</option>
                            <option value="published">منشور</option>
                            <option value="retired">موقوف</option>
                        </select>
                    </label>
                </div>

                {createMutation.isError ? (
                    <LessonFailure error={createMutation.error}>
                        تعذر إنشاء الدرس.
                    </LessonFailure>
                ) : null}

                {updateMutation.isError ? (
                    <LessonFailure error={updateMutation.error}>
                        تعذر تعديل الدرس.
                    </LessonFailure>
                ) : null}

                {lessonsQuery.isPending ? (
                    <div className="admin-lessons-empty">
                        جار تحميل الدروس…
                    </div>
                ) : lessonsQuery.isError ? (
                    <LessonFailure error={lessonsQuery.error}>
                        تعذر تحميل الدروس.
                    </LessonFailure>
                ) : lessonsQuery.data.length === 0 ? (
                    <div className="admin-lessons-empty">
                        <strong>لا توجد دروس حتى الآن.</strong>
                        <span>
                            أضف أول درس للبدء في بناء محتوى المنهج.
                        </span>
                    </div>
                ) : filteredLessons.length === 0 ? (
                    <div className="admin-lessons-empty">
                        لا توجد نتائج مطابقة للبحث أو التصفية.
                    </div>
                ) : (
                    <div className="admin-lessons-table-wrap">
                        <div className="admin-lessons-order-label">
                            ترتيب الدروس
                        </div>
                        <table className="admin-lessons-table">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">عنوان الدرس</th>
                                    <th scope="col">الحالة</th>
                                    <th scope="col">آخر تحديث</th>
                                    <th scope="col">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                {filteredLessons.map((lesson, index) => (
                                    <tr
                                        key={lesson.id}
                                        className={
                                            authoringLessonId === lesson.id
                                                ? 'admin-lessons-table__row admin-lessons-table__row--selected'
                                                : 'admin-lessons-table__row'
                                        }
                                    >
                                        <td>{index + 1}</td>
                                        <td>
                                            <button
                                                type="button"
                                                className="admin-lesson-title-button"
                                                onClick={() => {
                                                    setShowCreate(false);
                                                    setEditingLesson(null);
                                                    setAuthoringLessonId(lesson.id);
                                                }}
                                            >
                                                <strong>{lesson.title}</strong>
                                                {lesson.description ? (
                                                    <span>{lesson.description}</span>
                                                ) : null}
                                            </button>
                                        </td>
                                        <td>
                                            <span
                                                className={`admin-lesson-status admin-lesson-status--${lesson.status}`}
                                            >
                                                {lessonStatusLabel(lesson.status)}
                                            </span>
                                        </td>
                                        <td>{formatDate(lesson.updated_at)}</td>
                                        <td>
                                            <div className="admin-lesson-row-actions">
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    type="button"
                                                    onClick={() => {
                                                        setShowCreate(false);
                                                        setEditingLesson(null);
                                                        setAuthoringLessonId(lesson.id);
                                                    }}
                                                >
                                                    إدارة الدرس
                                                </Button>

                                                {editable && lesson.status === 'draft' ? (
                                                    <button
                                                        type="button"
                                                        className="admin-lesson-more"
                                                        aria-label={`تعديل بيانات الدرس ${lesson.title}`}
                                                        onClick={() => beginEdit(lesson)}
                                                    >
                                                        ⋮
                                                    </button>
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Surface>

            {showCreate && editable ? (
                <Surface className="admin-lesson-inspector">
                    <div className="admin-lesson-inspector__header">
                        <div>
                            <span>إضافة درس جديد</span>
                            <h3>بيانات الدرس</h3>
                        </div>
                        <button
                            type="button"
                            aria-label="إغلاق إضافة الدرس"
                            onClick={() => setShowCreate(false)}
                        >
                            ×
                        </button>
                    </div>

                    <form
                        className="admin-lesson-inspector__form"
                        onSubmit={submitCreate}
                    >
                        <label>
                            عنوان الدرس
                            <input
                                aria-label="عنوان الدرس الجديد"
                                value={newTitle}
                                maxLength={255}
                                required
                                placeholder="اكتب عنوان الدرس"
                                onChange={(event) => setNewTitle(event.target.value)}
                            />
                        </label>

                        <label>
                            الوصف المختصر
                            <textarea
                                aria-label="وصف الدرس الجديد"
                                rows={4}
                                value={newDescription}
                                placeholder="اكتب وصفًا مختصرًا للدرس"
                                onChange={(event) => setNewDescription(event.target.value)}
                            />
                        </label>

                        <div className="admin-lesson-inspector__actions">
                            <Button
                                type="submit"
                                disabled={createMutation.isPending}
                            >
                                حفظ الدرس
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setShowCreate(false)}
                            >
                                إلغاء
                            </Button>
                        </div>
                    </form>
                </Surface>
            ) : null}

            {authoringLesson ? (
                <aside className="admin-lesson-inspector-stack">
                    <Surface className="admin-lesson-inspector admin-lesson-inspector--summary">
                        <div className="admin-lesson-inspector__header">
                            <div>
                                <span>تفاصيل الدرس</span>
                                <h3>{authoringLesson.title}</h3>
                            </div>
                            <button
                                type="button"
                                aria-label="إغلاق تفاصيل الدرس"
                                onClick={() => {
                                    setAuthoringLessonId(null);
                                    setEditingLesson(null);
                                }}
                            >
                                ×
                            </button>
                        </div>

                        <div className="admin-lesson-inspector__summary-grid">
                            <div>
                                <span>الحالة</span>
                                <strong>{lessonStatusLabel(authoringLesson.status)}</strong>
                            </div>
                        </div>

                        {authoringLesson.description ? (
                            <p className="admin-lesson-inspector__description">
                                {authoringLesson.description}
                            </p>
                        ) : null}

                        {editingLesson?.id === authoringLesson.id ? (
                            <form
                                className="admin-lesson-inspector__form"
                                onSubmit={submitEdit}
                            >
                                <label>
                                    عنوان الدرس
                                    <input
                                        aria-label="تعديل عنوان الدرس"
                                        value={editTitle}
                                        maxLength={255}
                                        required
                                        onChange={(event) => setEditTitle(event.target.value)}
                                    />
                                </label>

                                <label>
                                    الوصف المختصر
                                    <textarea
                                        aria-label="تعديل وصف الدرس"
                                        rows={3}
                                        value={editDescription}
                                        onChange={(event) => setEditDescription(event.target.value)}
                                    />
                                </label>

                                <div className="admin-lesson-inspector__actions">
                                    <Button
                                        size="sm"
                                        type="submit"
                                        disabled={updateMutation.isPending}
                                    >
                                        حفظ التعديلات
                                    </Button>
                                    <Button
                                        size="sm"
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setEditingLesson(null)}
                                    >
                                        إلغاء
                                    </Button>
                                </div>
                            </form>
                        ) : editable && authoringLesson.status === 'draft' ? (
                            <Button
                                variant="secondary"
                                type="button"
                                onClick={() => beginEdit(authoringLesson)}
                            >
                                تعديل بيانات الدرس
                            </Button>
                        ) : null}
                    </Surface>

                    <LessonRevisionsPanel
                        version={version}
                        lesson={authoringLesson}
                        onClose={() => setAuthoringLessonId(null)}
                    />
                </aside>
            ) : null}
        </div>
    );
}

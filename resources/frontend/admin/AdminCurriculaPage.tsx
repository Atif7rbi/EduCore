import {
    FormEvent,
    useEffect,
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';

import {
    apiRequest,
} from '../api/client';
import {
    EduCoreApiError,
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../ui';

interface Subject {
    id: string;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

interface Curriculum {
    id: string;
    subject_id: string;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

interface CurriculumVersion {
    id: string;
    curriculum_id: string;
    version_number: number;
    label: string;
    status: 'draft' | 'published' | 'retired';
}

function subjectsKey() {
    return ['admin', 'subjects'] as const;
}

function curriculaKey(subjectId: string) {
    return [
        'admin',
        'subjects',
        subjectId,
        'curricula',
    ] as const;
}

async function fetchSubjects(): Promise<Subject[]> {
    return apiRequest<Subject[]>({
        method: 'GET',
        url: '/api/admin/subjects',
    });
}

async function fetchCurricula(
    subjectId: string,
): Promise<Curriculum[]> {
    return apiRequest<Curriculum[]>({
        method: 'GET',
        url: `/api/admin/subjects/${subjectId}/curricula`,
    });
}

function requestId(error: unknown) {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function AdminFailure({
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

export function AdminCurriculaPage() {
    const queryClient = useQueryClient();

    const [selectedSubjectId, setSelectedSubjectId] =
        useState<string | null>(null);
    const [newSubjectName, setNewSubjectName] =
        useState('');
    const [newCurriculumName, setNewCurriculumName] =
        useState('');
    const [editingSubject, setEditingSubject] =
        useState<Subject | null>(null);
    const [editingCurriculum, setEditingCurriculum] =
        useState<Curriculum | null>(null);

    const subjectsQuery = useQuery({
        queryKey: subjectsKey(),
        queryFn: fetchSubjects,
    });

    const curriculaQuery = useQuery({
        queryKey: curriculaKey(selectedSubjectId ?? ''),
        queryFn: () => fetchCurricula(selectedSubjectId!),
        enabled: selectedSubjectId !== null,
    });

    useEffect(() => {
        const subjects = subjectsQuery.data;

        if (
            subjects
            && subjects.length > 0
            && selectedSubjectId === null
        ) {
            setSelectedSubjectId(subjects[0].id);
        }
    }, [selectedSubjectId, subjectsQuery.data]);

    const createSubject = useMutation({
        mutationFn: (name: string) =>
            apiRequest<Subject>({
                method: 'POST',
                url: '/api/admin/subjects',
                data: { name },
            }),
        onSuccess: async (subject) => {
            setNewSubjectName('');
            setSelectedSubjectId(subject.id);

            await queryClient.invalidateQueries({
                queryKey: subjectsKey(),
            });
        },
    });

    const updateSubject = useMutation({
        mutationFn: ({
            id,
            name,
        }: {
            id: string;
            name: string;
        }) =>
            apiRequest<Subject>({
                method: 'PUT',
                url: `/api/admin/subjects/${id}`,
                data: { name },
            }),
        onSuccess: async () => {
            setEditingSubject(null);

            await queryClient.invalidateQueries({
                queryKey: subjectsKey(),
            });
        },
    });

    const createCurriculum = useMutation({
        mutationFn: async ({
            subjectId,
            name,
        }: {
            subjectId: string;
            name: string;
        }) => {
            const curriculum =
                await apiRequest<Curriculum>({
                    method: 'POST',
                    url: `/api/admin/subjects/${subjectId}/curricula`,
                    data: { name },
                });

            await apiRequest<CurriculumVersion>({
                method: 'POST',
                url: `/api/admin/curricula/${curriculum.id}/versions`,
                data: {
                    version_number: 1,
                    label: 'مسودة العمل',
                },
            });

            return curriculum;
        },
        onSuccess: async (curriculum) => {
            setNewCurriculumName('');

            await queryClient.invalidateQueries({
                queryKey: curriculaKey(
                    curriculum.subject_id,
                ),
            });
        },
    });

    const updateCurriculum = useMutation({
        mutationFn: ({
            id,
            subjectId,
            name,
        }: {
            id: string;
            subjectId: string;
            name: string;
        }) =>
            apiRequest<Curriculum>({
                method: 'PUT',
                url: `/api/admin/curricula/${id}`,
                data: { name },
            }).then((curriculum) => ({
                curriculum,
                subjectId,
            })),
        onSuccess: async ({ subjectId }) => {
            setEditingCurriculum(null);

            await queryClient.invalidateQueries({
                queryKey: curriculaKey(subjectId),
            });
        },
    });

    function submitSubject(event: FormEvent) {
        event.preventDefault();
        const name = newSubjectName.trim();

        if (name) {
            createSubject.mutate(name);
        }
    }

    function submitCurriculum(event: FormEvent) {
        event.preventDefault();

        if (!selectedSubjectId) {
            return;
        }

        const name = newCurriculumName.trim();

        if (name) {
            createCurriculum.mutate({
                subjectId: selectedSubjectId,
                name,
            });
        }
    }

    if (subjectsQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل إدارة المناهج"
            >
                <Surface>جار تحميل إدارة المناهج…</Surface>
            </section>
        );
    }

    if (subjectsQuery.isError) {
        return (
            <section className="foundation-page">
                <AdminFailure error={subjectsQuery.error}>
                    تعذر تحميل المواد.
                </AdminFailure>
                <Button
                    variant="secondary"
                    onClick={() => {
                        void subjectsQuery.refetch();
                    }}
                >
                    إعادة المحاولة
                </Button>
            </section>
        );
    }

    return (
        <section
            className="foundation-page admin-curricula"
            aria-labelledby="admin-curricula-title"
        >
            <div className="foundation-page__heading">
                <h1
                    id="admin-curricula-title"
                    className="foundation-page__title"
                >
                    إدارة المناهج
                </h1>

                <p className="foundation-page__description">
                    نظّم المواد والمناهج التي ستبني عليها
                    الدروس والأسئلة والتدريبات.
                </p>
            </div>

            <div className="admin-curricula__grid admin-curricula__grid--simple">
                <Surface
                    className="admin-curricula__panel"
                    elevated
                >
                    <div className="foundation-stack">
                        <div>
                            <h2 className="foundation-card__title">
                                المواد
                            </h2>
                            <p className="foundation-card__text">
                                أضف المادة الرئيسية مثل القدرات العامة.
                            </p>
                        </div>

                        <form
                            className="admin-inline-form"
                            onSubmit={submitSubject}
                        >
                            <label>
                                اسم المادة
                                <input
                                    value={newSubjectName}
                                    maxLength={255}
                                    required
                                    onChange={(event) =>
                                        setNewSubjectName(
                                            event.target.value,
                                        )
                                    }
                                />
                            </label>

                            <Button
                                type="submit"
                                disabled={createSubject.isPending}
                            >
                                إضافة مادة
                            </Button>
                        </form>

                        {createSubject.isError ? (
                            <AdminFailure
                                error={createSubject.error}
                            >
                                تعذر إضافة المادة.
                            </AdminFailure>
                        ) : null}

                        <div className="admin-entity-list">
                            {subjectsQuery.data.length === 0 ? (
                                <Feedback>
                                    لا توجد مواد حتى الآن.
                                </Feedback>
                            ) : (
                                subjectsQuery.data.map((subject) => (
                                    <div
                                        key={subject.id}
                                        className={
                                            subject.id
                                                === selectedSubjectId
                                                ? 'admin-entity-list__item admin-entity-list__item--selected'
                                                : 'admin-entity-list__item'
                                        }
                                    >
                                        {editingSubject?.id
                                        === subject.id ? (
                                            <form
                                                className="admin-edit-form"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    const name =
                                                        editingSubject.name.trim();

                                                    if (name) {
                                                        updateSubject.mutate({
                                                            id: subject.id,
                                                            name,
                                                        });
                                                    }
                                                }}
                                            >
                                                <label>
                                                    <span className="sr-only">
                                                        تعديل اسم المادة
                                                    </span>
                                                    <input
                                                        aria-label="تعديل اسم المادة"
                                                        value={editingSubject.name}
                                                        onChange={(event) =>
                                                            setEditingSubject({
                                                                ...editingSubject,
                                                                name: event.target.value,
                                                            })
                                                        }
                                                    />
                                                </label>
                                                <div className="admin-version-actions">
                                                    <Button
                                                        size="sm"
                                                        type="submit"
                                                    >
                                                        حفظ
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        type="button"
                                                        variant="secondary"
                                                        onClick={() =>
                                                            setEditingSubject(null)
                                                        }
                                                    >
                                                        إلغاء
                                                    </Button>
                                                </div>
                                            </form>
                                        ) : (
                                            <>
                                                <button
                                                    type="button"
                                                    className="admin-entity-select"
                                                    onClick={() =>
                                                        setSelectedSubjectId(
                                                            subject.id,
                                                        )
                                                    }
                                                >
                                                    {subject.name}
                                                </button>
                                                <Button
                                                    size="sm"
                                                    type="button"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        setEditingSubject(subject)
                                                    }
                                                >
                                                    تعديل
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </Surface>

                <Surface
                    className="admin-curricula__panel"
                    elevated
                >
                    <div className="foundation-stack">
                        <div>
                            <h2 className="foundation-card__title">
                                المناهج
                            </h2>
                            <p className="foundation-card__text">
                                اختر مادة ثم أضف المنهج أو القسم التابع لها.
                            </p>
                        </div>

                        {selectedSubjectId ? (
                            <form
                                className="admin-inline-form"
                                onSubmit={submitCurriculum}
                            >
                                <label>
                                    اسم المنهج
                                    <input
                                        value={newCurriculumName}
                                        maxLength={255}
                                        required
                                        onChange={(event) =>
                                            setNewCurriculumName(
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>

                                <Button
                                    type="submit"
                                    disabled={
                                        createCurriculum.isPending
                                    }
                                >
                                    إضافة منهج
                                </Button>
                            </form>
                        ) : (
                            <Feedback>
                                اختر مادة أولًا لإضافة منهج لها.
                            </Feedback>
                        )}

                        {createCurriculum.isError ? (
                            <AdminFailure
                                error={createCurriculum.error}
                            >
                                تعذر إضافة المنهج.
                            </AdminFailure>
                        ) : null}

                        {curriculaQuery.isPending ? (
                            <p>جار تحميل المناهج…</p>
                        ) : curriculaQuery.isError ? (
                            <AdminFailure
                                error={curriculaQuery.error}
                            >
                                تعذر تحميل المناهج.
                            </AdminFailure>
                        ) : !selectedSubjectId ? null
                        : curriculaQuery.data.length === 0 ? (
                            <Feedback>
                                لا توجد مناهج لهذه المادة حتى الآن.
                            </Feedback>
                        ) : (
                            <div className="admin-entity-list">
                                {curriculaQuery.data.map(
                                    (curriculum) => (
                                        <div
                                            key={curriculum.id}
                                            className="admin-entity-list__item"
                                        >
                                            {editingCurriculum?.id
                                            === curriculum.id ? (
                                                <form
                                                    className="admin-edit-form"
                                                    onSubmit={(event) => {
                                                        event.preventDefault();
                                                        const name =
                                                            editingCurriculum.name.trim();

                                                        if (
                                                            name
                                                            && selectedSubjectId
                                                        ) {
                                                            updateCurriculum.mutate({
                                                                id: curriculum.id,
                                                                subjectId:
                                                                    selectedSubjectId,
                                                                name,
                                                            });
                                                        }
                                                    }}
                                                >
                                                    <label>
                                                        <span className="sr-only">
                                                            تعديل اسم المنهج
                                                        </span>
                                                        <input
                                                            aria-label="تعديل اسم المنهج"
                                                            value={
                                                                editingCurriculum.name
                                                            }
                                                            onChange={(event) =>
                                                                setEditingCurriculum({
                                                                    ...editingCurriculum,
                                                                    name: event.target.value,
                                                                })
                                                            }
                                                        />
                                                    </label>
                                                    <div className="admin-version-actions">
                                                        <Button
                                                            size="sm"
                                                            type="submit"
                                                        >
                                                            حفظ
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            type="button"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                setEditingCurriculum(null)
                                                            }
                                                        >
                                                            إلغاء
                                                        </Button>
                                                    </div>
                                                </form>
                                            ) : (
                                                <>
                                                    <strong>
                                                        {curriculum.name}
                                                    </strong>
                                                    <Button
                                                        size="sm"
                                                        type="button"
                                                        variant="secondary"
                                                        onClick={() =>
                                                            setEditingCurriculum(
                                                                curriculum,
                                                            )
                                                        }
                                                    >
                                                        تعديل
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    ),
                                )}
                            </div>
                        )}
                    </div>
                </Surface>
            </div>
        </section>
    );
}

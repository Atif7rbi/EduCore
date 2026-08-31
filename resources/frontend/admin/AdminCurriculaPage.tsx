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
    status:
        | 'draft'
        | 'published'
        | 'retired';
    created_at?: string | null;
    updated_at?: string | null;
}

function subjectsKey() {
    return [
        'admin',
        'subjects',
    ] as const;
}

function curriculaKey(
    subjectId: string,
) {
    return [
        'admin',
        'subjects',
        subjectId,
        'curricula',
    ] as const;
}

function versionsKey(
    curriculumId: string,
) {
    return [
        'admin',
        'curricula',
        curriculumId,
        'versions',
    ] as const;
}

async function fetchSubjects():
Promise<Subject[]> {
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
        url:
            `/api/admin/subjects/${subjectId}/curricula`,
    });
}

async function fetchVersions(
    curriculumId: string,
): Promise<CurriculumVersion[]> {
    return apiRequest<CurriculumVersion[]>({
        method: 'GET',
        url:
            `/api/admin/curricula/${curriculumId}/versions`,
    });
}

function requestId(
    error: unknown,
): string | null {
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
    status: CurriculumVersion['status'],
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

export function AdminCurriculaPage() {
    const queryClient =
        useQueryClient();

    const [selectedSubjectId, setSelectedSubjectId] =
        useState<string | null>(null);

    const [
        selectedCurriculumId,
        setSelectedCurriculumId,
    ] = useState<string | null>(null);

    const [newSubjectName, setNewSubjectName] =
        useState('');

    const [newCurriculumName, setNewCurriculumName] =
        useState('');

    const [newVersionNumber, setNewVersionNumber] =
        useState('');

    const [newVersionLabel, setNewVersionLabel] =
        useState('');

    const [
        editingSubject,
        setEditingSubject,
    ] = useState<Subject | null>(null);

    const [
        editingCurriculum,
        setEditingCurriculum,
    ] = useState<Curriculum | null>(null);

    const [
        editingVersion,
        setEditingVersion,
    ] = useState<CurriculumVersion | null>(
        null,
    );

    const subjectsQuery = useQuery({
        queryKey: subjectsKey(),
        queryFn: fetchSubjects,
    });

    const curriculaQuery = useQuery({
        queryKey: curriculaKey(
            selectedSubjectId ?? '',
        ),
        queryFn: () =>
            fetchCurricula(
                selectedSubjectId!,
            ),
        enabled:
            selectedSubjectId !== null,
    });

    const versionsQuery = useQuery({
        queryKey: versionsKey(
            selectedCurriculumId ?? '',
        ),
        queryFn: () =>
            fetchVersions(
                selectedCurriculumId!,
            ),
        enabled:
            selectedCurriculumId !== null,
    });

    useEffect(() => {
        const subjects =
            subjectsQuery.data;

        if (
            subjects
            && subjects.length > 0
            && selectedSubjectId === null
        ) {
            setSelectedSubjectId(
                subjects[0].id,
            );
        }
    }, [
        selectedSubjectId,
        subjectsQuery.data,
    ]);

    useEffect(() => {
        const curricula =
            curriculaQuery.data;

        if (
            curricula
            && curricula.length > 0
            && selectedCurriculumId === null
        ) {
            setSelectedCurriculumId(
                curricula[0].id,
            );
        }

        if (
            curricula
            && curricula.length === 0
        ) {
            setSelectedCurriculumId(null);
        }
    }, [
        curriculaQuery.data,
        selectedCurriculumId,
    ]);

    const createSubject =
        useMutation({
            mutationFn: (
                name: string,
            ) =>
                apiRequest<Subject>({
                    method: 'POST',
                    url:
                        '/api/admin/subjects',
                    data: {
                        name,
                    },
                }),
            onSuccess: async (
                subject,
            ) => {
                setNewSubjectName('');
                setSelectedSubjectId(
                    subject.id,
                );
                setSelectedCurriculumId(
                    null,
                );

                await queryClient.invalidateQueries({
                    queryKey:
                        subjectsKey(),
                });
            },
        });

    const updateSubject =
        useMutation({
            mutationFn: ({
                id,
                name,
            }: {
                id: string;
                name: string;
            }) =>
                apiRequest<Subject>({
                    method: 'PUT',
                    url:
                        `/api/admin/subjects/${id}`,
                    data: {
                        name,
                    },
                }),
            onSuccess: async () => {
                setEditingSubject(null);

                await queryClient.invalidateQueries({
                    queryKey:
                        subjectsKey(),
                });
            },
        });

    const createCurriculum =
        useMutation({
            mutationFn: ({
                subjectId,
                name,
            }: {
                subjectId: string;
                name: string;
            }) =>
                apiRequest<Curriculum>({
                    method: 'POST',
                    url:
                        `/api/admin/subjects/${subjectId}/curricula`,
                    data: {
                        name,
                    },
                }),
            onSuccess: async (
                curriculum,
            ) => {
                setNewCurriculumName('');
                setSelectedCurriculumId(
                    curriculum.id,
                );

                await queryClient.invalidateQueries({
                    queryKey:
                        curriculaKey(
                            curriculum.subject_id,
                        ),
                });
            },
        });

    const updateCurriculum =
        useMutation({
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
                    url:
                        `/api/admin/curricula/${id}`,
                    data: {
                        name,
                    },
                }).then(
                    (curriculum) => ({
                        curriculum,
                        subjectId,
                    }),
                ),
            onSuccess: async ({
                subjectId,
            }) => {
                setEditingCurriculum(null);

                await queryClient.invalidateQueries({
                    queryKey:
                        curriculaKey(
                            subjectId,
                        ),
                });
            },
        });

    const createVersion =
        useMutation({
            mutationFn: ({
                curriculumId,
                versionNumber,
                label,
            }: {
                curriculumId: string;
                versionNumber: number;
                label: string;
            }) =>
                apiRequest<CurriculumVersion>({
                    method: 'POST',
                    url:
                        `/api/admin/curricula/${curriculumId}/versions`,
                    data: {
                        version_number:
                            versionNumber,
                        label,
                    },
                }),
            onSuccess: async (
                version,
            ) => {
                setNewVersionNumber('');
                setNewVersionLabel('');

                await queryClient.invalidateQueries({
                    queryKey:
                        versionsKey(
                            version.curriculum_id,
                        ),
                });
            },
        });

    const updateVersion =
        useMutation({
            mutationFn: ({
                id,
                curriculumId,
                versionNumber,
                label,
            }: {
                id: string;
                curriculumId: string;
                versionNumber: number;
                label: string;
            }) =>
                apiRequest<CurriculumVersion>({
                    method: 'PUT',
                    url:
                        `/api/admin/curriculum-versions/${id}`,
                    data: {
                        version_number:
                            versionNumber,
                        label,
                    },
                }).then(
                    (version) => ({
                        version,
                        curriculumId,
                    }),
                ),
            onSuccess: async ({
                curriculumId,
            }) => {
                setEditingVersion(null);

                await queryClient.invalidateQueries({
                    queryKey:
                        versionsKey(
                            curriculumId,
                        ),
                });
            },
        });

    const publishVersion =
        useMutation({
            mutationFn: ({
                id,
                curriculumId,
            }: {
                id: string;
                curriculumId: string;
            }) =>
                apiRequest<CurriculumVersion>({
                    method: 'POST',
                    url:
                        `/api/curriculum-versions/${id}/publish`,
                    data: {},
                }).then(
                    (version) => ({
                        version,
                        curriculumId,
                    }),
                ),
            onSuccess: async ({
                curriculumId,
            }) => {
                await queryClient.invalidateQueries({
                    queryKey:
                        versionsKey(
                            curriculumId,
                        ),
                });
            },
        });

    const retireVersion =
        useMutation({
            mutationFn: ({
                id,
                curriculumId,
            }: {
                id: string;
                curriculumId: string;
            }) =>
                apiRequest<CurriculumVersion>({
                    method: 'POST',
                    url:
                        `/api/curriculum-versions/${id}/retire`,
                    data: {},
                }).then(
                    (version) => ({
                        version,
                        curriculumId,
                    }),
                ),
            onSuccess: async ({
                curriculumId,
            }) => {
                await queryClient.invalidateQueries({
                    queryKey:
                        versionsKey(
                            curriculumId,
                        ),
                });
            },
        });

    const selectedSubject =
        subjectsQuery.data?.find(
            (subject) =>
                subject.id
                === selectedSubjectId,
        ) ?? null;

    const selectedCurriculum =
        curriculaQuery.data?.find(
            (curriculum) =>
                curriculum.id
                === selectedCurriculumId,
        ) ?? null;

    function submitSubject(
        event: FormEvent,
    ) {
        event.preventDefault();

        const name =
            newSubjectName.trim();

        if (!name) {
            return;
        }

        createSubject.mutate(name);
    }

    function submitCurriculum(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (!selectedSubjectId) {
            return;
        }

        const name =
            newCurriculumName.trim();

        if (!name) {
            return;
        }

        createCurriculum.mutate({
            subjectId:
                selectedSubjectId,
            name,
        });
    }

    function submitVersion(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (!selectedCurriculumId) {
            return;
        }

        const versionNumber =
            Number(newVersionNumber);

        const label =
            newVersionLabel.trim();

        if (
            !Number.isInteger(
                versionNumber,
            )
            || versionNumber < 1
            || !label
        ) {
            return;
        }

        createVersion.mutate({
            curriculumId:
                selectedCurriculumId,
            versionNumber,
            label,
        });
    }

    if (subjectsQuery.isPending) {
        return (
            <section
                className="foundation-page"
                aria-busy="true"
                aria-label="جار تحميل إدارة المناهج"
            >
                <Surface>
                    جار تحميل إدارة المناهج…
                </Surface>
            </section>
        );
    }

    if (subjectsQuery.isError) {
        return (
            <section className="foundation-page">
                <AdminFailure
                    error={subjectsQuery.error}
                >
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
                <p className="foundation-page__eyebrow">
                    Admin Studio
                </p>

                <h1
                    id="admin-curricula-title"
                    className="foundation-page__title"
                >
                    إدارة المناهج
                </h1>

                <p className="foundation-page__description">
                    إدارة المواد والمناهج وإصداراتها
                    مع احترام دورة حياة النشر المعتمدة.
                </p>
            </div>

            <div className="admin-curricula__grid">
                <Surface
                    className="admin-curricula__panel"
                    elevated
                >
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            المواد
                        </h2>

                        <form
                            className="admin-inline-form"
                            onSubmit={
                                submitSubject
                            }
                        >
                            <label>
                                اسم المادة
                                <input
                                    value={
                                        newSubjectName
                                    }
                                    maxLength={255}
                                    required
                                    onChange={(
                                        event,
                                    ) =>
                                        setNewSubjectName(
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
                                    createSubject
                                        .isPending
                                }
                            >
                                إضافة مادة
                            </Button>
                        </form>

                        {createSubject.isError ? (
                            <AdminFailure
                                error={
                                    createSubject.error
                                }
                            >
                                تعذر إضافة المادة.
                            </AdminFailure>
                        ) : null}

                        {updateSubject.isError ? (
                            <AdminFailure
                                error={
                                    updateSubject.error
                                }
                            >
                                تعذر تحديث المادة.
                            </AdminFailure>
                        ) : null}

                        <div className="admin-entity-list">
                            {subjectsQuery.data
                                .length === 0 ? (
                                <p>
                                    لا توجد مواد حتى الآن.
                                </p>
                            ) : (
                                subjectsQuery.data.map(
                                    (subject) => (
                                        <div
                                            key={
                                                subject.id
                                            }
                                            className={
                                                selectedSubjectId
                                                === subject.id
                                                    ? 'admin-entity-list__item admin-entity-list__item--selected'
                                                    : 'admin-entity-list__item'
                                            }
                                        >
                                            {editingSubject
                                                ?.id
                                                === subject.id ? (
                                                <form
                                                    className="admin-edit-form"
                                                    onSubmit={(
                                                        event,
                                                    ) => {
                                                        event.preventDefault();

                                                        const name =
                                                            editingSubject.name.trim();

                                                        if (
                                                            name
                                                        ) {
                                                            updateSubject.mutate({
                                                                id:
                                                                    subject.id,
                                                                name,
                                                            });
                                                        }
                                                    }}
                                                >
                                                    <input
                                                        aria-label="تعديل اسم المادة"
                                                        value={
                                                            editingSubject.name
                                                        }
                                                        maxLength={255}
                                                        required
                                                        onChange={(
                                                            event,
                                                        ) =>
                                                            setEditingSubject({
                                                                ...editingSubject,
                                                                name:
                                                                    event.target.value,
                                                            })
                                                        }
                                                    />

                                                    <Button
                                                        size="sm"
                                                        type="submit"
                                                    disabled={
                                                        updateSubject
                                                            .isPending
                                                    }
                                                    >
                                                        حفظ
                                                    </Button>

                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        type="button"
                                                        onClick={() =>
                                                            setEditingSubject(
                                                                null,
                                                            )
                                                        }
                                                    >
                                                        إلغاء
                                                    </Button>
                                                </form>
                                            ) : (
                                                <>
                                                    <button
                                                        type="button"
                                                        className="admin-entity-select"
                                                        onClick={() => {
                                                            setSelectedSubjectId(
                                                                subject.id,
                                                            );
                                                            setSelectedCurriculumId(
                                                                null,
                                                            );
                                                            setEditingCurriculum(
                                                                null,
                                                            );
                                                            setEditingVersion(
                                                                null,
                                                            );
                                                        }}
                                                    >
                                                        {
                                                            subject.name
                                                        }
                                                    </button>

                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        onClick={() =>
                                                            setEditingSubject(
                                                                {
                                                                    ...subject,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        تعديل
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    ),
                                )
                            )}
                        </div>
                    </div>
                </Surface>

                <Surface
                    className="admin-curricula__panel"
                    elevated
                >
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            المناهج
                        </h2>

                        {selectedSubject ? (
                            <p className="foundation-page__description">
                                المادة: {
                                    selectedSubject.name
                                }
                            </p>
                        ) : null}

                        {!selectedSubjectId ? (
                            <Feedback>
                                اختر مادة لإدارة مناهجها.
                            </Feedback>
                        ) : (
                            <>
                                <form
                                    className="admin-inline-form"
                                    onSubmit={
                                        submitCurriculum
                                    }
                                >
                                    <label>
                                        اسم المنهج
                                        <input
                                            value={
                                                newCurriculumName
                                            }
                                            maxLength={
                                                255
                                            }
                                            required
                                            onChange={(
                                                event,
                                            ) =>
                                                setNewCurriculumName(
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
                                            createCurriculum
                                                .isPending
                                        }
                                    >
                                        إضافة منهج
                                    </Button>
                                </form>

                                {createCurriculum.isError ? (
                                    <AdminFailure
                                        error={
                                            createCurriculum.error
                                        }
                                    >
                                        تعذر إضافة المنهج.
                                    </AdminFailure>
                                ) : null}

                                {updateCurriculum.isError ? (
                                    <AdminFailure
                                        error={
                                            updateCurriculum.error
                                        }
                                    >
                                        تعذر تحديث المنهج.
                                    </AdminFailure>
                                ) : null}

                                {curriculaQuery.isPending ? (
                                    <p>
                                        جار تحميل المناهج…
                                    </p>
                                ) : curriculaQuery.isError ? (
                                    <AdminFailure
                                        error={
                                            curriculaQuery.error
                                        }
                                    >
                                        تعذر تحميل المناهج.
                                    </AdminFailure>
                                ) : curriculaQuery.data
                                    .length === 0 ? (
                                    <p>
                                        لا توجد مناهج لهذه المادة.
                                    </p>
                                ) : (
                                    <div className="admin-entity-list">
                                        {curriculaQuery.data.map(
                                            (
                                                curriculum,
                                            ) => (
                                                <div
                                                    key={
                                                        curriculum.id
                                                    }
                                                    className={
                                                        selectedCurriculumId
                                                        === curriculum.id
                                                            ? 'admin-entity-list__item admin-entity-list__item--selected'
                                                            : 'admin-entity-list__item'
                                                    }
                                                >
                                                    {editingCurriculum
                                                        ?.id
                                                        === curriculum.id ? (
                                                        <form
                                                            className="admin-edit-form"
                                                            onSubmit={(
                                                                event,
                                                            ) => {
                                                                event.preventDefault();

                                                                const name =
                                                                    editingCurriculum.name.trim();

                                                                if (
                                                                    name
                                                                ) {
                                                                    updateCurriculum.mutate({
                                                                        id:
                                                                            curriculum.id,
                                                                        subjectId:
                                                                            curriculum.subject_id,
                                                                        name,
                                                                    });
                                                                }
                                                            }}
                                                        >
                                                            <input
                                                                aria-label="تعديل اسم المنهج"
                                                                value={
                                                                    editingCurriculum.name
                                                                }
                                                                maxLength={
                                                                    255
                                                                }
                                                                required
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setEditingCurriculum({
                                                                        ...editingCurriculum,
                                                                        name:
                                                                            event.target.value,
                                                                    })
                                                                }
                                                            />

                                                            <Button
                                                                size="sm"
                                                                type="submit"
                                                            disabled={
                                                                updateCurriculum
                                                                    .isPending
                                                            }
                                                            >
                                                                حفظ
                                                            </Button>

                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                type="button"
                                                                onClick={() =>
                                                                    setEditingCurriculum(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                إلغاء
                                                            </Button>
                                                        </form>
                                                    ) : (
                                                        <>
                                                            <button
                                                                type="button"
                                                                className="admin-entity-select"
                                                                onClick={() => {
                                                                    setSelectedCurriculumId(
                                                                        curriculum.id,
                                                                    );
                                                                    setEditingVersion(
                                                                        null,
                                                                    );
                                                                }}
                                                            >
                                                                {
                                                                    curriculum.name
                                                                }
                                                            </button>

                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    setEditingCurriculum(
                                                                        {
                                                                            ...curriculum,
                                                                        },
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
                            </>
                        )}
                    </div>
                </Surface>

                <Surface
                    className="admin-curricula__panel"
                    elevated
                >
                    <div className="foundation-stack">
                        <h2 className="foundation-card__title">
                            إصدارات المنهج
                        </h2>

                        {selectedCurriculum ? (
                            <p className="foundation-page__description">
                                المنهج: {
                                    selectedCurriculum.name
                                }
                            </p>
                        ) : null}

                        {!selectedCurriculumId ? (
                            <Feedback>
                                اختر منهجًا لإدارة إصداراته.
                            </Feedback>
                        ) : (
                            <>
                                <form
                                    className="admin-version-create"
                                    onSubmit={
                                        submitVersion
                                    }
                                >
                                    <label>
                                        رقم الإصدار
                                        <input
                                            type="number"
                                            min={1}
                                            step={1}
                                            value={
                                                newVersionNumber
                                            }
                                            required
                                            onChange={(
                                                event,
                                            ) =>
                                                setNewVersionNumber(
                                                    event
                                                        .target
                                                        .value,
                                                )
                                            }
                                        />
                                    </label>

                                    <label>
                                        اسم الإصدار
                                        <input
                                            value={
                                                newVersionLabel
                                            }
                                            maxLength={
                                                255
                                            }
                                            required
                                            onChange={(
                                                event,
                                            ) =>
                                                setNewVersionLabel(
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
                                            createVersion
                                                .isPending
                                        }
                                    >
                                        إنشاء مسودة
                                    </Button>
                                </form>

                                {createVersion.isError ? (
                                    <AdminFailure
                                        error={
                                            createVersion.error
                                        }
                                    >
                                        تعذر إنشاء الإصدار.
                                    </AdminFailure>
                                ) : null}

                                {updateVersion.isError ? (
                                    <AdminFailure
                                        error={
                                            updateVersion.error
                                        }
                                    >
                                        تعذر تحديث الإصدار.
                                    </AdminFailure>
                                ) : null}

                                {publishVersion.isError ? (
                                    <AdminFailure
                                        error={
                                            publishVersion.error
                                        }
                                    >
                                        تعذر نشر الإصدار.
                                    </AdminFailure>
                                ) : null}

                                {retireVersion.isError ? (
                                    <AdminFailure
                                        error={
                                            retireVersion.error
                                        }
                                    >
                                        تعذر تقاعد الإصدار.
                                    </AdminFailure>
                                ) : null}

                                {versionsQuery.isPending ? (
                                    <p>
                                        جار تحميل الإصدارات…
                                    </p>
                                ) : versionsQuery.isError ? (
                                    <AdminFailure
                                        error={
                                            versionsQuery.error
                                        }
                                    >
                                        تعذر تحميل الإصدارات.
                                    </AdminFailure>
                                ) : versionsQuery.data
                                    .length === 0 ? (
                                    <p>
                                        لا توجد إصدارات لهذا المنهج.
                                    </p>
                                ) : (
                                    <div className="admin-version-list">
                                        {versionsQuery.data.map(
                                            (
                                                version,
                                            ) => (
                                                <div
                                                    key={
                                                        version.id
                                                    }
                                                    className="admin-version-card"
                                                >
                                                    {editingVersion
                                                        ?.id
                                                        === version.id ? (
                                                        <form
                                                            className="admin-version-edit"
                                                            onSubmit={(
                                                                event,
                                                            ) => {
                                                                event.preventDefault();

                                                                const number =
                                                                    editingVersion.version_number;

                                                                const label =
                                                                    editingVersion.label.trim();

                                                                if (
                                                                    Number.isInteger(
                                                                        number,
                                                                    )
                                                                    && number
                                                                        >= 1
                                                                    && label
                                                                ) {
                                                                    updateVersion.mutate({
                                                                        id:
                                                                            version.id,
                                                                        curriculumId:
                                                                            version.curriculum_id,
                                                                        versionNumber:
                                                                            number,
                                                                        label,
                                                                    });
                                                                }
                                                            }}
                                                        >
                                                            <label>
                                                                رقم الإصدار
                                                                <input
                                                                    aria-label="تعديل رقم الإصدار"
                                                                    type="number"
                                                                    min={
                                                                        1
                                                                    }
                                                                    step={
                                                                        1
                                                                    }
                                                                    value={
                                                                        editingVersion.version_number
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setEditingVersion({
                                                                            ...editingVersion,
                                                                            version_number:
                                                                                Number(
                                                                                    event.target.value,
                                                                                ),
                                                                        })
                                                                    }
                                                                />
                                                            </label>

                                                            <label>
                                                                اسم الإصدار
                                                                <input
                                                                    aria-label="تعديل اسم الإصدار"
                                                                    value={
                                                                        editingVersion.label
                                                                    }
                                                                    maxLength={
                                                                        255
                                                                    }
                                                                    onChange={(
                                                                        event,
                                                                    ) =>
                                                                        setEditingVersion({
                                                                            ...editingVersion,
                                                                            label:
                                                                                event.target.value,
                                                                        })
                                                                    }
                                                                />
                                                            </label>

                                                            <Button
                                                                size="sm"
                                                                type="submit"
                                                            disabled={
                                                                updateVersion
                                                                    .isPending
                                                            }
                                                            >
                                                                حفظ
                                                            </Button>

                                                            <Button
                                                                size="sm"
                                                                variant="secondary"
                                                                type="button"
                                                                onClick={() =>
                                                                    setEditingVersion(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                إلغاء
                                                            </Button>
                                                        </form>
                                                    ) : (
                                                        <>
                                                            <div>
                                                                <strong>
                                                                    {
                                                                        version.label
                                                                    }
                                                                </strong>

                                                                <p>
                                                                    الإصدار {
                                                                        version.version_number
                                                                    }
                                                                </p>

                                                                <span className={
                                                                    `admin-lifecycle admin-lifecycle--${version.status}`
                                                                }>
                                                                    {
                                                                        statusLabel(
                                                                            version.status,
                                                                        )
                                                                    }
                                                                </span>
                                                            </div>

                                                            <div className="admin-version-actions">
                                                                {version.status
                                                                    === 'draft' ? (
                                                                    <>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="secondary"
                                                                            onClick={() =>
                                                                                setEditingVersion(
                                                                                    {
                                                                                        ...version,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            تعديل
                                                                        </Button>

                                                                        <Button
                                                                            size="sm"
                                                                            disabled={
                                                                                publishVersion
                                                                                    .isPending
                                                                                || retireVersion
                                                                                    .isPending
                                                                            }
                                                                            onClick={() =>
                                                                                publishVersion.mutate({
                                                                                    id:
                                                                                        version.id,
                                                                                    curriculumId:
                                                                                        version.curriculum_id,
                                                                                })
                                                                            }
                                                                        >
                                                                            نشر
                                                                        </Button>
                                                                    </>
                                                                ) : null}

                                                                {version.status
                                                                    === 'published' ? (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="secondary"
                                                                        disabled={
                                                                            publishVersion
                                                                                .isPending
                                                                            || retireVersion
                                                                                .isPending
                                                                        }
                                                                        onClick={() =>
                                                                            retireVersion.mutate({
                                                                                id:
                                                                                    version.id,
                                                                                curriculumId:
                                                                                    version.curriculum_id,
                                                                            })
                                                                        }
                                                                    >
                                                                        تقاعد
                                                                    </Button>
                                                                ) : null}

                                                                {version.status
                                                                    === 'retired' ? (
                                                                    <span>
                                                                        للقراءة فقط
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                        </>
                                                    )}
                                                </div>
                                            ),
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </Surface>
            </div>
        </section>
    );
}

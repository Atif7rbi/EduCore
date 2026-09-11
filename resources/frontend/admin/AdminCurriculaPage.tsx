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

type CatalogStatus =
    | 'active'
    | 'inactive';

interface Subject {
    id: string;
    code: string;
    name: string;
    icon_key: string | null;
    thumbnail_key: string | null;
    sort_order: number;
    status: CatalogStatus;
    curricula_count: number;
    created_at: string | null;
    updated_at: string | null;
}

interface EducationStage {
    id: string;
    code: string;
    name: string;
    sort_order: number;
    status: CatalogStatus;
    curricula_count: number;
}

interface Curriculum {
    id: string;
    subject_id: string;
    education_stage_id: string | null;
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

const CURRICULA_PAGE_SIZE = 20;

function subjectsKey() {
    return ['admin', 'subjects'] as const;
}

function educationStagesKey() {
    return ['admin', 'education-stages'] as const;
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

async function fetchEducationStages():
Promise<EducationStage[]> {
    return apiRequest<EducationStage[]>({
        method: 'GET',
        url: '/api/admin/education-stages',
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

function SubjectCatalogIcon({
    iconKey,
}: {
    iconKey: string | null;
}) {
    let glyph = (
        <>
            <path d="M5 4.5h6a3 3 0 0 1 3 3v12H8a3 3 0 0 0-3 3v-18Z" />
            <path d="M19 4.5h-2.5A2.5 2.5 0 0 0 14 7v12h5V4.5Z" />
        </>
    );

    switch (iconKey) {
        case 'subjects/mathematics/icon':
            glyph = (
                <>
                    <rect
                        x="4"
                        y="3"
                        width="16"
                        height="18"
                        rx="3"
                    />
                    <path d="M7.5 7h9M8 11h2M14 11h2M8 15h2M14 15h2M8 18h8" />
                </>
            );
            break;

        case 'subjects/physics/icon':
            glyph = (
                <>
                    <circle cx="12" cy="12" r="1.8" />
                    <ellipse
                        cx="12"
                        cy="12"
                        rx="9"
                        ry="3.5"
                    />
                    <ellipse
                        cx="12"
                        cy="12"
                        rx="9"
                        ry="3.5"
                        transform="rotate(60 12 12)"
                    />
                    <ellipse
                        cx="12"
                        cy="12"
                        rx="9"
                        ry="3.5"
                        transform="rotate(120 12 12)"
                    />
                </>
            );
            break;

        case 'subjects/biology/icon':
            glyph = (
                <>
                    <path d="M19.5 4.5C12 4.5 6.2 8.2 5 15.5c3.8.5 7-.2 9.4-2.2 2.6-2.1 4.2-5.1 5.1-8.8Z" />
                    <path d="M5 20c2.1-4.5 5.4-7.6 10.2-9.6" />
                </>
            );
            break;

        case 'subjects/chemistry/icon':
            glyph = (
                <>
                    <path d="M9 3h6M10 3v6l-5 8.5A2.3 2.3 0 0 0 7 21h10a2.3 2.3 0 0 0 2-3.5L14 9V3" />
                    <path d="M7.5 15h9" />
                    <circle cx="10" cy="17.5" r=".8" />
                    <circle cx="14" cy="18" r=".8" />
                </>
            );
            break;

        case 'subjects/english_language/icon':
            glyph = (
                <>
                    <path d="M4 5h16v11H9l-5 4V5Z" />
                    <path d="M8 9h8M8 12h5" />
                </>
            );
            break;

        case 'subjects/arabic_language/icon':
            glyph = (
                <>
                    <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21V5.5Z" />
                    <path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5A2.5 2.5 0 0 1 20 21V5.5Z" />
                    <path d="M7 8h2M15 8h2" />
                </>
            );
            break;

        default:
            break;
    }

    return (
        <svg
            className="admin-subject-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            {glyph}
        </svg>
    );
}

export function AdminCurriculaPage() {
    const queryClient = useQueryClient();

    const [selectedSubjectId, setSelectedSubjectId] =
        useState<string | null>(null);
    const [subjectSearch, setSubjectSearch] =
        useState('');
    const [newCurriculumName, setNewCurriculumName] =
        useState('');
    const [
        newEducationStageId,
        setNewEducationStageId,
    ] = useState('');
    const [isCurriculumFormOpen, setIsCurriculumFormOpen] =
        useState(false);
    const [curriculumSearch, setCurriculumSearch] =
        useState('');
    const [curriculumPage, setCurriculumPage] =
        useState(1);
    const [editingCurriculum, setEditingCurriculum] =
        useState<Curriculum | null>(null);

    const subjectsQuery = useQuery({
        queryKey: subjectsKey(),
        queryFn: fetchSubjects,
    });

    const educationStagesQuery = useQuery({
        queryKey: educationStagesKey(),
        queryFn: fetchEducationStages,
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

    useEffect(() => {
        setCurriculumSearch('');
        setCurriculumPage(1);
        setNewCurriculumName('');
        setNewEducationStageId('');
        setIsCurriculumFormOpen(false);
    }, [selectedSubjectId]);

    const selectedSubject =
        subjectsQuery.data?.find(
            (subject) => subject.id === selectedSubjectId,
        ) ?? null;

    const activeEducationStages =
        educationStagesQuery.data?.filter(
            (stage) => stage.status === 'active',
        ) ?? [];

    const normalizedSubjectSearch =
        subjectSearch.trim().toLocaleLowerCase('ar');

    const filteredSubjects =
        subjectsQuery.data?.filter((subject) =>
            subject.name
                .toLocaleLowerCase('ar')
                .includes(normalizedSubjectSearch),
        ) ?? [];

    const normalizedCurriculumSearch =
        curriculumSearch.trim().toLocaleLowerCase('ar');

    const filteredCurricula =
        curriculaQuery.data?.filter((curriculum) =>
            curriculum.name
                .toLocaleLowerCase('ar')
                .includes(normalizedCurriculumSearch),
        ) ?? [];

    const curriculumPageCount = Math.max(
        1,
        Math.ceil(
            filteredCurricula.length / CURRICULA_PAGE_SIZE,
        ),
    );

    const safeCurriculumPage = Math.min(
        curriculumPage,
        curriculumPageCount,
    );

    const curriculumPageStart =
        (safeCurriculumPage - 1) * CURRICULA_PAGE_SIZE;

    const visibleCurricula = filteredCurricula.slice(
        curriculumPageStart,
        curriculumPageStart + CURRICULA_PAGE_SIZE,
    );

    const createCurriculum = useMutation({
        mutationFn: async ({
            subjectId,
            name,
            educationStageId,
        }: {
            subjectId: string;
            name: string;
            educationStageId: string | null;
        }) => {
            const curriculum =
                await apiRequest<Curriculum>({
                    method: 'POST',
                    url: `/api/admin/subjects/${subjectId}/curricula`,
                    data: {
                        name,
                        education_stage_id:
                            educationStageId,
                    },
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
            setNewEducationStageId('');
            setCurriculumSearch('');
            setCurriculumPage(1);
            setIsCurriculumFormOpen(false);

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
                educationStageId:
                    newEducationStageId || null,
            });
        }
    }

    function curriculumStageLabel(
        curriculum: Curriculum,
    ) {
        if (!curriculum.education_stage_id) {
            return 'بدون مرحلة محددة';
        }

        return (
            educationStagesQuery.data?.find(
                (stage) =>
                    stage.id
                    === curriculum.education_stage_id,
            )?.name
            ?? 'مرحلة تعليمية محفوظة'
        );
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
                    استعرض المواد المعتمدة وأدر المناهج التي
                    ستبني عليها الدروس والأسئلة والتدريبات.
                </p>
            </div>

            <div className="admin-curricula__grid admin-curricula__grid--simple">
                <Surface
                    className="admin-curricula__panel admin-curricula__panel--subjects"
                    elevated
                >
                    <div className="foundation-stack">
                        <div className="admin-curricula__pane-header">
                            <div>
                                <div className="admin-curricula__pane-title-row">
                                    <h2 className="foundation-card__title">
                                        المواد
                                    </h2>
                                    <span className="admin-curricula__pane-total">
                                        {subjectsQuery.data.length}
                                    </span>
                                </div>
                                <p className="foundation-card__text">
                                    اختر من المواد المعتمدة في المنصة
                                    لإدارة مناهجها.
                                </p>
                            </div>

                        </div>

                        {subjectsQuery.data.length === 0 ? (
                            <Feedback>
                                لا توجد مواد حتى الآن.
                            </Feedback>
                        ) : (
                            <div className="admin-curricula-browser">
                                <div className="admin-curricula-browser__toolbar">
                                    <label className="admin-curricula-browser__search">
                                        <span className="sr-only">
                                            بحث في المواد
                                        </span>
                                        <input
                                            type="search"
                                            aria-label="بحث في المواد"
                                            placeholder="ابحث باسم المادة…"
                                            value={subjectSearch}
                                            onChange={(event) =>
                                                setSubjectSearch(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </label>

                                    <span className="admin-curricula-browser__count">
                                        {filteredSubjects.length}
                                        {' '}
                                        مادة
                                    </span>
                                </div>

                                {filteredSubjects.length === 0 ? (
                                    <Feedback>
                                        لا توجد مواد مطابقة للبحث.
                                    </Feedback>
                                ) : (
                                    <div
                                        className="admin-entity-list admin-curricula-browser__list"
                                        aria-label="قائمة المواد"
                                    >
                                        {filteredSubjects.map((subject) => (
                                            <div
                                                key={subject.id}
                                                className={
                                                    subject.id
                                                        === selectedSubjectId
                                                        ? 'admin-entity-list__item admin-entity-list__item--selected'
                                                        : 'admin-entity-list__item'
                                                }
                                            >
                                                <button
                                                    type="button"
                                                    className="admin-entity-select admin-subject-select"
                                                    aria-label={`اختيار مادة ${subject.name}`}
                                                    aria-pressed={
                                                        subject.id
                                                        === selectedSubjectId
                                                    }
                                                    onClick={() => {
                                                        setSelectedSubjectId(
                                                            subject.id,
                                                        );
                                                        setEditingCurriculum(
                                                            null,
                                                        );
                                                    }}
                                                >
                                                    <span
                                                        className="admin-subject-visual"
                                                        data-icon-key={
                                                            subject.icon_key
                                                            ?? undefined
                                                        }
                                                        data-thumbnail-key={
                                                            subject.thumbnail_key
                                                            ?? undefined
                                                        }
                                                    >
                                                        <SubjectCatalogIcon
                                                            iconKey={
                                                                subject.icon_key
                                                            }
                                                        />
                                                    </span>

                                                    <span className="admin-subject-copy">
                                                        <strong>
                                                            {subject.name}
                                                        </strong>

                                                        <span className="admin-subject-meta">
                                                            <span>
                                                                {
                                                                    subject.curricula_count
                                                                }
                                                                {' '}
                                                                منهج
                                                            </span>

                                                            {subject.status
                                                            === 'inactive' ? (
                                                                <span className="admin-subject-status admin-subject-status--inactive">
                                                                    غير نشط
                                                                </span>
                                                            ) : null}
                                                        </span>
                                                    </span>
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </Surface>

                <Surface
                    className="admin-curricula__panel admin-curricula__panel--curricula"
                    elevated
                >
                    <div className="foundation-stack">
                        <div className="admin-curricula__pane-header">
                            <div>
                                <div className="admin-curricula__pane-title-row">
                                    <h2 className="foundation-card__title">
                                        المناهج
                                    </h2>
                                    <span className="admin-curricula__pane-total">
                                        {curriculaQuery.data?.length ?? 0}
                                    </span>
                                </div>

                                <p className="foundation-card__text">
                                    المناهج التابعة للمادة المحددة.
                                </p>

                                <p className="admin-curricula__current-context">
                                    <span>المادة الحالية</span>
                                    <strong>
                                        {selectedSubject?.name ?? '—'}
                                    </strong>
                                </p>

                                {selectedSubject?.status
                                === 'inactive' ? (
                                    <p className="admin-curricula__readonly-note">
                                        هذه المادة غير نشطة؛ يمكن
                                        استعراض مناهجها الحالية فقط.
                                    </p>
                                ) : null}
                            </div>

                            <Button
                                size="sm"
                                type="button"
                                variant="secondary"
                                disabled={
                                    !selectedSubjectId
                                    || selectedSubject?.status
                                        !== 'active'
                                }
                                onClick={() => {
                                    setIsCurriculumFormOpen(
                                        !isCurriculumFormOpen,
                                    );

                                    if (isCurriculumFormOpen) {
                                        setNewCurriculumName('');
                                        setNewEducationStageId('');
                                    }
                                }}
                            >
                                {isCurriculumFormOpen
                                    ? 'إلغاء'
                                    : '+ إضافة منهج'}
                            </Button>
                        </div>

                        {selectedSubjectId
                        && isCurriculumFormOpen ? (
                            <form
                                className="admin-inline-form admin-curricula__create-form"
                                onSubmit={submitCurriculum}
                            >
                                <label>
                                    اسم المنهج
                                    <input
                                        value={newCurriculumName}
                                        maxLength={255}
                                        required
                                        autoFocus
                                        onChange={(event) =>
                                            setNewCurriculumName(
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>

                                <label>
                                    المرحلة التعليمية
                                    <select
                                        aria-label="المرحلة التعليمية"
                                        value={newEducationStageId}
                                        disabled={
                                            educationStagesQuery.isPending
                                            || educationStagesQuery.isError
                                        }
                                        onChange={(event) =>
                                            setNewEducationStageId(
                                                event.target.value,
                                            )
                                        }
                                    >
                                        <option value="">
                                            بدون مرحلة محددة
                                        </option>

                                        {activeEducationStages.map(
                                            (stage) => (
                                                <option
                                                    key={stage.id}
                                                    value={stage.id}
                                                >
                                                    {stage.name}
                                                </option>
                                            ),
                                        )}
                                    </select>

                                    <span className="admin-curricula__field-hint">
                                        {educationStagesQuery.isPending
                                            ? 'جار تحميل المراحل التعليمية…'
                                            : educationStagesQuery.isError
                                                ? 'تعذر تحميل المراحل؛ يمكن إنشاء المنهج دون تصنيف مرحلي.'
                                                : 'اختياري، ويصبح ثابتًا بعد إنشاء المنهج.'}
                                    </span>
                                </label>

                                <Button
                                    type="submit"
                                    disabled={
                                        createCurriculum.isPending
                                        || educationStagesQuery.isPending
                                    }
                                >
                                    إنشاء المنهج
                                </Button>
                            </form>
                        ) : null}

                        {educationStagesQuery.isError ? (
                            <Feedback tone="warning">
                                تعذر تحميل المراحل التعليمية.
                                يمكنك إنشاء المنهج بدون مرحلة
                                محددة أو المحاولة لاحقًا.
                            </Feedback>
                        ) : null}

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
                            <div className="admin-curricula-browser">
                                <div className="admin-curricula-browser__toolbar">
                                    <label className="admin-curricula-browser__search">
                                        <span className="sr-only">
                                            بحث في المناهج
                                        </span>
                                        <input
                                            type="search"
                                            aria-label="بحث في المناهج"
                                            placeholder="ابحث باسم المنهج…"
                                            value={curriculumSearch}
                                            onChange={(event) => {
                                                setCurriculumSearch(
                                                    event.target.value,
                                                );
                                                setCurriculumPage(1);
                                            }}
                                        />
                                    </label>

                                    <span className="admin-curricula-browser__count">
                                        {filteredCurricula.length}
                                        {' '}
                                        منهج
                                    </span>
                                </div>

                                {filteredCurricula.length === 0 ? (
                                    <Feedback>
                                        لا توجد مناهج مطابقة للبحث.
                                    </Feedback>
                                ) : (
                                    <>
                                        <div
                                            className="admin-entity-list admin-curricula-browser__list"
                                            aria-label="قائمة المناهج"
                                        >
                                            {visibleCurricula.map(
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

                                                                <span className="admin-curricula__immutable-note">
                                                                    المرحلة التعليمية ثابتة بعد إنشاء المنهج:
                                                                    {' '}
                                                                    <strong>
                                                                        {
                                                                            curriculumStageLabel(
                                                                                curriculum,
                                                                            )
                                                                        }
                                                                    </strong>
                                                                </span>

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
                                                                <div className="admin-curriculum-summary">
                                                                    <strong>
                                                                        {curriculum.name}
                                                                    </strong>

                                                                    <span className="admin-curriculum-stage">
                                                                        {
                                                                            curriculumStageLabel(
                                                                                curriculum,
                                                                            )
                                                                        }
                                                                    </span>
                                                                </div>

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

                                        <div
                                            className="admin-curricula-browser__pagination"
                                            aria-label="تنقل صفحات المناهج"
                                        >
                                            <Button
                                                size="sm"
                                                type="button"
                                                variant="secondary"
                                                disabled={
                                                    safeCurriculumPage === 1
                                                }
                                                onClick={() =>
                                                    setCurriculumPage(
                                                        Math.max(
                                                            1,
                                                            safeCurriculumPage - 1,
                                                        ),
                                                    )
                                                }
                                            >
                                                السابق
                                            </Button>

                                            <span>
                                                صفحة
                                                {' '}
                                                {safeCurriculumPage}
                                                {' '}
                                                من
                                                {' '}
                                                {curriculumPageCount}
                                            </span>

                                            <Button
                                                size="sm"
                                                type="button"
                                                variant="secondary"
                                                disabled={
                                                    safeCurriculumPage
                                                    === curriculumPageCount
                                                }
                                                onClick={() =>
                                                    setCurriculumPage(
                                                        Math.min(
                                                            curriculumPageCount,
                                                            safeCurriculumPage + 1,
                                                        ),
                                                    )
                                                }
                                            >
                                                التالي
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                </Surface>
            </div>
        </section>
    );
}

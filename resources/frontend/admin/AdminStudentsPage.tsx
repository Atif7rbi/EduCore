import '../../css/admin-operations.css';

import {
    type FormEvent,
    useEffect,
    useMemo,
    useState,
} from 'react';
import {
    useSearchParams,
} from 'react-router-dom';
import {
    useQuery,
} from '@tanstack/react-query';

import {
    Button,
    Feedback,
    Surface,
} from '../ui';

import {
    adminStudentDirectoryKey,
    adminStudentKey,
    fetchAdminStudentDirectory,
    fetchAdminStudent,
    fetchStudentEnrollment,
    fetchStudentEnrollments,
    studentEnrollmentKey,
    studentEnrollmentsKey,
} from './operations/studentApi';

import {
    StudentEnrollmentDeactivationPanel,
} from './operations/StudentEnrollmentDeactivationPanel';

import type {
    AdminStudent,
    StudentEnrollmentRead,
    StudentEnrollmentStatus,
} from './operations/studentTypes';

function statusLabel(
    status: StudentEnrollmentStatus,
): string {
    switch (status) {
        case 'pending':
            return 'بانتظار القرار';
        case 'active':
            return 'نشط';
        case 'inactive':
            return 'غير نشط';
    }
}

function userStatusLabel(
    status: AdminStudent['status'],
): string {
    return status === 'active'
        ? 'نشط'
        : 'معطّل';
}

function positivePage(value: string | null): number {
    const parsed = Number(value);

    return Number.isSafeInteger(parsed) && parsed > 0
        ? parsed
        : 1;
}

function directoryStatus(
    value: string | null,
): AdminStudent['status'] | null {
    return value === 'active' || value === 'disabled'
        ? value
        : null;
}

const DIRECTORY_SEARCH_MAX_LENGTH = 120;

function directorySearch(
    value: string | null,
): string {
    return Array.from(
        (value ?? '').trim(),
    ).slice(
        0,
        DIRECTORY_SEARCH_MAX_LENGTH,
    ).join('');
}

function identifier(
    value: string | null,
): string {
    return value ?? 'غير موجود';
}

export function AdminStudentsPage() {
    const [searchParams, setSearchParams] =
        useSearchParams();
    const [
        selectedStudentId,
        setSelectedStudentId,
    ] = useState<string | null>(
        null
    );

    const [
        selectedEnrollmentId,
        setSelectedEnrollmentId,
    ] = useState<string | null>(
        null
    );

    const committedSearch =
        directorySearch(
            searchParams.get('search'),
        );
    const page = positivePage(
        searchParams.get('page'),
    );
    const status = directoryStatus(
        searchParams.get('status'),
    );
    const [search, setSearch] =
        useState(committedSearch);

    const directoryParameters = useMemo(
        () => ({
            page,
            perPage: 20,
            search: committedSearch,
            status,
        }),
        [committedSearch, page, status],
    );

    const studentsQuery =
        useQuery({
            queryKey:
                adminStudentDirectoryKey(
                    directoryParameters,
                ),
            queryFn: ({ signal }) =>
                fetchAdminStudentDirectory(
                    directoryParameters,
                    signal,
                ),
        });

    const studentQuery =
        useQuery({
            queryKey:
                adminStudentKey(
                    selectedStudentId
                        ?? 'none'
                ),
            queryFn: ({ signal }) =>
                fetchAdminStudent(
                    selectedStudentId!,
                    signal,
                ),
            enabled:
                selectedStudentId
                !== null,
        });

    const learnerProfileId =
        studentQuery.data
            ?.learner_profile_id
        ?? null;

    const enrollmentsQuery =
        useQuery({
            queryKey:
                studentEnrollmentsKey(
                    selectedStudentId
                        ?? 'none'
                ),
            queryFn: ({ signal }) =>
                fetchStudentEnrollments(
                    selectedStudentId!,
                    signal,
                ),
            enabled:
                selectedStudentId
                    !== null
                && learnerProfileId
                    !== null,
        });

    const enrollmentQuery =
        useQuery({
            queryKey:
                studentEnrollmentKey(
                    selectedEnrollmentId
                        ?? 'none'
                ),
            queryFn: ({ signal }) =>
                fetchStudentEnrollment(
                    selectedEnrollmentId!,
                    signal,
                ),
            enabled:
                selectedEnrollmentId
                    !== null,
        });

    useEffect(() => {
        setSearch(committedSearch);
    }, [committedSearch]);

    useEffect(() => {
        setSelectedEnrollmentId(
            null
        );
    }, [selectedStudentId]);

    useEffect(() => {
        const enrollments =
            enrollmentsQuery.data;

        if (
            !enrollments
            || enrollments.length
                === 0
        ) {
            return;
        }

        const exists =
            selectedEnrollmentId
                !== null
            && enrollments.some(
                (enrollment) =>
                    enrollment.id
                    ===
                    selectedEnrollmentId
            );

        if (!exists) {
            setSelectedEnrollmentId(
                enrollments[0].id
            );
        }
    }, [
        enrollmentsQuery.data,
        selectedEnrollmentId,
    ]);

    const students =
        studentsQuery.data?.items ?? [];

    function submitDirectorySearch(
        event: FormEvent,
    ) {
        event.preventDefault();
        const next = new URLSearchParams(
            searchParams,
        );
        const normalized =
            directorySearch(search);

        if (normalized) {
            next.set('search', normalized);
        } else {
            next.delete('search');
        }

        next.set('page', '1');
        setSearchParams(next);
    }

    function changeStatus(
        nextStatus: AdminStudent['status'] | null,
    ) {
        const next = new URLSearchParams(
            searchParams,
        );

        if (nextStatus) {
            next.set('status', nextStatus);
        } else {
            next.delete('status');
        }

        next.set('page', '1');
        setSearchParams(next);
    }

    function changePage(nextPage: number) {
        const next = new URLSearchParams(
            searchParams,
        );
        next.set('page', String(nextPage));
        setSearchParams(next);
    }

    return (
        <section
            className="foundation-page admin-operations"
            aria-labelledby="admin-students-title"
        >
            <div className="foundation-page__heading">
                <h1
                    id="admin-students-title"
                    className="foundation-page__title"
                >
                    إدارة الطلاب
                </h1>

                <p className="foundation-page__description">
                    استعرض هوية الطالب
                    التعليمية وعلاقات التسجيل
                    وسجلها التاريخي دون تجاوز
                    صلاحيات دورة حياة التسجيل.
                </p>
            </div>

            <div className="admin-operations__workspace">
                <Surface
                    className="admin-operations__list-panel"
                    elevated
                >
                    <div className="admin-operations__section-heading">
                        <div>
                            <h2>
                                الطلاب
                            </h2>

                            <p>
                                {
                                    studentsQuery
                                        .data
                                        ?.pagination
                                        .total ?? 0
                                } حساب
                            </p>
                        </div>
                    </div>

                    <form
                        className="admin-operations__directory-controls"
                        onSubmit={submitDirectorySearch}
                    >
                        <label className="admin-operations__search">
                            <span>
                                بحث في الطلاب
                            </span>

                            <input
                                type="search"
                                maxLength={120}
                                value={search}
                                onChange={(event) => {
                                    setSearch(
                                        event.target.value
                                    );
                                }}
                            />
                        </label>

                        <label className="admin-operations__filter">
                            <span>حالة الحساب</span>
                            <select
                                value={status ?? ''}
                                onChange={(event) => {
                                    changeStatus(
                                        directoryStatus(
                                            event.target.value,
                                        ),
                                    );
                                }}
                            >
                                <option value="">كل الحالات</option>
                                <option value="active">نشط</option>
                                <option value="disabled">معطّل</option>
                            </select>
                        </label>

                        <Button type="submit" variant="secondary">
                            بحث
                        </Button>
                    </form>

                    <div
                        className="admin-operations__entity-list"
                        aria-label="قائمة الطلاب"
                    >
                        {studentsQuery.isPending
                            ? (
                                <Feedback>
                                    جار تحميل الطلاب…
                                </Feedback>
                            )
                            : studentsQuery.isError
                                ? (
                                    <Feedback tone="danger">
                                        تعذر تحميل الطلاب.
                                        <Button
                                            variant="secondary"
                                            onClick={() => {
                                                void studentsQuery.refetch();
                                            }}
                                        >
                                            إعادة المحاولة
                                        </Button>
                                    </Feedback>
                                )
                            : students.length === 0
                            ? (
                                <Feedback>
                                    لا توجد نتائج
                                    مطابقة.
                                </Feedback>
                            )
                            : students
                                .map(
                                    (
                                        student
                                    ) => (
                                        <StudentListItem
                                            key={
                                                student
                                                    .user_id
                                            }
                                            student={
                                                student
                                            }
                                            selected={
                                                student
                                                    .user_id
                                                ===
                                                selectedStudentId
                                            }
                                            onSelect={() => {
                                                setSelectedStudentId(
                                                    student
                                                        .user_id
                                                );
                                            }}
                                        />
                                    )
                                )}
                    </div>

                    {studentsQuery.data ? (
                        <nav
                            className="admin-operations__pagination"
                            aria-label="صفحات الطلاب"
                        >
                            <Button
                                variant="secondary"
                                disabled={page <= 1 || studentsQuery.isFetching}
                                onClick={() => changePage(page - 1)}
                            >
                                السابق
                            </Button>
                            <span>
                                صفحة {studentsQuery.data.pagination.current_page}
                                {' '}من{' '}
                                {studentsQuery.data.pagination.last_page}
                            </span>
                            <Button
                                variant="secondary"
                                disabled={
                                    page >= studentsQuery.data.pagination.last_page
                                    || studentsQuery.isFetching
                                }
                                onClick={() => changePage(page + 1)}
                            >
                                التالي
                            </Button>
                        </nav>
                    ) : null}
                </Surface>

                <Surface
                    className="admin-operations__detail-panel"
                    elevated
                >
                    <div className="admin-operations__section-heading">
                        <div>
                            <h2>
                                ملف الطالب
                            </h2>

                            <p>
                                User identity و
                                LearnerProfile
                                يظهران كهويتين
                                منفصلتين.
                            </p>
                        </div>
                    </div>

                    {!selectedStudentId ? (
                        <Feedback>
                            لا يوجد طالب محدد.
                        </Feedback>
                    ) : studentQuery
                        .isPending ? (
                            <Feedback>
                                جار تحميل ملف
                                الطالب…
                            </Feedback>
                        ) : studentQuery
                            .isError ? (
                                <Feedback tone="danger">
                                    تعذر تحميل ملف
                                    الطالب.
                                </Feedback>
                            ) : studentQuery
                                .data ? (
                                    <StudentDetail
                                        student={
                                            studentQuery
                                                .data
                                        }
                                        enrollmentsQuery={
                                            enrollmentsQuery
                                        }
                                        selectedEnrollmentId={
                                            selectedEnrollmentId
                                        }
                                        onSelectEnrollment={
                                            setSelectedEnrollmentId
                                        }
                                        enrollment={
                                            enrollmentQuery
                                                .data
                                            ?? null
                                        }
                                        enrollmentPending={
                                            enrollmentQuery
                                                .isPending
                                        }
                                        enrollmentError={
                                            enrollmentQuery
                                                .isError
                                        }
                                    />
                                ) : null}
                </Surface>
            </div>
        </section>
    );
}

function StudentListItem({
    student,
    selected,
    onSelect,
}: {
    student: AdminStudent;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            className={
                selected
                    ? 'admin-operations__entity admin-operations__entity--selected'
                    : 'admin-operations__entity'
            }
            aria-label={
                `عرض الطالب ${student.name}`
            }
            aria-pressed={selected}
            onClick={onSelect}
        >
            <span className="admin-operations__entity-main">
                <strong>
                    {student.name}
                </strong>

                <small>
                    {student.email}
                </small>
            </span>

            <span className="admin-operations__entity-meta">
                <span>
                    {userStatusLabel(
                        student.status
                    )}
                </span>

                <span>
                    {
                        student
                            .enrollment_counts
                            .active
                    }{' '}
                    تسجيل نشط
                </span>

                {student
                    .learner_profile_id
                    === null ? (
                        <span>
                            هوية تعليمية
                            مفقودة
                        </span>
                    ) : null}
            </span>
        </button>
    );
}

function StudentDetail({
    student,
    enrollmentsQuery,
    selectedEnrollmentId,
    onSelectEnrollment,
    enrollment,
    enrollmentPending,
    enrollmentError,
}: {
    student: AdminStudent;
    enrollmentsQuery: {
        data:
            | StudentEnrollmentRead[]
            | undefined;
        isPending: boolean;
        isError: boolean;
    };
    selectedEnrollmentId:
        string | null;
    onSelectEnrollment:
        (id: string) => void;
    enrollment:
        StudentEnrollmentRead | null;
    enrollmentPending: boolean;
    enrollmentError: boolean;
}) {
    return (
        <div className="admin-operations__detail">
            <div className="admin-operations__identity">
                <div>
                    <strong>
                        {student.name}
                    </strong>

                    <span>
                        {student.email}
                    </span>
                </div>

                <span
                    className="admin-operations__status"
                    data-status={
                        student.status
                    }
                >
                    {userStatusLabel(
                        student.status
                    )}
                </span>
            </div>

            <dl className="admin-operations__facts admin-students__identity-facts">
                <div>
                    <dt>
                        User UUID
                    </dt>

                    <dd className="admin-operations__identifier">
                        {
                            student
                                .user_id
                        }
                    </dd>
                </div>

                <div>
                    <dt>
                        LearnerProfile UUID
                    </dt>

                    <dd className="admin-operations__identifier">
                        {identifier(
                            student
                                .learner_profile_id
                        )}
                    </dd>
                </div>

                <div>
                    <dt>
                        تسجيلات pending
                    </dt>

                    <dd>
                        {
                            student
                                .enrollment_counts
                                .pending
                        }
                    </dd>
                </div>

                <div>
                    <dt>
                        تسجيلات active
                    </dt>

                    <dd>
                        {
                            student
                                .enrollment_counts
                                .active
                        }
                    </dd>
                </div>

                <div>
                    <dt>
                        تسجيلات inactive
                    </dt>

                    <dd>
                        {
                            student
                                .enrollment_counts
                                .inactive
                        }
                    </dd>
                </div>

                <div>
                    <dt>
                        إجمالي التسجيلات
                    </dt>

                    <dd>
                        {
                            student
                                .enrollment_counts
                                .total
                        }
                    </dd>
                </div>
            </dl>

            {student
                .learner_profile_id
                === null ? (
                    <Feedback tone="danger">
                        هذا الحساب لا يملك
                        LearnerProfile مطابقًا.
                        تم إيقاف قراءة
                        StudentEnrollment لهذا
                        الحساب بدل محاولة
                        استنتاج الهوية التعليمية.
                    </Feedback>
                ) : (
                    <EnrollmentBrowser
                        enrollmentsQuery={
                            enrollmentsQuery
                        }
                        selectedEnrollmentId={
                            selectedEnrollmentId
                        }
                        onSelectEnrollment={
                            onSelectEnrollment
                        }
                        enrollment={
                            enrollment
                        }
                        enrollmentPending={
                            enrollmentPending
                        }
                        enrollmentError={
                            enrollmentError
                        }
                    />
                )}
        </div>
    );
}

function EnrollmentBrowser({
    enrollmentsQuery,
    selectedEnrollmentId,
    onSelectEnrollment,
    enrollment,
    enrollmentPending,
    enrollmentError,
}: {
    enrollmentsQuery: {
        data:
            | StudentEnrollmentRead[]
            | undefined;
        isPending: boolean;
        isError: boolean;
    };
    selectedEnrollmentId:
        string | null;
    onSelectEnrollment:
        (id: string) => void;
    enrollment:
        StudentEnrollmentRead | null;
    enrollmentPending: boolean;
    enrollmentError: boolean;
}) {
    if (
        enrollmentsQuery.isPending
    ) {
        return (
            <Feedback>
                جار تحميل التسجيلات…
            </Feedback>
        );
    }

    if (
        enrollmentsQuery.isError
    ) {
        return (
            <Feedback tone="danger">
                تعذر تحميل تسجيلات
                الطالب.
            </Feedback>
        );
    }

    const enrollments =
        enrollmentsQuery.data
        ?? [];

    return (
        <div className="admin-students__enrollments">
            <div className="admin-operations__section-heading">
                <div>
                    <h3>
                        التسجيلات
                    </h3>

                    <p>
                        الحالة المعروضة هنا
                        StudentEnrollment raw
                        state وليست إثباتًا
                        للوصول التعليمي الفعلي.
                    </p>
                </div>
            </div>

            <Feedback tone="info">
                الوصول الفعلي يتطلب أيضًا
                Student وTeacher و
                TeacherSubjectAssignment
                مؤهلين حاليًا. لا نستنتجه من
                enrollment.status وحده.
            </Feedback>

            {enrollments.length
            === 0 ? (
                <Feedback>
                    لا توجد تسجيلات لهذا
                    الطالب.
                </Feedback>
            ) : (
                <div className="admin-students__enrollment-list">
                    {enrollments.map(
                        (
                            item
                        ) => (
                            <button
                                key={
                                    item.id
                                }
                                type="button"
                                className={
                                    item.id
                                    ===
                                    selectedEnrollmentId
                                        ? 'admin-students__enrollment admin-students__enrollment--selected'
                                        : 'admin-students__enrollment'
                                }
                                onClick={() => {
                                    onSelectEnrollment(
                                        item.id
                                    );
                                }}
                            >
                                <span>
                                    <strong>
                                        {
                                            item
                                                .teacher_subject_assignment
                                                .subject
                                                .name
                                        }
                                    </strong>

                                    <small>
                                        المعلم:{' '}
                                        {
                                            item
                                                .teacher_subject_assignment
                                                .teacher
                                                .name
                                        }
                                    </small>
                                </span>

                                <span
                                    className="admin-operations__status"
                                    data-status={
                                        item.status
                                    }
                                >
                                    {statusLabel(
                                        item.status
                                    )}
                                </span>
                            </button>
                        )
                    )}
                </div>
            )}

            {selectedEnrollmentId ? (
                <EnrollmentDetail
                    enrollment={
                        enrollment
                    }
                    pending={
                        enrollmentPending
                    }
                    error={
                        enrollmentError
                    }
                />
            ) : null}
        </div>
    );
}

function EnrollmentDetail({
    enrollment,
    pending,
    error,
}: {
    enrollment:
        StudentEnrollmentRead | null;
    pending: boolean;
    error: boolean;
}) {
    if (pending) {
        return (
            <Feedback>
                جار تحميل سجل التسجيل…
            </Feedback>
        );
    }

    if (error) {
        return (
            <Feedback tone="danger">
                تعذر تحميل سجل التسجيل.
            </Feedback>
        );
    }

    if (!enrollment) {
        return null;
    }

    const assignment =
        enrollment
            .teacher_subject_assignment;

    return (
        <div className="admin-students__enrollment-detail">
            <div className="admin-students__relationship">
                <div>
                    <span>
                        المادة
                    </span>

                    <strong>
                        {
                            assignment
                                .subject
                                .name
                        }
                    </strong>

                    <small>
                        {
                            assignment
                                .subject
                                .code
                        }
                    </small>
                </div>

                <div>
                    <span>
                        المعلم
                    </span>

                    <strong>
                        {
                            assignment
                                .teacher
                                .name
                        }
                    </strong>

                    <small>
                        {
                            assignment
                                .teacher
                                .email
                        }
                    </small>
                </div>

                <div>
                    <span>
                        حالة الإسناد
                    </span>

                    <strong>
                        {
                            assignment
                                .status
                            === 'active'
                                ? 'نشط'
                                : 'غير نشط'
                        }
                    </strong>
                </div>
            </div>

            <StudentEnrollmentDeactivationPanel
                key={enrollment.id}
                enrollment={enrollment}
            />

            <div className="admin-students__history">
                <h4>
                    السجل التاريخي
                </h4>

                <p className="admin-students__history-note">
                    actor_user_id هو
                    provenance الحدث الثابت.
                    أما actor_current فهو
                    حالة حساب المنفذ الحالية
                    وليس snapshot وقت الحدث.
                </p>

                {(enrollment.transitions
                    ?? [])
                    .length === 0 ? (
                        <Feedback>
                            لا توجد انتقالات
                            تاريخية.
                        </Feedback>
                    ) : (
                        <ol>
                            {(
                                enrollment
                                    .transitions
                                ?? []
                            ).map(
                                (
                                    transition
                                ) => (
                                    <li
                                        key={
                                            transition
                                                .sequence_number
                                        }
                                    >
                                        <div>
                                            <strong>
                                                #
                                                {
                                                    transition
                                                        .sequence_number
                                                }{' '}
                                                {
                                                    transition
                                                        .outcome
                                                }
                                            </strong>

                                            <span>
                                                {
                                                    transition
                                                        .from_status
                                                    ?? '∅'
                                                }{' '}
                                                →{' '}
                                                {
                                                    transition
                                                        .to_status
                                                }
                                            </span>
                                        </div>

                                        <dl>
                                            <div>
                                                <dt>
                                                    actor_user_id
                                                </dt>

                                                <dd className="admin-operations__identifier">
                                                    {
                                                        transition
                                                            .actor_user_id
                                                    }
                                                </dd>
                                            </div>

                                            <div>
                                                <dt>
                                                    actor_current
                                                </dt>

                                                <dd>
                                                    {
                                                        transition
                                                            .actor_current
                                                            .name
                                                    }{' '}
                                                    —{' '}
                                                    {
                                                        transition
                                                            .actor_current
                                                            .status
                                                    }
                                                </dd>
                                            </div>

                                            <div>
                                                <dt>
                                                    السبب
                                                </dt>

                                                <dd>
                                                    {
                                                        transition
                                                            .reason
                                                    }
                                                </dd>
                                            </div>
                                        </dl>
                                    </li>
                                )
                            )}
                        </ol>
                    )}
            </div>
        </div>
    );
}

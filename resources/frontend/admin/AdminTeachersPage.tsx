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
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';

import {
    EduCoreApiError,
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
    TextField,
} from '../ui';

import {
    adminTeacherDirectoryKey,
    adminTeacherKey,
    adminTeachersKey,
    fetchAdminTeacherDirectory,
    fetchAdminTeacher,
    provisionTeacher,
} from './operations/api';

import {
    TeacherAssignmentsPanel,
} from './operations/TeacherAssignmentsPanel';

import type {
    AdminTeacher,
    ProvisionTeacherResult,
} from './operations/types';

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId
        : null;
}

function fieldError(
    error: unknown,
    field: string,
): string | undefined {
    if (!(error instanceof EduCoreApiError)) {
        return undefined;
    }

    return error.details?.[field]?.[0];
}

function teacherStatusLabel(
    status: AdminTeacher['status'],
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
): AdminTeacher['status'] | null {
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

function provisioningLabel(
    state: AdminTeacher[
        'provisioning'
    ]['state'],
): string {
    switch (state) {
        case 'pending_setup':
            return 'بانتظار إعداد الحساب';
        case 'completed':
            return 'اكتمل إعداد الحساب';
        default:
            return 'حساب سابق غير متتبع';
    }
}

function provisionErrorMessage(
    error: unknown,
): string {
    if (
        error instanceof EduCoreApiError
        && error.code
            === 'teacher_identity_conflict'
    ) {
        return 'البريد الإلكتروني مرتبط بحساب موجود مسبقًا.';
    }

    return 'تعذر إنشاء حساب المعلم.';
}

export function AdminTeachersPage() {
    const queryClient = useQueryClient();
    const [searchParams, setSearchParams] =
        useSearchParams();

    const [selectedTeacherId, setSelectedTeacherId] =
        useState<string | null>(null);

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

    const [name, setName] =
        useState('');

    const [email, setEmail] =
        useState('');

    const [
        provisionResult,
        setProvisionResult,
    ] = useState<
        ProvisionTeacherResult | null
    >(null);

    const directoryParameters = useMemo(
        () => ({
            page,
            perPage: 20,
            search: committedSearch,
            status,
        }),
        [committedSearch, page, status],
    );

    const teachersQuery = useQuery({
        queryKey: adminTeacherDirectoryKey(
            directoryParameters,
        ),
        queryFn: ({ signal }) =>
            fetchAdminTeacherDirectory(
                directoryParameters,
                signal,
            ),
    });

    const teacherQuery = useQuery({
        queryKey: adminTeacherKey(
            selectedTeacherId ?? 'none'
        ),
        queryFn: ({ signal }) =>
            fetchAdminTeacher(
                selectedTeacherId!,
                signal,
            ),
        enabled:
            selectedTeacherId !== null,
    });

    useEffect(() => {
        setSearch(committedSearch);
    }, [committedSearch]);

    const teachers =
        teachersQuery.data?.items ?? [];

    const provisionMutation =
        useMutation({
            mutationFn: provisionTeacher,

            onSuccess: async (result) => {
                setName('');
                setEmail('');
                setProvisionResult(result);

                await queryClient
                    .invalidateQueries({
                        queryKey:
                            adminTeachersKey(),
                    });

                setSelectedTeacherId(
                    result.teacher.id
                );
            },
        });

    function submitProvision(
        event: FormEvent,
    ) {
        event.preventDefault();

        const normalizedName =
            name.trim();

        const normalizedEmail =
            email.trim();

        if (
            !normalizedName
            || !normalizedEmail
        ) {
            return;
        }

        setProvisionResult(null);

        provisionMutation.mutate({
            name: normalizedName,
            email: normalizedEmail,
        });
    }

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
        nextStatus: AdminTeacher['status'] | null,
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
            aria-labelledby="admin-teachers-title"
        >
            <div className="foundation-page__heading">
                <h1
                    id="admin-teachers-title"
                    className="foundation-page__title"
                >
                    إدارة المعلمين
                </h1>

                <p className="foundation-page__description">
                    أنشئ حسابات المعلمين وتابع حالة
                    إعداد الحساب والمواد المسندة لكل
                    معلم.
                </p>
            </div>

            <Surface
                className="admin-operations__provision"
                elevated
            >
                <div className="admin-operations__section-heading">
                    <div>
                        <h2>
                            إضافة معلم
                        </h2>

                        <p>
                            يبدأ الحساب بحالة غير نشطة
                            حتى يكتمل إعداد كلمة المرور
                            من خلال رابط الإعداد.
                        </p>
                    </div>
                </div>

                <form
                    className="admin-operations__provision-form"
                    onSubmit={submitProvision}
                >
                    <TextField
                        label="اسم المعلم"
                        value={name}
                        autoComplete="name"
                        error={fieldError(
                            provisionMutation.error,
                            'name'
                        )}
                        onChange={(event) => {
                            setName(
                                event.target.value
                            );
                        }}
                    />

                    <TextField
                        label="البريد الإلكتروني"
                        type="email"
                        value={email}
                        autoComplete="email"
                        error={fieldError(
                            provisionMutation.error,
                            'email'
                        )}
                        onChange={(event) => {
                            setEmail(
                                event.target.value
                            );
                        }}
                    />

                    <Button
                        type="submit"
                        isLoading={
                            provisionMutation
                                .isPending
                        }
                        disabled={
                            !name.trim()
                            || !email.trim()
                        }
                    >
                        إنشاء حساب المعلم
                    </Button>
                </form>

                {provisionMutation.isError ? (
                    <Feedback tone="danger">
                        <strong>
                            {provisionErrorMessage(
                                provisionMutation
                                    .error
                            )}
                        </strong>

                        {requestId(
                            provisionMutation.error
                        ) ? (
                            <p>
                                رقم الطلب:{' '}
                                {requestId(
                                    provisionMutation
                                        .error
                                )}
                            </p>
                        ) : null}
                    </Feedback>
                ) : null}

                {provisionResult ? (
                    <Feedback
                        tone={
                            provisionResult.setup
                                .delivery
                            === 'sent'
                                ? 'success'
                                : 'warning'
                        }
                    >
                        {provisionResult.setup
                            .delivery
                        === 'sent'
                            ? 'تم إنشاء حساب المعلم بحالة غير نشطة وإرسال رابط إعداد كلمة المرور.'
                            : 'تم إنشاء حساب المعلم بحالة غير نشطة، لكن تعذر إرسال رابط الإعداد. لم يتم تفعيل الحساب.'}
                    </Feedback>
                ) : null}
            </Surface>

            <div className="admin-operations__workspace">
                <Surface
                    className="admin-operations__list-panel"
                    elevated
                >
                    <div className="admin-operations__section-heading">
                        <div>
                            <h2>
                                المعلمون
                            </h2>

                            <p>
                                {
                                    teachersQuery
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
                                بحث في المعلمين
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
                        aria-label="قائمة المعلمين"
                    >
                        {teachersQuery.isPending ? (
                            <Feedback>
                                جار تحميل المعلمين…
                            </Feedback>
                        ) : teachersQuery.isError ? (
                            <Feedback tone="danger">
                                <strong>تعذر تحميل المعلمين.</strong>
                                {requestId(teachersQuery.error) ? (
                                    <p>
                                        رقم الطلب:{' '}
                                        {requestId(teachersQuery.error)}
                                    </p>
                                ) : null}
                                <Button
                                    variant="secondary"
                                    onClick={() => {
                                        void teachersQuery.refetch();
                                    }}
                                >
                                    إعادة المحاولة
                                </Button>
                            </Feedback>
                        ) : teachers.length === 0 ? (
                            <Feedback>
                                لا توجد نتائج مطابقة.
                            </Feedback>
                        ) : (
                            teachers.map(
                                (teacher) => {
                                    const selected =
                                        teacher
                                            .user_id
                                        ===
                                        selectedTeacherId;

                                    return (
                                        <button
                                            key={
                                                teacher
                                                    .user_id
                                            }
                                            type="button"
                                            className={
                                                selected
                                                    ? 'admin-operations__entity admin-operations__entity--selected'
                                                    : 'admin-operations__entity'
                                            }
                                            aria-label={
                                                `عرض المعلم ${teacher.name}`
                                            }
                                            aria-pressed={selected}
                                            onClick={() => {
                                                setSelectedTeacherId(
                                                    teacher
                                                        .user_id
                                                );
                                            }}
                                        >
                                            <span className="admin-operations__entity-main">
                                                <strong>
                                                    {
                                                        teacher
                                                            .name
                                                    }
                                                </strong>

                                                <small>
                                                    {
                                                        teacher
                                                            .email
                                                    }
                                                </small>
                                            </span>

                                            <span className="admin-operations__entity-meta">
                                                <span>
                                                    {
                                                        teacherStatusLabel(
                                                            teacher
                                                                .status
                                                        )
                                                    }
                                                </span>

                                                <span>
                                                    {
                                                        provisioningLabel(
                                                            teacher
                                                                .provisioning
                                                                .state
                                                        )
                                                    }
                                                </span>

                                                <span>
                                                    {
                                                        teacher
                                                            .assignment_counts
                                                            .active
                                                    }{' '}
                                                    مادة نشطة
                                                </span>
                                            </span>
                                        </button>
                                    );
                                },
                            )
                        )}
                    </div>

                    {teachersQuery.data ? (
                        <nav
                            className="admin-operations__pagination"
                            aria-label="صفحات المعلمين"
                        >
                            <Button
                                variant="secondary"
                                disabled={page <= 1 || teachersQuery.isFetching}
                                onClick={() => changePage(page - 1)}
                            >
                                السابق
                            </Button>
                            <span>
                                صفحة {teachersQuery.data.pagination.current_page}
                                {' '}من{' '}
                                {teachersQuery.data.pagination.last_page}
                            </span>
                            <Button
                                variant="secondary"
                                disabled={
                                    page >= teachersQuery.data.pagination.last_page
                                    || teachersQuery.isFetching
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
                                تفاصيل المعلم
                            </h2>

                            <p>
                                الهوية وحالة الإعداد
                                والعدّادات التشغيلية.
                            </p>
                        </div>
                    </div>

                    {!selectedTeacherId ? (
                        <Feedback>
                            لا يوجد معلم محدد.
                        </Feedback>
                    ) : teacherQuery.isPending ? (
                        <Feedback>
                            جار تحميل التفاصيل…
                        </Feedback>
                    ) : teacherQuery.isError ? (
                        <Feedback tone="danger">
                            تعذر تحميل تفاصيل المعلم.
                        </Feedback>
                    ) : teacherQuery.data ? (
                        <TeacherSummary
                            teacher={
                                teacherQuery.data
                            }
                        />
                    ) : null}
                </Surface>
            </div>
        </section>
    );
}

function TeacherSummary({
    teacher,
}: {
    teacher: AdminTeacher;
}) {
    return (
        <div className="admin-operations__detail">
            <div className="admin-operations__identity">
                <div>
                    <strong>
                        {teacher.name}
                    </strong>

                    <span>
                        {teacher.email}
                    </span>
                </div>

                <span
                    className="admin-operations__status"
                    data-status={
                        teacher.status
                    }
                >
                    {teacherStatusLabel(
                        teacher.status
                    )}
                </span>
            </div>

            <dl className="admin-operations__facts">
                <div>
                    <dt>
                        حالة إعداد الحساب
                    </dt>
                    <dd>
                        {provisioningLabel(
                            teacher.provisioning
                                .state
                        )}
                    </dd>
                </div>

                <div>
                    <dt>
                        المواد النشطة
                    </dt>
                    <dd>
                        {
                            teacher
                                .assignment_counts
                                .active
                        }
                    </dd>
                </div>

                <div>
                    <dt>
                        المواد غير النشطة
                    </dt>
                    <dd>
                        {
                            teacher
                                .assignment_counts
                                .inactive
                        }
                    </dd>
                </div>

                <div>
                    <dt>
                        إجمالي سجلات الإسناد
                    </dt>
                    <dd>
                        {
                            teacher
                                .assignment_counts
                                .total
                        }
                    </dd>
                </div>
            </dl>

            <Feedback>
                عدّادات المواد أعلاه تمثل حالات
                TeacherSubjectAssignment الخام، وليست
                مقياسًا لوصول الطلاب الفعلي.
            </Feedback>

            <TeacherAssignmentsPanel
                key={teacher.user_id}
                teacher={teacher}
            />
        </div>
    );
}

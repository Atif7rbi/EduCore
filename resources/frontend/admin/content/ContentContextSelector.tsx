import {
    useEffect,
} from 'react';
import {
    useQuery,
} from '@tanstack/react-query';

import {
    Button,
    Feedback,
} from '../../ui';

import {
    adminCurriculaKey,
    adminSubjectsKey,
    adminVersionsKey,
    fetchCurricula,
    fetchSubjects,
    fetchVersions,
} from './api';

import type {
    Curriculum,
    CurriculumVersion,
} from './types';

interface ContentContextSelectorProps {
    subjectId: string | null;
    curriculumId: string | null;
    curriculumVersionId: string | null;
    onSubjectChange: (
        value: string | null,
        mode?: ContextNavigationMode,
    ) => void;
    onCurriculumChange: (
        value: string | null,
        mode?: ContextNavigationMode,
    ) => void;
    onCurriculumVersionChange: (
        value: string | null,
        mode?: ContextNavigationMode,
    ) => void;
    onCurriculumResolved: (
        curriculum: Curriculum | null,
    ) => void;
    onVersionResolved: (
        version: CurriculumVersion | null,
    ) => void;
    onContextErrorChange: (
        message: string | null,
    ) => void;
    onContextStateChange: (
        state: ContentContextState,
    ) => void;
}

export type ContextNavigationMode =
    | 'push'
    | 'replace';

export type ContentContextState =
    | 'subjects-loading'
    | 'subjects-error'
    | 'subjects-empty'
    | 'subject-invalid'
    | 'subject-missing'
    | 'curricula-loading'
    | 'curricula-fetching'
    | 'curricula-error'
    | 'curricula-empty'
    | 'curriculum-invalid'
    | 'curriculum-missing'
    | 'versions-loading'
    | 'versions-fetching'
    | 'versions-error'
    | 'versions-empty'
    | 'version-invalid'
    | 'version-missing'
    | 'ready';

function statusLabel(
    status: CurriculumVersion['status'],
) {
    switch (status) {
        case 'draft':
            return 'مسودة';
        case 'published':
            return 'منشور';
        case 'retired':
            return 'موقوف';
    }
}

export function ContentContextSelector({
    subjectId,
    curriculumId,
    curriculumVersionId,
    onSubjectChange,
    onCurriculumChange,
    onCurriculumVersionChange,
    onCurriculumResolved,
    onVersionResolved,
    onContextErrorChange,
    onContextStateChange,
}: ContentContextSelectorProps) {
    const subjectsQuery = useQuery({
        queryKey: adminSubjectsKey(),
        queryFn: fetchSubjects,
    });

    const selectedSubject =
        subjectsQuery.data?.find(
            (subject) =>
                subject.id === subjectId,
        ) ?? null;

    const subjectSelectionInvalid =
        subjectId !== null
        && subjectsQuery.isSuccess
        && selectedSubject === null;

    const curriculaQueryEnabled =
        subjectId !== null
        && selectedSubject !== null;

    const curriculaQuery = useQuery({
        queryKey:
            adminCurriculaKey(
                subjectId ?? '',
            ),
        queryFn: () =>
            fetchCurricula(subjectId!),
        enabled: curriculaQueryEnabled,
    });

    const selectedCurriculum =
        curriculaQuery.data?.find(
            (curriculum) =>
                curriculum.id === curriculumId,
        ) ?? null;

    const curriculumSelectionInvalid =
        curriculumId !== null
        && curriculaQuery.isSuccess
        && selectedCurriculum === null;

    const versionsQueryEnabled =
        curriculumId !== null
        && selectedCurriculum !== null;

    const versionsQuery = useQuery({
        queryKey:
            adminVersionsKey(
                curriculumId ?? '',
            ),
        queryFn: () =>
            fetchVersions(curriculumId!),
        enabled: versionsQueryEnabled,
    });

    const selectedVersion =
        versionsQuery.data?.find(
            (version) =>
                version.id
                === curriculumVersionId,
        ) ?? null;

    const versionSelectionInvalid =
        curriculumVersionId !== null
        && versionsQuery.isSuccess
        && selectedVersion === null;

    useEffect(() => {
        if (
            subjectId === null
            && subjectsQuery.data
            && subjectsQuery.data.length > 0
        ) {
            onSubjectChange(
                subjectsQuery.data[0].id,
                'replace',
            );
        }
    }, [
        onSubjectChange,
        subjectId,
        subjectsQuery.data,
    ]);

    useEffect(() => {
        if (
            curriculumId === null
            && curriculaQuery.data
            && curriculaQuery.data.length > 0
        ) {
            onCurriculumChange(
                curriculaQuery.data[0].id,
                'replace',
            );
        }

    }, [
        curriculumId,
        curriculaQuery.data,
        onCurriculumChange,
    ]);

    useEffect(() => {
        if (
            curriculumVersionId === null
            && versionsQuery.data
            && versionsQuery.data.length > 0
        ) {
            onCurriculumVersionChange(
                versionsQuery.data[0].id,
                'replace',
            );
        }

    }, [
        curriculumVersionId,
        onCurriculumVersionChange,
        versionsQuery.data,
    ]);

    useEffect(() => {
        onCurriculumResolved(
            selectedCurriculum,
        );
    }, [
        onCurriculumResolved,
        selectedCurriculum,
    ]);

    useEffect(() => {
        onVersionResolved(
            selectedVersion,
        );
    }, [
        onVersionResolved,
        selectedVersion,
    ]);

    const contextError =
        subjectsQuery.isError
            ? 'تعذر تحميل المواد.'
            : subjectSelectionInvalid
                ? 'المادة المحددة في الرابط غير متاحة.'
                : curriculaQueryEnabled
                    && curriculaQuery.isError
                    && !curriculaQuery.isFetching
                    ? 'تعذر تحميل المناهج.'
                    : curriculumSelectionInvalid
                        ? 'المنهج المحدد لا يتبع المادة الحالية أو لا يمكن الوصول إليه.'
                        : versionsQueryEnabled
                            && versionsQuery.isError
                            && !versionsQuery.isFetching
                            ? 'تعذر تحميل إصدارات المنهج.'
                            : versionSelectionInvalid
                                ? 'إصدار المنهج المحدد غير متاح ضمن المنهج الحالي.'
                                : null;

    const contextState: ContentContextState =
        subjectsQuery.isPending
            ? 'subjects-loading'
            : subjectsQuery.isError
                ? 'subjects-error'
                : subjectsQuery.data.length === 0
                    ? 'subjects-empty'
                    : subjectSelectionInvalid
                        ? 'subject-invalid'
                        : subjectId === null
                            ? 'subject-missing'
                            : curriculaQueryEnabled
                                && curriculaQuery.isPending
                                ? 'curricula-loading'
                                : curriculaQueryEnabled
                                    && curriculaQuery.isFetching
                                    ? 'curricula-fetching'
                                : curriculaQueryEnabled
                                    && curriculaQuery.isError
                                    ? 'curricula-error'
                                    : (curriculaQuery.data?.length ?? 0) === 0
                                        ? 'curricula-empty'
                                        : curriculumSelectionInvalid
                                            ? 'curriculum-invalid'
                                            : curriculumId === null
                                                ? 'curriculum-missing'
                                                : versionsQueryEnabled
                                                    && versionsQuery.isPending
                                                    ? 'versions-loading'
                                                    : versionsQueryEnabled
                                                        && versionsQuery.isFetching
                                                        ? 'versions-fetching'
                                                    : versionsQueryEnabled
                                                        && versionsQuery.isError
                                                        ? 'versions-error'
                                                        : (versionsQuery.data?.length ?? 0) === 0
                                                            ? 'versions-empty'
                                                            : versionSelectionInvalid
                                                                ? 'version-invalid'
                                                                : curriculumVersionId === null
                                                                    ? 'version-missing'
                                                                    : 'ready';

    useEffect(() => {
        onContextErrorChange(
            contextError,
        );

        return () => {
            onContextErrorChange(null);
        };
    }, [
        contextError,
        onContextErrorChange,
    ]);

    useEffect(() => {
        onContextStateChange(contextState);
    }, [
        contextState,
        onContextStateChange,
    ]);

    if (subjectsQuery.isPending) {
        return (
            <div className="admin-context-bar admin-context-bar--loading">
                جار تحميل سياق المحتوى…
            </div>
        );
    }

    if (subjectsQuery.isError) {
        return (
            <Feedback tone="danger">
                <div className="foundation-stack">
                    <span>تعذر تحميل المواد.</span>
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                            void subjectsQuery.refetch();
                        }}
                    >
                        إعادة المحاولة
                    </Button>
                </div>
            </Feedback>
        );
    }

    if (subjectsQuery.data.length === 0) {
        return (
            <Feedback>
                لا توجد مواد متاحة.
            </Feedback>
        );
    }

    return (
        <div className="admin-context-bar">
            <div className="admin-context-bar__trail" aria-label="سياق المحتوى الحالي">
                <label className="admin-context-chip">
                    <span className="admin-context-chip__label">
                        المادة
                    </span>
                    <select
                        aria-label="المادة"
                        value={subjectId ?? ''}
                        onChange={(event) => {
                            const value =
                                event.target.value || null;

                            onSubjectChange(value);
                            onCurriculumResolved(null);
                            onVersionResolved(null);
                        }}
                    >
                        {subjectsQuery.data.map(
                            (subject) => (
                                <option
                                    key={subject.id}
                                    value={subject.id}
                                >
                                    {subject.name}
                                </option>
                            ),
                        )}
                    </select>
                </label>

                <span className="admin-context-bar__separator" aria-hidden="true">
                    ‹
                </span>

                <label className="admin-context-chip">
                    <span className="admin-context-chip__label">
                        المنهج
                    </span>
                    <select
                        aria-label="المنهج"
                        value={curriculumId ?? ''}
                        disabled={
                            !subjectId
                            || curriculaQuery.isPending
                            || curriculaQuery.isError
                            || curriculaQuery.data?.length === 0
                        }
                        onChange={(event) => {
                            const value =
                                event.target.value || null;

                            onCurriculumChange(value);
                            onCurriculumResolved(null);
                            onVersionResolved(null);
                        }}
                    >
                        {curriculaQuery.data?.map(
                            (curriculum) => (
                                <option
                                    key={curriculum.id}
                                    value={curriculum.id}
                                >
                                    {curriculum.name}
                                </option>
                            ),
                        )}
                    </select>
                </label>
            </div>

            <div className="admin-context-bar__meta">
                {selectedVersion ? (
                    <span
                        className={`admin-context-status admin-context-status--${selectedVersion.status}`}
                    >
                        {statusLabel(selectedVersion.status)}
                    </span>
                ) : null}

                {versionsQuery.data
                    && versionsQuery.data.length > 1 ? (
                    <label className="admin-context-version">
                        <span>إصدار العمل</span>
                        <select
                            aria-label="إصدار المنهج"
                            value={curriculumVersionId ?? ''}
                            onChange={(event) => {
                                onCurriculumVersionChange(
                                    event.target.value || null,
                                );
                            }}
                        >
                            {versionsQuery.data.map(
                                (version) => (
                                    <option
                                        key={version.id}
                                        value={version.id}
                                    >
                                        الإصدار {version.version_number} — {statusLabel(version.status)}
                                    </option>
                                ),
                            )}
                        </select>
                    </label>
                ) : null}

                <span className="admin-context-bar__summary">
                    {selectedSubject?.name ?? '—'}
                    {' · '}
                    {selectedCurriculum?.name ?? '—'}
                </span>
            </div>

            {subjectSelectionInvalid ? (
                <Feedback tone="danger">
                    <div className="foundation-stack">
                        <span>
                            المادة المحددة في الرابط غير متاحة.
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                onSubjectChange(
                                    null,
                                    'replace',
                                );
                            }}
                        >
                            مسح السياق غير الصالح
                        </Button>
                    </div>
                </Feedback>
            ) : curriculaQueryEnabled
                && curriculaQuery.isPending ? (
                <Feedback>
                    جار تحميل مناهج المادة…
                </Feedback>
            ) : curriculaQueryEnabled
                && curriculaQuery.isFetching ? (
                <Feedback>
                    جار تحديث مناهج المادة…
                </Feedback>
            ) : curriculaQueryEnabled
                && curriculaQuery.isError ? (
                <Feedback tone="danger">
                    <div className="foundation-stack">
                        <span>تعذر تحميل المناهج.</span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                void curriculaQuery.refetch();
                            }}
                        >
                            إعادة محاولة تحميل المناهج
                        </Button>
                    </div>
                </Feedback>
            ) : curriculaQuery.data?.length === 0 ? (
                <Feedback>
                    لا توجد مناهج متاحة لهذه المادة.
                </Feedback>
            ) : curriculumSelectionInvalid ? (
                <Feedback tone="danger">
                    <div className="foundation-stack">
                        <span>
                            المنهج المحدد لا يتبع المادة الحالية أو لا يمكن الوصول إليه.
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                onCurriculumChange(
                                    null,
                                    'replace',
                                );
                            }}
                        >
                            مسح المنهج غير الصالح
                        </Button>
                    </div>
                </Feedback>
            ) : null}

            {versionsQueryEnabled
                && versionsQuery.isPending ? (
                <Feedback>
                    جار تحميل إصدارات المنهج…
                </Feedback>
            ) : versionsQueryEnabled
                && versionsQuery.isFetching ? (
                <Feedback>
                    جار تحديث إصدارات المنهج…
                </Feedback>
            ) : versionsQueryEnabled
                && versionsQuery.isError ? (
                <Feedback tone="danger">
                    <div className="foundation-stack">
                        <span>تعذر تحميل إصدارات المنهج.</span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                void versionsQuery.refetch();
                            }}
                        >
                            إعادة محاولة تحميل الإصدارات
                        </Button>
                    </div>
                </Feedback>
            ) : versionsQueryEnabled
                && versionsQuery.data?.length === 0 ? (
                <Feedback>
                    لا توجد إصدارات متاحة لهذا المنهج.
                </Feedback>
            ) : versionSelectionInvalid ? (
                <Feedback tone="danger">
                    <div className="foundation-stack">
                        <span>
                            إصدار المنهج المحدد غير متاح ضمن المنهج الحالي.
                        </span>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                                onCurriculumVersionChange(
                                    null,
                                    'replace',
                                );
                            }}
                        >
                            مسح الإصدار غير الصالح
                        </Button>
                    </div>
                </Feedback>
            ) : null}
        </div>
    );
}

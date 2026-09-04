import {
    useEffect,
} from 'react';
import {
    useQuery,
} from '@tanstack/react-query';

import {
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
    CurriculumVersion,
} from './types';

interface ContentContextSelectorProps {
    subjectId: string | null;
    curriculumId: string | null;
    curriculumVersionId: string | null;
    onSubjectChange: (
        value: string | null,
    ) => void;
    onCurriculumChange: (
        value: string | null,
    ) => void;
    onCurriculumVersionChange: (
        value: string | null,
    ) => void;
    onVersionResolved: (
        version: CurriculumVersion | null,
    ) => void;
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
    onVersionResolved,
}: ContentContextSelectorProps) {
    const subjectsQuery = useQuery({
        queryKey: adminSubjectsKey(),
        queryFn: fetchSubjects,
    });

    const curriculaQuery = useQuery({
        queryKey:
            adminCurriculaKey(
                subjectId ?? '',
            ),
        queryFn: () =>
            fetchCurricula(subjectId!),
        enabled: subjectId !== null,
    });

    const versionsQuery = useQuery({
        queryKey:
            adminVersionsKey(
                curriculumId ?? '',
            ),
        queryFn: () =>
            fetchVersions(curriculumId!),
        enabled: curriculumId !== null,
    });

    useEffect(() => {
        if (
            subjectId === null
            && subjectsQuery.data
            && subjectsQuery.data.length > 0
        ) {
            onSubjectChange(
                subjectsQuery.data[0].id,
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
            );
        }

        if (
            curriculaQuery.data
            && curriculaQuery.data.length === 0
        ) {
            onCurriculumChange(null);
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
            );
        }

        if (
            versionsQuery.data
            && versionsQuery.data.length === 0
        ) {
            onCurriculumVersionChange(
                null,
            );
        }
    }, [
        curriculumVersionId,
        onCurriculumVersionChange,
        versionsQuery.data,
    ]);

    const selectedSubject =
        subjectsQuery.data?.find(
            (subject) =>
                subject.id === subjectId,
        ) ?? null;

    const selectedCurriculum =
        curriculaQuery.data?.find(
            (curriculum) =>
                curriculum.id === curriculumId,
        ) ?? null;

    const selectedVersion =
        versionsQuery.data?.find(
            (version) =>
                version.id
                === curriculumVersionId,
        ) ?? null;

    useEffect(() => {
        onVersionResolved(
            selectedVersion,
        );
    }, [
        onVersionResolved,
        selectedVersion,
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
                تعذر تحميل المواد.
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
                            onCurriculumChange(null);
                            onCurriculumVersionChange(null);
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
                            onCurriculumVersionChange(null);
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

            {curriculaQuery.isError ? (
                <Feedback tone="danger">
                    تعذر تحميل المناهج.
                </Feedback>
            ) : null}

            {versionsQuery.isError ? (
                <Feedback tone="danger">
                    تعذر تحميل إصدارات المنهج.
                </Feedback>
            ) : null}
        </div>
    );
}

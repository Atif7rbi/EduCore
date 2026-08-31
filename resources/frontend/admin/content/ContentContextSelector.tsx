import {
    useEffect,
} from 'react';
import {
    useQuery,
} from '@tanstack/react-query';

import {
    Feedback,
    Surface,
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
            return 'متقاعد';
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

    return (
        <Surface
            className="admin-content-context"
            elevated
        >
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">
                        سياق المحتوى
                    </h2>

                    <p className="foundation-page__description">
                        اختر المادة والمنهج وإصدار
                        المنهج الذي تريد إدارة محتواه.
                    </p>
                </div>

                {subjectsQuery.isPending ? (
                    <p>
                        جار تحميل المواد…
                    </p>
                ) : subjectsQuery.isError ? (
                    <Feedback tone="danger">
                        تعذر تحميل المواد.
                    </Feedback>
                ) : subjectsQuery.data.length
                    === 0 ? (
                    <Feedback>
                        لا توجد مواد متاحة.
                    </Feedback>
                ) : (
                    <div className="admin-content-context__fields">
                        <label>
                            المادة

                            <select
                                value={
                                    subjectId
                                    ?? ''
                                }
                                onChange={(
                                    event,
                                ) => {
                                    const value =
                                        event.target
                                            .value
                                        || null;

                                    onSubjectChange(
                                        value,
                                    );

                                    onCurriculumChange(
                                        null,
                                    );

                                    onCurriculumVersionChange(
                                        null,
                                    );

                                    onVersionResolved(
                                        null,
                                    );
                                }}
                            >
                                {subjectsQuery.data.map(
                                    (subject) => (
                                        <option
                                            key={
                                                subject.id
                                            }
                                            value={
                                                subject.id
                                            }
                                        >
                                            {
                                                subject.name
                                            }
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>

                        <label>
                            المنهج

                            <select
                                value={
                                    curriculumId
                                    ?? ''
                                }
                                disabled={
                                    !subjectId
                                    || curriculaQuery
                                        .isPending
                                    || curriculaQuery
                                        .isError
                                    || (
                                        curriculaQuery
                                            .data
                                        && curriculaQuery
                                            .data
                                            .length
                                            === 0
                                    )
                                }
                                onChange={(
                                    event,
                                ) => {
                                    const value =
                                        event.target
                                            .value
                                        || null;

                                    onCurriculumChange(
                                        value,
                                    );

                                    onCurriculumVersionChange(
                                        null,
                                    );

                                    onVersionResolved(
                                        null,
                                    );
                                }}
                            >
                                {curriculaQuery.data
                                    ?.map(
                                        (
                                            curriculum,
                                        ) => (
                                            <option
                                                key={
                                                    curriculum.id
                                                }
                                                value={
                                                    curriculum.id
                                                }
                                            >
                                                {
                                                    curriculum.name
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>

                        <label>
                            إصدار المنهج

                            <select
                                value={
                                    curriculumVersionId
                                    ?? ''
                                }
                                disabled={
                                    !curriculumId
                                    || versionsQuery
                                        .isPending
                                    || versionsQuery
                                        .isError
                                    || (
                                        versionsQuery
                                            .data
                                        && versionsQuery
                                            .data
                                            .length
                                            === 0
                                    )
                                }
                                onChange={(
                                    event,
                                ) => {
                                    onCurriculumVersionChange(
                                        event.target
                                            .value
                                        || null,
                                    );
                                }}
                            >
                                {versionsQuery.data
                                    ?.map(
                                        (
                                            version,
                                        ) => (
                                            <option
                                                key={
                                                    version.id
                                                }
                                                value={
                                                    version.id
                                                }
                                            >
                                                {
                                                    version.label
                                                }
                                                {' — '}
                                                الإصدار{' '}
                                                {
                                                    version.version_number
                                                }
                                                {' — '}
                                                {
                                                    statusLabel(
                                                        version.status,
                                                    )
                                                }
                                            </option>
                                        ),
                                    )}
                            </select>
                        </label>
                    </div>
                )}

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

                {selectedVersion ? (
                    <Feedback
                        tone={
                            selectedVersion.status
                            === 'draft'
                                ? 'success'
                                : undefined
                        }
                    >
                        {selectedVersion.status
                        === 'draft'
                            ? 'هذه النسخة مسودة ويمكن تعديل محتواها.'
                            : 'هذه النسخة للقراءة فقط؛ عمليات التأليف مجمدة.'}
                    </Feedback>
                ) : null}
            </div>
        </Surface>
    );
}

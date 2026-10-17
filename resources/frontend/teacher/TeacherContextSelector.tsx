import {
    useEffect,
    useRef,
    useState,
} from 'react';
import {
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';

import {
    Feedback,
} from '../ui';

import {
    fetchTeacherAssignments,
    fetchTeacherCurricula,
    fetchTeacherCurriculumVersions,
    teacherAssignmentsKey,
    teacherCurriculaKey,
    teacherCurriculumVersionsKey,
} from './api';

import type {
    TeacherCurriculum,
    TeacherCurriculumVersion,
    CurriculumVersionStatus,
    TeacherSubjectAssignment,
} from './types';

export interface TeacherContext {
    assignmentId: string;
    curriculumId: string;
    curriculumVersionId: string;
    versionStatus: CurriculumVersionStatus;
}

interface TeacherContextSelectorProps {
    authenticatedUserId: string;
    assignmentId: string | null;
    curriculumId: string | null;
    curriculumVersionId: string | null;
    onContextResolved: (
        context: TeacherContext,
    ) => void;
    onContextUnavailable: () => void;
    onBeforeContextChange?: () => boolean;
}

function isUnavailable(error: unknown): boolean {
    return typeof error === 'object'
        && error !== null
        && 'status' in error
        && (
            error.status === 403
            || error.status === 404
        );
}

function versionLabel(
    version: TeacherCurriculumVersion,
): string {
    return (
        'الإصدار '
        + version.version_number
        + ' — '
        + version.label
    );
}

export function TeacherContextSelector({
    assignmentId,
    authenticatedUserId,
    curriculumId,
    curriculumVersionId,
    onContextResolved,
    onContextUnavailable,
    onBeforeContextChange,
}: TeacherContextSelectorProps) {
    const queryClient = useQueryClient();
    const routeContext = {
        assignmentId,
        curriculumId,
        curriculumVersionId,
    };
    const routeKey = [
        assignmentId ?? '',
        curriculumId ?? '',
        curriculumVersionId ?? '',
    ].join(':');
    const previousRouteKey = useRef(routeKey);
    const lastResolvedContext = useRef<string | null>(
        null,
    );
    const [selection, setSelection] = useState(
        routeContext,
    );
    const routeUpdatePending =
        previousRouteKey.current !== routeKey;
    const currentSelection = routeUpdatePending
        ? routeContext
        : selection;

    useEffect(() => {
        if (previousRouteKey.current === routeKey) {
            return;
        }

        previousRouteKey.current = routeKey;
        lastResolvedContext.current = null;
        setSelection(routeContext);
    }, [
        routeContext,
        routeKey,
    ]);

    const assignmentsQuery = useQuery({
        queryKey:
            teacherAssignmentsKey(
                authenticatedUserId,
            ),
        queryFn: ({
            signal,
        }) => fetchTeacherAssignments(signal),
    });

    const selectedAssignment =
        assignmentsQuery.data?.find(
            (item) =>
                item.id
                === currentSelection.assignmentId,
        ) ?? null;
    const activeAssignment =
        selectedAssignment?.status === 'active'
        && selectedAssignment.subject.status === 'active'
            ? selectedAssignment
            : null;

    const curriculaQuery = useQuery({
        queryKey:
            teacherCurriculaKey(
                authenticatedUserId,
                currentSelection.assignmentId ?? '',
            ),
        queryFn: ({
            signal,
        }) =>
            fetchTeacherCurricula(
                currentSelection.assignmentId!,
                signal,
            ),
        enabled:
            currentSelection.assignmentId !== null
            && activeAssignment !== null,
    });

    const selectedCurriculum =
        curriculaQuery.data?.find(
            (item) =>
                item.id
                === currentSelection.curriculumId
                && item.teacher_subject_assignment_id
                === activeAssignment?.id,
        ) ?? null;

    const versionsQuery = useQuery({
        queryKey:
            teacherCurriculumVersionsKey(
                authenticatedUserId,
                currentSelection.assignmentId ?? '',
                currentSelection.curriculumId ?? '',
            ),
        queryFn: ({
            signal,
        }) =>
            fetchTeacherCurriculumVersions(
                currentSelection.assignmentId!,
                currentSelection.curriculumId!,
                signal,
            ),
        enabled:
            currentSelection.assignmentId !== null
            && currentSelection.curriculumId !== null
            && activeAssignment !== null
            && selectedCurriculum !== null,
    });

    const selectedVersion =
        versionsQuery.data?.find(
            (item) =>
                item.id
                === currentSelection.curriculumVersionId
                && item.curriculum_id
                === selectedCurriculum?.id,
        ) ?? null;

    const hasScopedRoute =
        assignmentId !== null
        && curriculumId !== null
        && curriculumVersionId !== null;
    const queryUnavailable = [
        assignmentsQuery.error,
        curriculaQuery.error,
        versionsQuery.error,
    ].some(isUnavailable);
    const invalidAssignment =
        assignmentsQuery.isSuccess
        && currentSelection.assignmentId !== null
        && activeAssignment === null;
    const invalidCurriculum =
        activeAssignment !== null
        && curriculaQuery.isSuccess
        && currentSelection.curriculumId !== null
        && selectedCurriculum === null;
    const invalidVersion =
        selectedCurriculum !== null
        && versionsQuery.isSuccess
        && currentSelection.curriculumVersionId !== null
        && selectedVersion === null;

    useEffect(() => {
        if (hasScopedRoute && queryUnavailable) {
            onContextUnavailable();
        }
    }, [
        hasScopedRoute,
        onContextUnavailable,
        queryUnavailable,
    ]);

    useEffect(() => {
        if (
            hasScopedRoute
            && (
                invalidAssignment
                || invalidCurriculum
                || invalidVersion
            )
        ) {
            onContextUnavailable();
        }
    }, [
        hasScopedRoute,
        invalidAssignment,
        invalidCurriculum,
        invalidVersion,
        onContextUnavailable,
    ]);

    useEffect(() => {
        if (
            !activeAssignment
            || !selectedCurriculum
            || !selectedVersion
        ) {
            lastResolvedContext.current = null;

            return;
        }

        const context = {
            assignmentId: activeAssignment.id,
            curriculumId: selectedCurriculum.id,
            curriculumVersionId: selectedVersion.id,
            versionStatus: selectedVersion.status,
        };
        const contextKey = [
            context.assignmentId,
            context.curriculumId,
            context.curriculumVersionId,
            context.versionStatus,
        ].join(':');

        if (lastResolvedContext.current === contextKey) {
            return;
        }

        lastResolvedContext.current = contextKey;
        onContextResolved(context);
    }, [
        activeAssignment,
        onContextResolved,
        selectedCurriculum,
        selectedVersion,
    ]);

    function cancelDependentQueries() {
        if (currentSelection.curriculumId !== null) {
            void queryClient.cancelQueries({
                queryKey:
                    teacherCurriculumVersionsKey(
                        authenticatedUserId,
                        currentSelection.assignmentId ?? '',
                        currentSelection.curriculumId,
                    ),
            });
        }

        if (currentSelection.assignmentId !== null) {
            void queryClient.cancelQueries({
                queryKey:
                    teacherCurriculaKey(
                        authenticatedUserId,
                        currentSelection.assignmentId,
                    ),
            });
        }
    }

    function selectAssignment(
        nextAssignmentId: string | null,
    ) {
        if (onBeforeContextChange?.() === false) {
            return;
        }

        cancelDependentQueries();
        lastResolvedContext.current = null;
        setSelection({
            assignmentId: nextAssignmentId,
            curriculumId: null,
            curriculumVersionId: null,
        });
    }

    function selectCurriculum(
        nextCurriculumId: string | null,
    ) {
        if (onBeforeContextChange?.() === false) {
            return;
        }

        if (currentSelection.curriculumId !== null) {
            void queryClient.cancelQueries({
                queryKey:
                    teacherCurriculumVersionsKey(
                        authenticatedUserId,
                        currentSelection.assignmentId ?? '',
                        currentSelection.curriculumId,
                    ),
            });
        }

        lastResolvedContext.current = null;
        setSelection((current) => ({
            ...current,
            curriculumId: nextCurriculumId,
            curriculumVersionId: null,
        }));
    }

    function selectVersion(
        nextVersionId: string | null,
    ) {
        if (onBeforeContextChange?.() === false) {
            return;
        }

        lastResolvedContext.current = null;
        setSelection((current) => ({
            ...current,
            curriculumVersionId: nextVersionId,
        }));
    }

    if (assignmentsQuery.isPending) {
        return (
            <Feedback>
                جار تحميل تعيينات المادة…
            </Feedback>
        );
    }

    if (assignmentsQuery.isError) {
        return (
            <Feedback tone="danger">
                تعذر تحميل تعيينات المادة.
            </Feedback>
        );
    }

    const activeAssignments =
        assignmentsQuery.data.filter(
            (item) =>
                item.status === 'active'
                && item.subject.status === 'active',
        );
    const inactiveAssignments =
        assignmentsQuery.data.filter(
            (item) =>
                item.status !== 'active'
                || item.subject.status !== 'active',
        );

    if (activeAssignments.length === 0) {
        return (
            <Feedback>
                لا توجد تعيينات مادة نشطة متاحة للتأليف.
            </Feedback>
        );
    }

    return (
        <div
            className="teacher-context-selector"
            aria-label="سياق التأليف"
        >
            <label>
                <span>تعيين المادة</span>
                <select
                    aria-label="تعيين المادة"
                    value={
                        currentSelection.assignmentId ?? ''
                    }
                    onChange={(event) => {
                        selectAssignment(
                            event.target.value || null,
                        );
                    }}
                >
                    <option value="">
                        اختر تعيين المادة
                    </option>
                    {activeAssignments.map(
                        (
                            assignment: TeacherSubjectAssignment,
                        ) => (
                            <option
                                key={assignment.id}
                                value={assignment.id}
                            >
                                {assignment.subject.name}
                            </option>
                        ),
                    )}
                    {inactiveAssignments.length > 0 ? (
                        <optgroup label="تعيينات غير نشطة">
                            {inactiveAssignments.map(
                                (assignment) => (
                                    <option
                                        disabled
                                        key={assignment.id}
                                        value={assignment.id}
                                    >
                                        {assignment.subject.name}
                                    </option>
                                ),
                            )}
                        </optgroup>
                    ) : null}
                </select>
            </label>

            <label>
                <span>المنهج</span>
                <select
                    aria-label="المنهج"
                    value={
                        currentSelection.curriculumId ?? ''
                    }
                    disabled={
                        activeAssignment === null
                        || curriculaQuery.isPending
                        || curriculaQuery.isError
                        || curriculaQuery.data?.length === 0
                    }
                    onChange={(event) => {
                        selectCurriculum(
                            event.target.value || null,
                        );
                    }}
                >
                    <option value="">
                        اختر المنهج
                    </option>
                    {curriculaQuery.data?.map(
                        (
                            curriculum: TeacherCurriculum,
                        ) => (
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

            <label>
                <span>إصدار المنهج</span>
                <select
                    aria-label="إصدار المنهج"
                    value={
                        currentSelection.curriculumVersionId
                        ?? ''
                    }
                    disabled={
                        selectedCurriculum === null
                        || versionsQuery.isPending
                        || versionsQuery.isError
                        || versionsQuery.data?.length === 0
                    }
                    onChange={(event) => {
                        selectVersion(
                            event.target.value || null,
                        );
                    }}
                >
                    <option value="">
                        اختر إصدار المنهج
                    </option>
                    {versionsQuery.data?.map(
                        (version) => (
                            <option
                                key={version.id}
                                value={version.id}
                            >
                                {versionLabel(version)}
                            </option>
                        ),
                    )}
                </select>
            </label>

            {activeAssignment
                && curriculaQuery.isSuccess
                && curriculaQuery.data.length === 0 ? (
                <Feedback>
                    لا توجد مناهج متاحة لهذا التعيين.
                </Feedback>
            ) : null}

            {selectedCurriculum
                && versionsQuery.isSuccess
                && versionsQuery.data.length === 0 ? (
                <Feedback>
                    لا توجد إصدارات متاحة لهذا المنهج.
                </Feedback>
            ) : null}

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

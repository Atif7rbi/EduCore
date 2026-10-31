import {
    useRef,
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';

import {
    Button,
    Feedback,
} from '../../ui';

import {
    adminCanonicalSubjectsKey,
    adminTeacherKey,
    adminTeachersKey,
    assignTeacherSubject,
    deactivateTeacherAssignment,
    fetchAdminCanonicalSubjects,
    fetchTeacherAssignments,
    reactivateTeacherAssignment,
    teacherAssignmentsKey,
} from './api';
import {
    ImpactConfirmationDialog,
} from './ImpactConfirmationDialog';

import type {
    AdminTeacher,
    AssignmentOperationPayload,
    AssignTeacherSubjectPayload,
    TeacherSubjectAssignment,
} from './types';

function operationId(): string {
    return globalThis.crypto.randomUUID();
}

export function TeacherAssignmentsPanel({
    teacher,
}: {
    teacher: AdminTeacher;
}) {
    const queryClient =
        useQueryClient();

    const [subjectId, setSubjectId] =
        useState('');

    const [reason, setReason] =
        useState('');

    const [pendingDeactivation, setPendingDeactivation] =
        useState<{
            assignment: TeacherSubjectAssignment;
            teacherName: string;
            reason: string;
        } | null>(null);
    const deactivationSubmissionStarted =
        useRef(false);
    const currentTeacherUserId =
        useRef(teacher.user_id);
    currentTeacherUserId.current =
        teacher.user_id;

    const assignmentsQuery =
        useQuery({
            queryKey:
                teacherAssignmentsKey(
                    teacher.user_id
                ),
            queryFn: ({ signal }) =>
                fetchTeacherAssignments(
                    teacher.user_id,
                    signal,
                ),
        });

    const subjectsQuery =
        useQuery({
            queryKey:
                adminCanonicalSubjectsKey(),
            queryFn: ({ signal }) =>
                fetchAdminCanonicalSubjects(
                    signal,
                ),
        });

    async function refreshTeacherTruth(
        teacherUserId: string,
    ) {
        await Promise.all([
            queryClient.invalidateQueries({
                queryKey:
                    teacherAssignmentsKey(
                        teacherUserId
                    ),
            }),
            queryClient.invalidateQueries({
                queryKey:
                    adminTeacherKey(
                        teacherUserId
                    ),
            }),
            queryClient.invalidateQueries({
                queryKey:
                    adminTeachersKey(),
            }),
        ]);
    }

    const assignMutation =
        useMutation({
            mutationFn: ({
                teacherUserId,
                payload,
            }: {
                teacherUserId: string;
                payload:
                    AssignTeacherSubjectPayload;
            }) =>
                assignTeacherSubject(
                    teacherUserId,
                    payload,
                ),

            onSuccess: async (
                _result,
                variables,
            ) => {
                if (
                    currentTeacherUserId.current
                    === variables.teacherUserId
                ) {
                    setSubjectId('');
                    setReason('');
                }

                await refreshTeacherTruth(
                    variables.teacherUserId,
                );
            },
        });

    const lifecycleMutation =
        useMutation({
            mutationFn: ({
                assignment,
                operation,
                payload,
                teacherUserId,
            }: {
                assignment:
                    TeacherSubjectAssignment;
                operation:
                    'deactivate'
                    | 'reactivate';
                payload:
                    AssignmentOperationPayload;
                teacherUserId: string;
            }) => {
                if (
                    operation
                    === 'deactivate'
                ) {
                    return deactivateTeacherAssignment(
                        assignment.id,
                        payload,
                    );
                }

                return reactivateTeacherAssignment(
                    assignment.id,
                    payload,
                );
            },

            onSuccess: async (
                _result,
                variables,
            ) => {
                if (
                    variables.operation
                    === 'deactivate'
                    && currentTeacherUserId.current
                        === variables.teacherUserId
                ) {
                    setPendingDeactivation(null);
                    setReason('');
                }

                await refreshTeacherTruth(
                    variables.teacherUserId,
                );
            },
            onSettled: () => {
                deactivationSubmissionStarted.current = false;
            },
        });

    const assignments =
        assignmentsQuery.data ?? [];

    const assignedSubjectIds =
        new Set(
            assignments.map(
                (assignment) =>
                    assignment.subject.id
            )
        );

    const availableSubjects =
        (subjectsQuery.data ?? [])
            .filter(
                (subject) =>
                    subject.status
                        === 'active'
                    && !assignedSubjectIds
                        .has(subject.id)
            );

    const teacherCanReceiveGrant =
        teacher.status === 'active';

    const trimmedReason =
        reason.trim();

    function submitAssignment() {
        if (
            !teacherCanReceiveGrant
            || !subjectId
            || !trimmedReason
        ) {
            return;
        }

        assignMutation.mutate({
            teacherUserId: teacher.user_id,
            payload: {
                subject_id:
                    subjectId,
                operation_id:
                    operationId(),
                reason:
                    trimmedReason,
            },
        });
    }

    function runLifecycle(
        assignment:
            TeacherSubjectAssignment,
        operation:
            'deactivate'
            | 'reactivate',
    ) {
        if (
            !trimmedReason
            || pendingDeactivation !== null
            || lifecycleMutation.isPending
        ) {
            return;
        }

        if (
            operation === 'deactivate'
        ) {
            setPendingDeactivation({
                assignment,
                teacherName: teacher.name,
                reason: trimmedReason,
            });

            return;
        }

        lifecycleMutation.mutate({
            teacherUserId: teacher.user_id,
            assignment,
            operation,
            payload: {
                operation_id:
                    operationId(),
                reason:
                    trimmedReason,
            },
        });
    }

    function confirmDeactivation() {
        if (
            pendingDeactivation === null
            || deactivationSubmissionStarted.current
            || lifecycleMutation.isPending
        ) {
            return;
        }

        deactivationSubmissionStarted.current = true;

        lifecycleMutation.mutate({
            teacherUserId: teacher.user_id,
            assignment:
                pendingDeactivation.assignment,
            operation: 'deactivate',
            payload: {
                operation_id:
                    operationId(),
                reason:
                    pendingDeactivation.reason,
            },
        });
    }

    function deactivationCancellationLocked() {
        return deactivationSubmissionStarted.current
            || lifecycleMutation.isPending;
    }

    if (
        assignmentsQuery.isPending
        || subjectsQuery.isPending
    ) {
        return (
            <Feedback>
                جار تحميل إسنادات المواد…
            </Feedback>
        );
    }

    if (
        assignmentsQuery.isError
        || subjectsQuery.isError
    ) {
        return (
            <Feedback tone="danger">
                تعذر تحميل إسنادات المواد.
            </Feedback>
        );
    }

    return (
        <section
            className="teacher-assignments"
            aria-labelledby="teacher-assignments-title"
        >
            <div className="admin-operations__section-heading">
                <div>
                    <h3 id="teacher-assignments-title">
                        إسنادات المواد
                    </h3>

                    <p>
                        المواد هنا من الكتالوج
                        المعتمد فقط. لا يمكن إنشاء
                        مادة أو تعديل هويتها من هذه
                        الشاشة.
                    </p>
                </div>
            </div>

            {!teacherCanReceiveGrant ? (
                <Feedback tone="warning">
                    حساب المعلم غير نشط. يمكن
                    إلغاء الإسنادات الحالية، لكن
                    لا يمكن إنشاء إسناد جديد أو
                    إعادة تفعيل إسناد حتى يصبح
                    المعلم نشطًا.
                </Feedback>
            ) : null}

            <div className="teacher-assignments__operation">
                <label>
                    <span>
                        سبب العملية
                    </span>

                    <textarea
                        value={reason}
                        maxLength={1000}
                        rows={3}
                        onChange={(event) => {
                            setReason(
                                event.target.value
                            );
                        }}
                    />
                </label>

                <div className="teacher-assignments__assign-row">
                    <label>
                        <span>
                            المادة
                        </span>

                        <select
                            aria-label="المادة"
                            value={subjectId}
                            disabled={
                                !teacherCanReceiveGrant
                                || availableSubjects
                                    .length === 0
                            }
                            onChange={(event) => {
                                setSubjectId(
                                    event.target.value
                                );
                            }}
                        >
                            <option value="">
                                اختر مادة
                            </option>

                            {availableSubjects.map(
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

                    <Button
                        onClick={
                            submitAssignment
                        }
                        isLoading={
                            assignMutation
                                .isPending
                        }
                        disabled={
                            !teacherCanReceiveGrant
                            || !subjectId
                            || !trimmedReason
                        }
                    >
                        إسناد المادة
                    </Button>
                </div>
            </div>

            {assignMutation.isError ? (
                <Feedback tone="danger">
                    تعذر إسناد المادة.
                </Feedback>
            ) : null}

            {lifecycleMutation.isError ? (
                <Feedback tone="danger">
                    تعذر تنفيذ عملية الإسناد.
                </Feedback>
            ) : null}

            <div
                className="teacher-assignments__list"
                aria-label="إسنادات المعلم"
            >
                {assignments.length === 0 ? (
                    <Feedback>
                        لا توجد مواد مسندة لهذا
                        المعلم.
                    </Feedback>
                ) : (
                    assignments.map(
                        (assignment) => {
                            const canReactivate =
                                teacher.status
                                    === 'active'
                                && assignment
                                    .subject
                                    .status
                                    === 'active';

                            return (
                                <article
                                    key={
                                        assignment.id
                                    }
                                    className="teacher-assignments__item"
                                >
                                    <div>
                                        <strong>
                                            {
                                                assignment
                                                    .subject
                                                    .name
                                            }
                                        </strong>

                                        <span>
                                            {
                                                assignment
                                                    .subject
                                                    .code
                                            }
                                        </span>
                                    </div>

                                    <span
                                        className="admin-operations__status"
                                        data-status={
                                            assignment
                                                .status
                                        }
                                    >
                                        {assignment
                                            .status
                                        === 'active'
                                            ? 'نشط'
                                            : 'غير نشط'}
                                    </span>

                                    {assignment
                                        .status
                                    === 'active' ? (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            disabled={
                                                !trimmedReason
                                                || pendingDeactivation !== null
                                                || lifecycleMutation.isPending
                                            }
                                            isLoading={
                                                lifecycleMutation
                                                    .isPending
                                            }
                                            onClick={() => {
                                                runLifecycle(
                                                    assignment,
                                                    'deactivate',
                                                );
                                            }}
                                        >
                                            إلغاء الإسناد
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            disabled={
                                                !trimmedReason
                                                || !canReactivate
                                                || pendingDeactivation !== null
                                                || lifecycleMutation.isPending
                                            }
                                            isLoading={
                                                lifecycleMutation
                                                    .isPending
                                            }
                                            onClick={() => {
                                                runLifecycle(
                                                    assignment,
                                                    'reactivate',
                                                );
                                            }}
                                        >
                                            إعادة التفعيل
                                        </Button>
                                    )}
                                </article>
                            );
                        },
                    )
                )}
            </div>

            {pendingDeactivation ? (
                <ImpactConfirmationDialog
                    title="تأكيد إلغاء إسناد المادة"
                    target={`${pendingDeactivation.teacherName} — ${pendingDeactivation.assignment.subject.name}`}
                    impact="سيصبح الإسناد غير نشط، وقد يتوقف الوصول التعليمي المرتبط به وفق قواعد الأهلية الحالية."
                    reason={pendingDeactivation.reason}
                    confirmLabel="تأكيد إلغاء الإسناد"
                    isPending={lifecycleMutation.isPending}
                    isCancellationLocked={
                        deactivationCancellationLocked
                    }
                    errorMessage={
                        lifecycleMutation.isError
                            ? 'تعذر إلغاء الإسناد. لم يتم افتراض نجاح العملية، ويمكنك التراجع أو المحاولة مرة أخرى.'
                            : null
                    }
                    onCancel={() => {
                        if (
                            !deactivationCancellationLocked()
                        ) {
                            setPendingDeactivation(null);
                        }
                    }}
                    onConfirm={confirmDeactivation}
                />
            ) : null}
        </section>
    );
}

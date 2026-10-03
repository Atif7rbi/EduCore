import {
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

    const assignmentsQuery =
        useQuery({
            queryKey:
                teacherAssignmentsKey(
                    teacher.user_id
                ),
            queryFn: () =>
                fetchTeacherAssignments(
                    teacher.user_id
                ),
        });

    const subjectsQuery =
        useQuery({
            queryKey:
                adminCanonicalSubjectsKey(),
            queryFn:
                fetchAdminCanonicalSubjects,
        });

    async function refreshTeacherTruth() {
        await Promise.all([
            queryClient.invalidateQueries({
                queryKey:
                    teacherAssignmentsKey(
                        teacher.user_id
                    ),
            }),
            queryClient.invalidateQueries({
                queryKey:
                    adminTeacherKey(
                        teacher.user_id
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
                payload,
            }: {
                payload:
                    AssignTeacherSubjectPayload;
            }) =>
                assignTeacherSubject(
                    teacher.user_id,
                    payload,
                ),

            onSuccess: async () => {
                setSubjectId('');
                setReason('');

                await refreshTeacherTruth();
            },
        });

    const lifecycleMutation =
        useMutation({
            mutationFn: ({
                assignment,
                operation,
                payload,
            }: {
                assignment:
                    TeacherSubjectAssignment;
                operation:
                    'deactivate'
                    | 'reactivate';
                payload:
                    AssignmentOperationPayload;
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

            onSuccess:
                refreshTeacherTruth,
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
        if (!trimmedReason) {
            return;
        }

        lifecycleMutation.mutate({
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
        </section>
    );
}

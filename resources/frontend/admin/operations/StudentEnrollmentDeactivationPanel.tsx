import {
    useRef,
    useState,
} from 'react';
import {
    useMutation,
    useQueryClient,
} from '@tanstack/react-query';

import {
    Button,
    Feedback,
} from '../../ui';

import {
    adminStudentKey,
    adminStudentsKey,
    deactivateStudentEnrollment,
    studentEnrollmentKey,
    studentEnrollmentsKey,
} from './studentApi';
import {
    ImpactConfirmationDialog,
} from './ImpactConfirmationDialog';

import type {
    StudentEnrollmentRead,
} from './studentTypes';

function operationId(): string {
    return globalThis.crypto.randomUUID();
}

export function StudentEnrollmentDeactivationPanel({
    enrollment,
}: {
    enrollment: StudentEnrollmentRead;
}) {
    const queryClient =
        useQueryClient();

    const [reason, setReason] =
        useState('');

    const [pendingDeactivation, setPendingDeactivation] =
        useState<{
            enrollmentId: string;
            studentUserId: string;
            studentName: string;
            subjectName: string;
            reason: string;
        } | null>(null);
    const deactivationSubmissionStarted =
        useRef(false);
    const currentEnrollmentId =
        useRef(enrollment.id);
    currentEnrollmentId.current =
        enrollment.id;

    const studentUserId =
        enrollment.student.user_id;

    const trimmedReason =
        reason.trim();

    const mutation =
        useMutation({
            mutationFn: (
                confirmed: NonNullable<
                    typeof pendingDeactivation
                >,
            ) =>
                deactivateStudentEnrollment(
                    confirmed.enrollmentId,
                    {
                        operation_id:
                            operationId(),
                        reason:
                            confirmed.reason,
                    },
                ),

            onSuccess: async (
                _result,
                confirmed,
            ) => {
                if (
                    currentEnrollmentId.current
                    === confirmed.enrollmentId
                ) {
                    setReason('');
                    setPendingDeactivation(null);
                }

                await Promise.all([
                    queryClient
                        .invalidateQueries({
                            queryKey:
                                adminStudentsKey(),
                        }),

                    queryClient
                        .invalidateQueries({
                            queryKey:
                                adminStudentKey(
                                    confirmed.studentUserId
                                ),
                        }),

                    queryClient
                        .invalidateQueries({
                            queryKey:
                                studentEnrollmentsKey(
                                    confirmed.studentUserId
                                ),
                        }),

                    queryClient
                        .invalidateQueries({
                            queryKey:
                                studentEnrollmentKey(
                                    confirmed.enrollmentId
                                ),
                        }),
                ]);
            },
            onSettled: () => {
                deactivationSubmissionStarted.current = false;
            },
        });

    function confirmDeactivation() {
        if (
            pendingDeactivation === null
            || deactivationSubmissionStarted.current
            || mutation.isPending
        ) {
            return;
        }

        deactivationSubmissionStarted.current = true;

        mutation.mutate(
            pendingDeactivation,
        );
    }

    function deactivationCancellationLocked() {
        return deactivationSubmissionStarted.current
            || mutation.isPending;
    }

    if (
        enrollment.status
        === 'pending'
    ) {
        return (
            <Feedback tone="info">
                هذا التسجيل بانتظار قرار المعلم.
                Phase G لا يمنح Admin صلاحية
                القبول أو الرفض أو تحويل pending
                إلى inactive.
            </Feedback>
        );
    }

    if (
        enrollment.status
        === 'inactive'
    ) {
        return (
            <Feedback>
                التسجيل غير نشط بالفعل. لا توجد
                عملية Admin إضافية متاحة من هذه
                الشاشة.
            </Feedback>
        );
    }

    return (
        <section className="admin-students__deactivation">
            <div className="admin-operations__section-heading">
                <div>
                    <h4>
                        إلغاء التسجيل
                    </h4>

                    <p>
                        عملية تشغيلية مسموحة
                        للـAdmin على التسجيل النشط
                        فقط. يتم تسجيل السبب وهوية
                        المنفذ في سجل الانتقالات.
                    </p>
                </div>
            </div>

            <label>
                <span>
                    سبب إلغاء التسجيل
                </span>

                <textarea
                    rows={3}
                    maxLength={1000}
                    value={reason}
                    onChange={(event) => {
                        setReason(
                            event.target.value
                        );
                    }}
                />
            </label>

            <Button
                variant="danger"
                isLoading={
                    mutation.isPending
                }
                disabled={
                    !trimmedReason
                    || pendingDeactivation !== null
                    || mutation.isPending
                }
                onClick={() => {
                    if (
                        trimmedReason
                        && pendingDeactivation === null
                        && !mutation.isPending
                    ) {
                        setPendingDeactivation({
                            enrollmentId: enrollment.id,
                            studentUserId,
                            studentName:
                                enrollment.student.name,
                            subjectName:
                                enrollment
                                    .teacher_subject_assignment
                                    .subject.name,
                            reason: trimmedReason,
                        });
                    }
                }}
            >
                إلغاء التسجيل
            </Button>

            {mutation.isError ? (
                <Feedback tone="danger">
                    تعذر إلغاء التسجيل.
                    لم يتم افتراض أي تغيير في
                    الحالة المحلية؛ أعد تحميل
                    البيانات قبل المحاولة مرة
                    أخرى.
                </Feedback>
            ) : null}

            {pendingDeactivation ? (
                <ImpactConfirmationDialog
                    title="تأكيد إلغاء تسجيل الطالب"
                    target={`${pendingDeactivation.studentName} — ${pendingDeactivation.subjectName}`}
                    impact="سيتحول التسجيل النشط إلى غير نشط مع الاحتفاظ بسجل الانتقالات التاريخي."
                    reason={pendingDeactivation.reason}
                    confirmLabel="تأكيد إلغاء التسجيل"
                    isPending={mutation.isPending}
                    isCancellationLocked={
                        deactivationCancellationLocked
                    }
                    errorMessage={
                        mutation.isError
                            ? 'تعذر إلغاء التسجيل. لم يتم افتراض نجاح العملية، ولا تزال إعادة المحاولة بحاجة إلى تأكيد صريح.'
                            : null
                    }
                    onCancel={() => {
                        if (!deactivationCancellationLocked()) {
                            setPendingDeactivation(null);
                        }
                    }}
                    onConfirm={confirmDeactivation}
                />
            ) : null}
        </section>
    );
}

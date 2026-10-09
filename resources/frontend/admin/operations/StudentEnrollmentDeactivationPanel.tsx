import {
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

    const studentUserId =
        enrollment.student.user_id;

    const trimmedReason =
        reason.trim();

    const mutation =
        useMutation({
            mutationFn: () =>
                deactivateStudentEnrollment(
                    enrollment.id,
                    {
                        operation_id:
                            operationId(),
                        reason:
                            trimmedReason,
                    },
                ),

            onSuccess: async () => {
                setReason('');

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
                                    studentUserId
                                ),
                        }),

                    queryClient
                        .invalidateQueries({
                            queryKey:
                                studentEnrollmentsKey(
                                    studentUserId
                                ),
                        }),

                    queryClient
                        .invalidateQueries({
                            queryKey:
                                studentEnrollmentKey(
                                    enrollment.id
                                ),
                        }),
                ]);
            },
        });

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
                }
                onClick={() => {
                    mutation.mutate();
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
        </section>
    );
}

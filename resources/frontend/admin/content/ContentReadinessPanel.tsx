import {
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
} from '@tanstack/react-query';

import {
    apiRequest,
} from '../../api/client';
import {
    EduCoreApiError,
} from '../../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../../ui';

import type {
    CurriculumVersion,
} from './types';

export interface ReadinessCheck {
    code: string;
    message: string;
    passed: boolean;
    value: string | number;
}

export interface ReadinessIssue {
    code: string;
    message: string;
    value: string | number;
}

export interface CurriculumReadiness {
    curriculum_version: {
        id: string;
        curriculum_id: string;
        version_number: number;
        label: string;
        status: CurriculumVersion['status'];
    };
    ready_to_publish: boolean;
    checks: ReadinessCheck[];
    counts: Record<string, number>;
    blockers: ReadinessIssue[];
    warnings: ReadinessIssue[];
}

const checkLabels: Record<string, string> = {
    curriculum_version_is_draft:
        'النسخة ما زالت في حالة مسودة',
    has_topic:
        'توجد وحدة واحدة على الأقل',
    has_skill_placement:
        'توجد مهارة مرتبطة بالنسخة',
    has_published_lesson:
        'يوجد درس منشور واحد على الأقل',
    has_published_assessment_item:
        'يوجد سؤال منشور واحد على الأقل',
    has_learner_usable_practice:
        'يوجد تدريب نشط قابل للعرض للمتعلم',
    has_usable_exam_template:
        'يوجد اختبار نشط بنسخة منشورة',
};

const failedCheckLabels: Record<string, string> = {
    curriculum_version_is_draft:
        'النسخة ليست في حالة مسودة',
    has_topic:
        'لا توجد وحدة في نسخة المنهج',
    has_skill_placement:
        'لا توجد مهارة مرتبطة بالنسخة',
    has_published_lesson:
        'لا يوجد درس منشور',
    has_published_assessment_item:
        'لا يوجد سؤال منشور',
    has_learner_usable_practice:
        'لا يوجد تدريب نشط قابل للعرض للمتعلم',
    has_usable_exam_template:
        'لا يوجد اختبار نشط بنسخة منشورة',
};

export type ReadinessTargetSection =
    | 'topics'
    | 'lessons'
    | 'assessment-items'
    | 'practice-activities'
    | 'exam-templates'
    | 'skills';

interface ReadinessGuidance {
    section: ReadinessTargetSection;
    sectionLabel: string;
    explanation: string;
}

const checkGuidance:
Record<string, ReadinessGuidance> = {
    has_topic: {
        section: 'topics',
        sectionLabel: 'الوحدات',
        explanation:
            'يجب إضافة وحدة واحدة على الأقل حتى تصبح نسخة المنهج جاهزة للنشر.',
    },
    has_skill_placement: {
        section: 'skills',
        sectionLabel: 'المهارات',
        explanation:
            'يجب ربط مهارة واحدة على الأقل بنسخة المنهج حتى تصبح جاهزة للنشر.',
    },
    has_published_lesson: {
        section: 'lessons',
        sectionLabel: 'الدروس',
        explanation:
            'يجب نشر درس واحد على الأقل حتى تصبح نسخة المنهج جاهزة للنشر.',
    },
    has_published_assessment_item: {
        section: 'assessment-items',
        sectionLabel: 'بنك الأسئلة',
        explanation:
            'يجب نشر سؤال واحد على الأقل حتى تصبح نسخة المنهج جاهزة للنشر.',
    },
    has_learner_usable_practice: {
        section: 'practice-activities',
        sectionLabel: 'التدريبات',
        explanation:
            'يجب إتاحة تدريب واحد على الأقل للطلاب حتى تصبح نسخة المنهج جاهزة للنشر.',
    },
    has_usable_exam_template: {
        section: 'exam-templates',
        sectionLabel: 'الاختبارات',
        explanation:
            'يجب أن يوجد اختبار نشط بنسخة منشورة حتى تصبح نسخة المنهج جاهزة للنشر.',
    },
};

const warningLabels: Record<string, string> = {
    draft_lessons:
        'دروس ما زالت مسودة',
    unpublished_lessons:
        'دروس غير منشورة',
    draft_assessment_items:
        'أسئلة ما زالت مسودة',
    retired_assessment_items:
        'أسئلة موقوفة محفوظة تاريخيًا',
    archived_practice_activities:
        'تدريبات مؤرشفة',
    active_practice_hidden_by_lesson:
        'تدريبات نشطة مرتبطة بدروس غير منشورة',
    archived_exam_templates:
        'اختبارات مؤرشفة',
    active_exam_templates_without_published_version:
        'اختبارات نشطة بلا نسخة منشورة قابلة للاستخدام',
    draft_exam_template_versions:
        'نسخ اختبار ما زالت مسودة',
    retired_exam_template_versions:
        'نسخ اختبار موقوفة محفوظة تاريخيًا',
};

export function contentReadinessKey(
    curriculumVersionId: string,
) {
    return [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'readiness',
    ] as const;
}

export function fetchContentReadiness(
    curriculumVersionId: string,
): Promise<CurriculumReadiness> {
    return apiRequest<CurriculumReadiness>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/readiness`,
    });
}

function publishCurriculum(
    curriculumVersionId: string,
): Promise<CurriculumVersion> {
    return apiRequest<CurriculumVersion>({
        method: 'POST',
        url:
            `/api/curriculum-versions/${curriculumVersionId}/publish`,
    });
}

function checkLabel(code: string): string {
    return checkLabels[code] ?? code;
}

function failedCheckLabel(
    code: string,
): string {
    return failedCheckLabels[code]
        ?? checkLabel(code);
}

function warningLabel(code: string): string {
    return warningLabels[code] ?? code;
}

function publishErrorMessage(
    error: unknown,
): string {
    if (
        error instanceof EduCoreApiError
        && error.code
            === 'curriculum_version_not_ready'
    ) {
        return (
            'تعذر نشر المنهج لأن متطلبات النشر '
            + 'تغيرت. تم تحديث المراجعة.'
        );
    }

    if (error instanceof EduCoreApiError) {
        return error.message;
    }

    return 'تعذر نشر المنهج.';
}

export function ContentReadinessPanel({
    version,
    onNavigateToSection,
}: {
    version: CurriculumVersion;
    onNavigateToSection?: (
        section: ReadinessTargetSection,
    ) => void;
}) {
    const [openHelpCode, setOpenHelpCode] =
        useState<string | null>(null);
    const [pinnedHelpCode, setPinnedHelpCode] =
        useState<string | null>(null);

    const readiness = useQuery({
        queryKey: contentReadinessKey(version.id),
        queryFn: () =>
            fetchContentReadiness(version.id),
    });

    const publishMutation = useMutation({
        mutationFn: () =>
            publishCurriculum(version.id),

        onSuccess: async () => {
            await readiness.refetch();
        },

        onError: async (error: unknown) => {
            if (
                error instanceof EduCoreApiError
                && error.code
                    === 'curriculum_version_not_ready'
            ) {
                await readiness.refetch();
            }
        },
    });

    if (readiness.isPending) {
        return (
            <Surface className="admin-readiness admin-readiness--loading" aria-busy="true">
                جار فحص جاهزية المحتوى…
            </Surface>
        );
    }

    if (readiness.isError) {
        return (
            <div className="foundation-stack admin-readiness admin-readiness--error">
                <Feedback tone="danger">
                    تعذر فحص جاهزية المحتوى.
                </Feedback>

                <Button
                    type="button"
                    variant="secondary"
                    onClick={() => {
                        void readiness.refetch();
                    }}
                >
                    إعادة المحاولة
                </Button>
            </div>
        );
    }

    const data = readiness.data;

    const isPublished =
        data.curriculum_version.status
        === 'published';

    const canPublish =
        data.curriculum_version.status === 'draft'
        && data.ready_to_publish;

    if (isPublished) {
        return (
            <div className="foundation-stack admin-readiness admin-readiness--published">
                <Surface className="admin-readiness__hero" elevated>
                    <div className="foundation-stack">
                        <div>
                            <h2>
                                مراجعة النشر
                            </h2>

                            <p>
                                حالة النسخة بعد اعتماد
                                المحتوى للمتعلمين.
                            </p>
                        </div>

                        <Feedback tone="success">
                            تم نشر النسخة
                        </Feedback>
                    </div>
                </Surface>

                <Feedback tone="success">
                    تم نشر المنهج بنجاح. أصبح
                    التأليف على هذه النسخة مجمدًا،
                    والمحتوى المنشور هو المرجع
                    المعتمد للمتعلمين.
                </Feedback>

                <Button
                    type="button"
                    variant="secondary"
                    onClick={() => {
                        void readiness.refetch();
                    }}
                >
                    تحديث الحالة
                </Button>
            </div>
        );
    }

    return (
        <div className="foundation-stack admin-readiness">
            <Surface className="admin-readiness__hero" elevated>
                <div className="foundation-stack">
                    <div>
                        <h2>
                            مراجعة النشر
                        </h2>

                        <p>
                            تحقق من اكتمال المحتوى قبل
                            إتاحة النسخة للمتعلمين.
                        </p>
                    </div>

                    <Feedback
                        tone={
                            data.ready_to_publish
                                ? 'success'
                                : 'warning'
                        }
                    >
                        {data.ready_to_publish
                            ? 'جاهز للنشر'
                            : 'غير جاهز للنشر'}
                    </Feedback>
                </div>
            </Surface>

            <Surface className="admin-readiness__section admin-readiness__checks">
                <div className="foundation-stack">
                    <h3>
                        متطلبات النشر
                    </h3>

                    <ul>
                        {data.checks.map((check) => {
                            const label =
                                check.passed
                                    ? checkLabel(
                                        check.code
                                    )
                                    : failedCheckLabel(
                                        check.code
                                    );

                            const guidance =
                                !check.passed
                                    ? checkGuidance[
                                        check.code
                                    ]
                                    : undefined;

                            const helpOpen =
                                openHelpCode
                                === check.code;

                            const helpId =
                                `readiness-help-${check.code}`;

                            return (
                                <li
                                    key={check.code}
                                    className={
                                        check.passed
                                            ? 'admin-readiness__check admin-readiness__check--passed'
                                            : 'admin-readiness__check admin-readiness__check--failed'
                                    }
                                >
                                    <div className="admin-readiness__check-main">
                                        <strong>
                                            <span
                                                className="admin-readiness__check-icon"
                                                aria-hidden="true"
                                            >
                                                {check.passed
                                                    ? '✓'
                                                    : '✕'}
                                            </span>

                                            <span>
                                                {label}
                                            </span>
                                        </strong>

                                        {typeof check.value ===
                                        'number' ? (
                                            <span className="admin-readiness__check-value">
                                                ({check.value})
                                            </span>
                                        ) : null}

                                        {guidance ? (
                                            <span
                                                className="admin-readiness__issue-help"
                                                onMouseEnter={() => {
                                                    setOpenHelpCode(
                                                        check.code
                                                    );
                                                }}
                                                onMouseLeave={() => {
                                                    if (
                                                        pinnedHelpCode
                                                        !== check.code
                                                    ) {
                                                        setOpenHelpCode(
                                                            null
                                                        );
                                                    }
                                                }}
                                                onFocusCapture={() => {
                                                    setOpenHelpCode(
                                                        check.code
                                                    );
                                                }}
                                                onBlurCapture={(event) => {
                                                    const next =
                                                        event.relatedTarget;

                                                    if (
                                                        pinnedHelpCode
                                                        !== check.code
                                                        && (
                                                            !next
                                                            || !event.currentTarget.contains(
                                                                next as Node
                                                            )
                                                        )
                                                    ) {
                                                        setOpenHelpCode(
                                                            null
                                                        );
                                                    }
                                                }}
                                            >
                                                <button
                                                    type="button"
                                                    className="admin-readiness__info-trigger"
                                                    aria-label={`تفاصيل ${label}`}
                                                    aria-expanded={
                                                        helpOpen
                                                    }
                                                    aria-controls={
                                                        helpId
                                                    }
                                                    onClick={() => {
                                                        if (
                                                            pinnedHelpCode
                                                            === check.code
                                                        ) {
                                                            setPinnedHelpCode(
                                                                null
                                                            );
                                                            setOpenHelpCode(
                                                                null
                                                            );
                                                            return;
                                                        }

                                                        setPinnedHelpCode(
                                                            check.code
                                                        );
                                                        setOpenHelpCode(
                                                            check.code
                                                        );
                                                    }}
                                                    onKeyDown={(event) => {
                                                        if (
                                                            event.key
                                                            === 'Escape'
                                                        ) {
                                                            setPinnedHelpCode(
                                                                null
                                                            );
                                                            setOpenHelpCode(
                                                                null
                                                            );
                                                        }
                                                    }}
                                                >
                                                    ⓘ
                                                </button>

                                                {helpOpen ? (
                                                    <div
                                                        id={
                                                            helpId
                                                        }
                                                        role="dialog"
                                                        aria-label={`تفاصيل ${label}`}
                                                        className="admin-readiness__popover"
                                                    >
                                                        <p>
                                                            {
                                                                guidance
                                                                    .explanation
                                                            }
                                                        </p>

                                                        <p className="admin-readiness__popover-section">
                                                            <strong>
                                                                القسم المطلوب:
                                                            </strong>{' '}
                                                            {
                                                                guidance
                                                                    .sectionLabel
                                                            }
                                                        </p>

                                                        {onNavigateToSection ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="secondary"
                                                                onClick={() => {
                                                                    setPinnedHelpCode(
                                                                        null
                                                                    );
                                                                    setOpenHelpCode(
                                                                        null
                                                                    );
                                                                    onNavigateToSection(
                                                                        guidance
                                                                            .section
                                                                    );
                                                                }}
                                                            >
                                                                الانتقال إلى {
                                                                    guidance
                                                                        .sectionLabel
                                                                }
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                ) : null}
                                            </span>
                                        ) : null}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </Surface>

            {data.blockers.length > 0 ? (
                <Surface className="admin-readiness__section admin-readiness__blockers">
                    <div className="foundation-stack">
                        <h3>
                            الموانع
                        </h3>

                        <ul>
                            {data.blockers.map(
                                (blocker) => (
                                    <li
                                        key={
                                            blocker.code
                                        }
                                    >
                                        {failedCheckLabel(
                                            blocker.code
                                        )}
                                    </li>
                                )
                            )}
                        </ul>
                    </div>
                </Surface>
            ) : null}

            {data.warnings.length > 0 ? (
                <Surface className="admin-readiness__section admin-readiness__warnings">
                    <div className="foundation-stack">
                        <h3>
                            تنبيهات
                        </h3>

                        <ul>
                            {data.warnings.map(
                                (warning) => (
                                    <li
                                        key={
                                            warning.code
                                        }
                                    >
                                        <span
                                            className="admin-readiness__warning-icon"
                                            aria-hidden="true"
                                        >
                                            ⚠
                                        </span>{' '}
                                        {warningLabel(
                                            warning.code
                                        )}
                                        {' '}
                                        ({warning.value})
                                    </li>
                                )
                            )}
                        </ul>
                    </div>
                </Surface>
            ) : null}

            {publishMutation.isError ? (
                <Feedback tone="danger">
                    {publishErrorMessage(
                        publishMutation.error
                    )}
                </Feedback>
            ) : null}

            {canPublish ? (
                <Surface className="admin-readiness__publish-card" elevated>
                    <div className="foundation-stack">
                        <Feedback tone="warning">
                            النشر إجراء نهائي لهذه
                            النسخة وسيوقف التأليف
                            عليها.
                        </Feedback>

                        <Button
                            type="button"
                            disabled={
                                publishMutation
                                    .isPending
                            }
                            onClick={() => {
                                const confirmed =
                                    window.confirm(
                                        'سيتم نشر المنهج وتجميد التأليف على هذه النسخة. هل تريد المتابعة؟'
                                    );

                                if (! confirmed) {
                                    return;
                                }

                                publishMutation
                                    .mutate();
                            }}
                        >
                            {publishMutation.isPending
                                ? 'جار النشر…'
                                : 'نشر المنهج'}
                        </Button>
                    </div>
                </Surface>
            ) : (
                <Feedback>
                    أكمل الموانع أعلاه ثم حدّث
                    المراجعة قبل النشر.
                </Feedback>
            )}

            <Button
                type="button"
                variant="secondary"
                disabled={
                    publishMutation.isPending
                }
                onClick={() => {
                    void readiness.refetch();
                }}
            >
                تحديث المراجعة
            </Button>
        </div>
    );
}

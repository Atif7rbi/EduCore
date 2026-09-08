import {
    useQuery,
} from '@tanstack/react-query';

import {
    apiRequest,
} from '../../api/client';
import {
    Button,
    Feedback,
    Surface,
} from '../../ui';

import type {
    CurriculumVersion,
} from './types';

interface ReadinessCheck {
    code: string;
    message: string;
    passed: boolean;
    value: string | number;
}

interface ReadinessIssue {
    code: string;
    message: string;
    value: string | number;
}

interface CurriculumReadiness {
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

function readinessKey(
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

function fetchReadiness(
    curriculumVersionId: string,
): Promise<CurriculumReadiness> {
    return apiRequest<CurriculumReadiness>({
        method: 'GET',
        url:
            `/api/admin/curriculum-versions/${curriculumVersionId}/readiness`,
    });
}

function checkLabel(code: string): string {
    return checkLabels[code] ?? code;
}

function warningLabel(code: string): string {
    return warningLabels[code] ?? code;
}

export function ContentReadinessPanel({
    version,
}: {
    version: CurriculumVersion;
}) {
    const readiness = useQuery({
        queryKey: readinessKey(version.id),
        queryFn: () =>
            fetchReadiness(version.id),
    });

    if (readiness.isPending) {
        return (
            <Surface aria-busy="true">
                جار فحص جاهزية المحتوى…
            </Surface>
        );
    }

    if (readiness.isError) {
        return (
            <div className="foundation-stack">
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

    return (
        <div className="foundation-stack">
            <Surface elevated>
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

            <Surface>
                <div className="foundation-stack">
                    <h3>
                        متطلبات النشر
                    </h3>

                    <ul>
                        {data.checks.map((check) => (
                            <li key={check.code}>
                                <strong>
                                    {check.passed
                                        ? '✓ '
                                        : '✕ '}
                                    {checkLabel(
                                        check.code
                                    )}
                                </strong>

                                {typeof check.value ===
                                'number' ? (
                                    <span>
                                        {' '}
                                        ({check.value})
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </div>
            </Surface>

            {data.blockers.length > 0 ? (
                <Surface>
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
                                        {checkLabel(
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
                <Surface>
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

            <Feedback>
                هذه الصفحة للمراجعة فقط. لا يتم
                نشر المنهج من هنا.
            </Feedback>

            <Button
                type="button"
                variant="secondary"
                onClick={() => {
                    void readiness.refetch();
                }}
            >
                تحديث المراجعة
            </Button>
        </div>
    );
}

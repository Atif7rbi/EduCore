import {
    FormEvent,
    useState,
} from 'react';
import {
    useMutation,
    useQuery,
    useQueryClient,
} from '@tanstack/react-query';

import {
    EduCoreApiError,
} from '../../api/errors';

import {
    Button,
    Feedback,
    Surface,
} from '../../ui';

import {
    activateExamTemplate,
    adminExamTemplatesKey,
    archiveExamTemplate,
    createExamTemplate,
    fetchExamTemplates,
    updateExamTemplate,
} from './api';

import {
    ExamTemplateVersionsPanel,
} from './ExamTemplateVersionsPanel';

import type {
    CurriculumVersion,
    ExamTemplate,
} from './types';

interface ExamTemplatesPanelProps {
    version: CurriculumVersion;
}

function requestId(
    error: unknown,
): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function TemplateFailure({
    children,
    error,
}: {
    children: string;
    error: unknown;
}) {
    const id = requestId(error);

    return (
        <Feedback tone="danger">
            <div>
                <strong>
                    {children}
                </strong>

                {id ? (
                    <p className="learner-read-request-id">
                        رقم الطلب: {id}
                    </p>
                ) : null}
            </div>
        </Feedback>
    );
}

function statusLabel(
    status: ExamTemplate['status'],
) {
    return status === 'active'
        ? 'متاح'
        : 'متوقف';
}

export function ExamTemplatesPanel({
    version,
}: ExamTemplatesPanelProps) {
    const queryClient =
        useQueryClient();

    const editable =
        version.status === 'draft';

    const [
        name,
        setName,
    ] = useState('');

    const [
        description,
        setDescription,
    ] = useState('');

    const [
        editingTemplate,
        setEditingTemplate,
    ] =
        useState<ExamTemplate | null>(
            null,
        );

    const [
        managingVersionsTemplateId,
        setManagingVersionsTemplateId,
    ] =
        useState<string | null>(
            null,
        );

    const templatesQuery =
        useQuery({
            queryKey:
                adminExamTemplatesKey(
                    version.id,
                ),
            queryFn: () =>
                fetchExamTemplates(
                    version.id,
                ),
        });

    const managingVersionsTemplate =
        templatesQuery.data
            ?.find(
                (template) =>
                    template.id
                    === managingVersionsTemplateId,
            )
        ?? null;

    async function invalidate() {
        await queryClient
            .invalidateQueries({
                queryKey:
                    adminExamTemplatesKey(
                        version.id,
                    ),
            });
    }

    const createMutation =
        useMutation({
            mutationFn: () =>
                createExamTemplate(
                    version.id,
                    {
                        name:
                            name.trim(),
                        description:
                            description
                                .trim()
                            || null,
                    },
                ),
            onSuccess: async () => {
                setName('');
                setDescription('');

                await invalidate();
            },
        });

    const updateMutation =
        useMutation({
            mutationFn: ({
                templateId,
                templateName,
                templateDescription,
            }: {
                templateId: string;
                templateName: string;
                templateDescription:
                    string;
            }) =>
                updateExamTemplate(
                    templateId,
                    {
                        name:
                            templateName
                                .trim(),
                        description:
                            templateDescription
                                .trim()
                            || null,
                    },
                ),
            onSuccess: async () => {
                setEditingTemplate(
                    null,
                );

                await invalidate();
            },
        });

    const lifecycleMutation =
        useMutation({
            mutationFn: ({
                templateId,
                action,
            }: {
                templateId: string;
                action:
                    | 'activate'
                    | 'archive';
            }) =>
                action === 'activate'
                    ? activateExamTemplate(
                        templateId,
                    )
                    : archiveExamTemplate(
                        templateId,
                    ),
            onSuccess: async () => {
                setEditingTemplate(
                    null,
                );

                await invalidate();
            },
        });

    function submitCreate(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editable
            || createMutation.isPending
            || name.trim() === ''
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(
        template: ExamTemplate,
    ) {
        if (
            !editable
            || template.status
                !== 'active'
        ) {
            return;
        }

        setEditingTemplate({
            ...template,
        });
    }

    function submitEdit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editingTemplate
            || !editable
            || editingTemplate.status
                !== 'active'
            || updateMutation.isPending
            || editingTemplate
                .name
                .trim() === ''
        ) {
            return;
        }

        updateMutation.mutate({
            templateId:
                editingTemplate.id,
            templateName:
                editingTemplate.name,
            templateDescription:
                editingTemplate
                    .description
                ?? '',
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div>
                    <h2 className="foundation-card__title">
                        الاختبارات
                    </h2>

                    <p className="foundation-page__description">
                        أنشئ الاختبارات ونظّمها ثم حدّد الأسئلة والإعدادات التي يحتاجها الطالب عند بدء الاختبار.
                    </p>
                </div>

                {editable ? (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitCreate
                        }
                    >
                        <label>
                            الاسم

                            <input
                                aria-label="اسم الاختبار"
                                value={name}
                                required
                                maxLength={255}
                                onChange={(
                                    event,
                                ) =>
                                    setName(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            الوصف

                            <textarea
                                aria-label="وصف الاختبار"
                                rows={4}
                                value={
                                    description
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setDescription(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <Button
                            type="submit"
                            disabled={
                                createMutation
                                    .isPending
                            }
                        >
                            إنشاء اختبار
                        </Button>
                    </form>
                ) : (
                    <Feedback>
                        هذا المنهج للقراءة فقط؛ لا يمكن إنشاء الاختبارات أو تعديلها.
                    </Feedback>
                )}

                {createMutation.isError ? (
                    <TemplateFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إنشاء الاختبار.
                    </TemplateFailure>
                ) : null}

                {updateMutation.isError ? (
                    <TemplateFailure
                        error={
                            updateMutation
                                .error
                        }
                    >
                        تعذر تعديل الاختبار.
                    </TemplateFailure>
                ) : null}

                {lifecycleMutation.isError ? (
                    <TemplateFailure
                        error={
                            lifecycleMutation
                                .error
                        }
                    >
                        تعذر تغيير حالة الاختبار.
                    </TemplateFailure>
                ) : null}

                {templatesQuery.isPending ? (
                    <p>
                        جار تحميل الاختبارات…
                    </p>
                ) : templatesQuery.isError ? (
                    <TemplateFailure
                        error={
                            templatesQuery
                                .error
                        }
                    >
                        تعذر تحميل الاختبارات.
                    </TemplateFailure>
                ) : templatesQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد اختبارات لهذا المنهج حتى الآن.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {templatesQuery.data.map(
                            (
                                template,
                            ) => (
                                <article
                                    key={
                                        template.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            {
                                                template.name
                                            }
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            الحالة:{' '}
                                            {
                                                statusLabel(
                                                    template.status,
                                                )
                                            }
                                            {' · '}
                                            الإعدادات المحفوظة:{' '}
                                            {
                                                template.versions_count
                                                ?? 0
                                            }
                                        </p>

                                        {template.description ? (
                                            <p className="admin-content-list__meta">
                                                {
                                                    template.description
                                                }
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="admin-content-actions">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            type="button"
                                            onClick={() =>
                                                setManagingVersionsTemplateId(
                                                    template.id,
                                                )
                                            }
                                        >
                                            إعداد الاختبار
                                        </Button>

                                        {editable
                                        && template.status
                                            === 'active' ? (
                                            <>
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    type="button"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                        || lifecycleMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        beginEdit(
                                                            template,
                                                        )
                                                    }
                                                >
                                                    تعديل
                                                </Button>

                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    type="button"
                                                    disabled={
                                                        lifecycleMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        lifecycleMutation
                                                            .mutate({
                                                                templateId:
                                                                    template.id,
                                                                action:
                                                                    'archive',
                                                            })
                                                    }
                                                >
                                                    إيقاف
                                                </Button>
                                            </>
                                        ) : null}

                                        {editable
                                        && template.status
                                            === 'archived' ? (
                                            <Button
                                                size="sm"
                                                type="button"
                                                disabled={
                                                    lifecycleMutation
                                                        .isPending
                                                }
                                                onClick={() =>
                                                    lifecycleMutation
                                                        .mutate({
                                                            templateId:
                                                                template.id,
                                                            action:
                                                                'activate',
                                                        })
                                                }
                                            >
                                                إعادة الإتاحة
                                            </Button>
                                        ) : null}
                                    </div>
                                </article>
                            ),
                        )}
                    </div>
                )}

                {editingTemplate ? (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitEdit
                        }
                    >
                        <h3 className="foundation-card__title">
                            تعديل الاختبار
                        </h3>

                        <label>
                            الاسم

                            <input
                                aria-label="تعديل اسم الاختبار"
                                required
                                maxLength={255}
                                value={
                                    editingTemplate
                                        .name
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingTemplate(
                                        {
                                            ...editingTemplate,
                                            name:
                                                event
                                                    .target
                                                    .value,
                                        },
                                    )
                                }
                            />
                        </label>

                        <label>
                            الوصف

                            <textarea
                                aria-label="تعديل وصف الاختبار"
                                rows={4}
                                value={
                                    editingTemplate
                                        .description
                                    ?? ''
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingTemplate(
                                        {
                                            ...editingTemplate,
                                            description:
                                                event
                                                    .target
                                                    .value,
                                        },
                                    )
                                }
                            />
                        </label>

                        <div className="admin-content-actions">
                            <Button
                                type="submit"
                                disabled={
                                    updateMutation
                                        .isPending
                                }
                            >
                                حفظ التعديل
                            </Button>

                            <Button
                                type="button"
                                variant="secondary"
                                disabled={
                                    updateMutation
                                        .isPending
                                }
                                onClick={() =>
                                    setEditingTemplate(
                                        null,
                                    )
                                }
                            >
                                إلغاء
                            </Button>
                        </div>
                    </form>
                ) : null}
            </div>

            {managingVersionsTemplate ? (
                <ExamTemplateVersionsPanel
                    version={version}
                    template={
                        managingVersionsTemplate
                    }
                    onClose={() =>
                        setManagingVersionsTemplateId(
                            null,
                        )
                    }
                />
            ) : null}
        </Surface>
    );
}

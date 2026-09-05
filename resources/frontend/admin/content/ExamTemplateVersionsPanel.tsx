import {
    FormEvent,
    useMemo,
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
    adminExamTemplatesKey,
    adminExamTemplateVersionsKey,
    createExamTemplateVersion,
    fetchExamTemplateVersions,
    publishExamTemplateVersion,
    retireExamTemplateVersion,
    updateExamTemplateVersion,
} from './api';

import type {
    CurriculumVersion,
    ExamTemplate,
    ExamTemplateVersion,
} from './types';

import type {
    PublishExamTemplateVersionResult,
} from './api';

interface ExamTemplateVersionsPanelProps {
    version: CurriculumVersion;
    template: ExamTemplate;
    onClose: () => void;
}

function requestId(error: unknown): string | null {
    return error instanceof EduCoreApiError
        ? error.requestId ?? null
        : null;
}

function VersionFailure({
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
                <strong>{children}</strong>
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
    item: ExamTemplateVersion,
    currentPublishedId: string | null,
) {
    if (
        item.status === 'published'
        && item.id === currentPublishedId
    ) {
        return 'المعتمدة حاليًا';
    }

    if (item.status === 'published') {
        return 'معتمدة سابقًا';
    }

    if (item.status === 'retired') {
        return 'متوقفة';
    }

    return 'مسودة';
}

export function ExamTemplateVersionsPanel({
    version,
    template,
    onClose,
}: ExamTemplateVersionsPanelProps) {
    const queryClient = useQueryClient();

    const canAuthor =
        version.status === 'draft'
        && template.status === 'active';

    const [label, setLabel] = useState('');
    const [editingVersion, setEditingVersion] =
        useState<ExamTemplateVersion | null>(null);
    const [editingLabel, setEditingLabel] =
        useState('');

    const versionsQuery = useQuery({
        queryKey: adminExamTemplateVersionsKey(
            template.id,
        ),
        queryFn: () =>
            fetchExamTemplateVersions(template.id),
    });

    const nextVersionNumber = useMemo(() => {
        const versions = versionsQuery.data ?? [];

        if (versions.length === 0) {
            return 1;
        }

        return Math.max(
            ...versions.map(
                (item) => item.version_number,
            ),
        ) + 1;
    }, [versionsQuery.data]);

    async function invalidate() {
        await Promise.all([
            queryClient.invalidateQueries({
                queryKey:
                    adminExamTemplateVersionsKey(
                        template.id,
                    ),
            }),
            queryClient.invalidateQueries({
                queryKey:
                    adminExamTemplatesKey(
                        version.id,
                    ),
            }),
        ]);
    }

    const createMutation = useMutation({
        mutationFn: () =>
            createExamTemplateVersion(
                template.id,
                {
                    version_number:
                        nextVersionNumber,
                    label:
                        label.trim() || null,
                    rules_payload: [],
                    rules_schema_version: 1,
                },
            ),
        onSuccess: async () => {
            setLabel('');
            await invalidate();
        },
    });

    const updateMutation = useMutation({
        mutationFn: ({
            item,
            newLabel,
        }: {
            item: ExamTemplateVersion;
            newLabel: string;
        }) =>
            updateExamTemplateVersion(
                item.id,
                {
                    label:
                        newLabel.trim() || null,
                    rules_payload:
                        item.rules_payload,
                    rules_schema_version:
                        item.rules_schema_version,
                },
            ),
        onSuccess: async () => {
            setEditingVersion(null);
            setEditingLabel('');
            await invalidate();
        },
    });

    const lifecycleMutation = useMutation<
        | PublishExamTemplateVersionResult
        | ExamTemplateVersion,
        Error,
        {
            versionId: string;
            action: 'publish' | 'retire';
        }
    >({
        mutationFn: ({ versionId, action }) =>
            action === 'publish'
                ? publishExamTemplateVersion(
                    versionId,
                )
                : retireExamTemplateVersion(
                    versionId,
                ),
        onSuccess: async () => {
            setEditingVersion(null);
            setEditingLabel('');
            await invalidate();
        },
    });

    function submitCreate(event: FormEvent) {
        event.preventDefault();

        if (
            !canAuthor
            || createMutation.isPending
        ) {
            return;
        }

        createMutation.mutate();
    }

    function beginEdit(item: ExamTemplateVersion) {
        if (
            !canAuthor
            || item.status !== 'draft'
        ) {
            return;
        }

        setEditingVersion(item);
        setEditingLabel(item.label ?? '');
    }

    function submitEdit(event: FormEvent) {
        event.preventDefault();

        if (
            !editingVersion
            || !canAuthor
            || editingVersion.status !== 'draft'
            || updateMutation.isPending
        ) {
            return;
        }

        updateMutation.mutate({
            item: editingVersion,
            newLabel: editingLabel,
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h3 className="foundation-card__title">
                            إعداد الاختبار — {template.name}
                        </h3>
                        <p className="foundation-page__description">
                            أنشئ إعدادات الاختبار واعتمدها عندما تصبح جاهزة للطلاب. يحتفظ النظام بالتاريخ السابق تلقائيًا.
                        </p>
                    </div>

                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={onClose}
                    >
                        إغلاق
                    </Button>
                </div>

                {!canAuthor ? (
                    <Feedback>
                        إعدادات الاختبار للقراءة فقط لأن الاختبار أو المنهج غير متاح للتعديل.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={submitCreate}
                    >
                        <label>
                            اسم الإعدادات
                            <input
                                aria-label="اسم إعدادات الاختبار"
                                maxLength={255}
                                placeholder="مثال: الإعدادات الأساسية"
                                value={label}
                                onChange={(event) =>
                                    setLabel(
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <Button
                            type="submit"
                            disabled={
                                createMutation.isPending
                            }
                        >
                            إنشاء إعدادات جديدة
                        </Button>
                    </form>
                )}

                {createMutation.isError ? (
                    <VersionFailure
                        error={createMutation.error}
                    >
                        تعذر إنشاء إعدادات الاختبار.
                    </VersionFailure>
                ) : null}

                {updateMutation.isError ? (
                    <VersionFailure
                        error={updateMutation.error}
                    >
                        تعذر تعديل إعدادات الاختبار.
                    </VersionFailure>
                ) : null}

                {lifecycleMutation.isError ? (
                    <VersionFailure
                        error={lifecycleMutation.error}
                    >
                        تعذر تغيير حالة إعدادات الاختبار.
                    </VersionFailure>
                ) : null}

                {versionsQuery.isPending ? (
                    <p>جار تحميل إعدادات الاختبار…</p>
                ) : versionsQuery.isError ? (
                    <VersionFailure
                        error={versionsQuery.error}
                    >
                        تعذر تحميل إعدادات الاختبار.
                    </VersionFailure>
                ) : versionsQuery.data.length === 0 ? (
                    <Feedback>
                        لم يتم إنشاء إعدادات لهذا الاختبار بعد.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {versionsQuery.data.map(
                            (item) => (
                                <article
                                    key={item.id}
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            {item.label
                                            ?? 'إعدادات الاختبار'}
                                        </strong>
                                        <p className="admin-content-list__meta">
                                            {statusLabel(
                                                item,
                                                template.published_version_id,
                                            )}
                                        </p>
                                    </div>

                                    <div className="admin-content-actions">
                                        {canAuthor
                                        && item.status === 'draft' ? (
                                            <>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={
                                                        updateMutation.isPending
                                                        || lifecycleMutation.isPending
                                                    }
                                                    onClick={() =>
                                                        beginEdit(item)
                                                    }
                                                >
                                                    تعديل الاسم
                                                </Button>

                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={
                                                        lifecycleMutation.isPending
                                                    }
                                                    onClick={() =>
                                                        lifecycleMutation.mutate({
                                                            versionId: item.id,
                                                            action: 'publish',
                                                        })
                                                    }
                                                >
                                                    اعتماد الإعدادات
                                                </Button>
                                            </>
                                        ) : null}

                                        {canAuthor
                                        && item.status === 'published'
                                        && template.published_version_id
                                            !== item.id ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                disabled={
                                                    lifecycleMutation.isPending
                                                }
                                                onClick={() =>
                                                    lifecycleMutation.mutate({
                                                        versionId: item.id,
                                                        action: 'retire',
                                                    })
                                                }
                                            >
                                                إيقاف الإعدادات السابقة
                                            </Button>
                                        ) : null}
                                    </div>
                                </article>
                            ),
                        )}
                    </div>
                )}

                {editingVersion ? (
                    <form
                        className="admin-content-form"
                        onSubmit={submitEdit}
                    >
                        <h3 className="foundation-card__title">
                            تعديل اسم الإعدادات
                        </h3>

                        <label>
                            الاسم
                            <input
                                aria-label="تعديل اسم إعدادات الاختبار"
                                maxLength={255}
                                value={editingLabel}
                                onChange={(event) =>
                                    setEditingLabel(
                                        event.target.value,
                                    )
                                }
                            />
                        </label>

                        <div className="admin-content-actions">
                            <Button
                                type="submit"
                                disabled={
                                    updateMutation.isPending
                                }
                            >
                                حفظ
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={
                                    updateMutation.isPending
                                }
                                onClick={() =>
                                    setEditingVersion(null)
                                }
                            >
                                إلغاء
                            </Button>
                        </div>
                    </form>
                ) : null}
            </div>
        </Surface>
    );
}

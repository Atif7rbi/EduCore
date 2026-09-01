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

function requestId(
    error: unknown,
): string | null {
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
    status:
        ExamTemplateVersion['status'],
) {
    if (status === 'published') {
        return 'منشور';
    }

    if (status === 'retired') {
        return 'متقاعد';
    }

    return 'مسودة';
}

function parseJsonPayload(
    value: string,
):
    | unknown[]
    | Record<string, unknown>
    | null {
    let parsed: unknown;

    try {
        parsed = JSON.parse(value);
    } catch {
        return null;
    }

    if (
        Array.isArray(parsed)
    ) {
        return parsed;
    }

    if (
        typeof parsed === 'object'
        && parsed !== null
    ) {
        return parsed as Record<
            string,
            unknown
        >;
    }

    return null;
}

export function ExamTemplateVersionsPanel({
    version,
    template,
    onClose,
}: ExamTemplateVersionsPanelProps) {
    const queryClient =
        useQueryClient();

    const canAuthor =
        version.status === 'draft'
        && template.status === 'active';

    const [
        versionNumber,
        setVersionNumber,
    ] = useState('1');

    const [
        label,
        setLabel,
    ] = useState('');

    const [
        rulesPayload,
        setRulesPayload,
    ] = useState('[]');

    const [
        rulesSchemaVersion,
        setRulesSchemaVersion,
    ] = useState('1');

    const [
        editingVersion,
        setEditingVersion,
    ] =
        useState<ExamTemplateVersion | null>(
            null,
        );

    const [
        editingLabel,
        setEditingLabel,
    ] = useState('');

    const [
        editingRulesPayload,
        setEditingRulesPayload,
    ] = useState('[]');

    const [
        editingRulesSchemaVersion,
        setEditingRulesSchemaVersion,
    ] = useState('1');

    const [
        localValidationError,
        setLocalValidationError,
    ] =
        useState<string | null>(
            null,
        );

    const versionsQuery =
        useQuery({
            queryKey:
                adminExamTemplateVersionsKey(
                    template.id,
                ),
            queryFn: () =>
                fetchExamTemplateVersions(
                    template.id,
                ),
        });

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

    const createMutation =
        useMutation({
            mutationFn: ({
                parsedVersionNumber,
                parsedRulesPayload,
                parsedRulesSchemaVersion,
            }: {
                parsedVersionNumber:
                    number;
                parsedRulesPayload:
                    | unknown[]
                    | Record<string, unknown>;
                parsedRulesSchemaVersion:
                    number;
            }) =>
                createExamTemplateVersion(
                    template.id,
                    {
                        version_number:
                            parsedVersionNumber,
                        label:
                            label.trim()
                            || null,
                        rules_payload:
                            parsedRulesPayload,
                        rules_schema_version:
                            parsedRulesSchemaVersion,
                    },
                ),
            onSuccess: async () => {
                setVersionNumber('1');
                setLabel('');
                setRulesPayload('[]');
                setRulesSchemaVersion(
                    '1',
                );
                setLocalValidationError(
                    null,
                );

                await invalidate();
            },
        });

    const updateMutation =
        useMutation({
            mutationFn: ({
                versionId,
                parsedRulesPayload,
                parsedRulesSchemaVersion,
            }: {
                versionId: string;
                parsedRulesPayload:
                    | unknown[]
                    | Record<string, unknown>;
                parsedRulesSchemaVersion:
                    number;
            }) =>
                updateExamTemplateVersion(
                    versionId,
                    {
                        label:
                            editingLabel
                                .trim()
                            || null,
                        rules_payload:
                            parsedRulesPayload,
                        rules_schema_version:
                            parsedRulesSchemaVersion,
                    },
                ),
            onSuccess: async () => {
                setEditingVersion(
                    null,
                );
                setLocalValidationError(
                    null,
                );

                await invalidate();
            },
        });

    const lifecycleMutation =
        useMutation<
            | PublishExamTemplateVersionResult
            | ExamTemplateVersion,
            Error,
            {
                versionId: string;
                action:
                    | 'publish'
                    | 'retire';
            }
        >({
            mutationFn: ({
                versionId,
                action,
            }) =>
                action === 'publish'
                    ? publishExamTemplateVersion(
                        versionId,
                    )
                    : retireExamTemplateVersion(
                        versionId,
                    ),
            onSuccess: async () => {
                setEditingVersion(
                    null,
                );
                setLocalValidationError(
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
            !canAuthor
            || createMutation.isPending
        ) {
            return;
        }

        const parsedVersionNumber =
            Number(versionNumber);

        const parsedRulesSchemaVersion =
            Number(
                rulesSchemaVersion,
            );

        const parsedRulesPayload =
            parseJsonPayload(
                rulesPayload,
            );

        if (
            !Number.isInteger(
                parsedVersionNumber,
            )
            || parsedVersionNumber < 1
        ) {
            setLocalValidationError(
                'رقم الإصدار يجب أن يكون عددًا صحيحًا يبدأ من 1.',
            );
            return;
        }

        if (
            !Number.isInteger(
                parsedRulesSchemaVersion,
            )
            || parsedRulesSchemaVersion < 1
        ) {
            setLocalValidationError(
                'إصدار مخطط القواعد يجب أن يكون عددًا صحيحًا يبدأ من 1.',
            );
            return;
        }

        if (
            parsedRulesPayload === null
        ) {
            setLocalValidationError(
                'rules_payload يجب أن يكون JSON array أو object صالحًا.',
            );
            return;
        }

        setLocalValidationError(
            null,
        );

        createMutation.mutate({
            parsedVersionNumber,
            parsedRulesPayload,
            parsedRulesSchemaVersion,
        });
    }

    function beginEdit(
        item: ExamTemplateVersion,
    ) {
        if (
            !canAuthor
            || item.status !== 'draft'
        ) {
            return;
        }

        setEditingVersion(item);
        setEditingLabel(
            item.label ?? '',
        );
        setEditingRulesPayload(
            JSON.stringify(
                item.rules_payload,
                null,
                2,
            ),
        );
        setEditingRulesSchemaVersion(
            String(
                item.rules_schema_version,
            ),
        );
        setLocalValidationError(
            null,
        );
    }

    function submitEdit(
        event: FormEvent,
    ) {
        event.preventDefault();

        if (
            !editingVersion
            || !canAuthor
            || editingVersion.status
                !== 'draft'
            || updateMutation.isPending
        ) {
            return;
        }

        const parsedRulesPayload =
            parseJsonPayload(
                editingRulesPayload,
            );

        const parsedRulesSchemaVersion =
            Number(
                editingRulesSchemaVersion,
            );

        if (
            parsedRulesPayload === null
        ) {
            setLocalValidationError(
                'rules_payload يجب أن يكون JSON array أو object صالحًا.',
            );
            return;
        }

        if (
            !Number.isInteger(
                parsedRulesSchemaVersion,
            )
            || parsedRulesSchemaVersion < 1
        ) {
            setLocalValidationError(
                'إصدار مخطط القواعد يجب أن يكون عددًا صحيحًا يبدأ من 1.',
            );
            return;
        }

        setLocalValidationError(
            null,
        );

        updateMutation.mutate({
            versionId:
                editingVersion.id,
            parsedRulesPayload,
            parsedRulesSchemaVersion,
        });
    }

    return (
        <Surface elevated>
            <div className="foundation-stack admin-content-panel">
                <div className="admin-content-revisions__heading">
                    <div>
                        <h3 className="foundation-card__title">
                            إصدارات قالب الاختبار — {
                                template.name
                            }
                        </h3>

                        <p className="foundation-page__description">
                            إدارة ExamTemplateVersion
                            وقواعده بصيغة JSON
                            schema-neutral.
                        </p>
                    </div>

                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        onClick={
                            onClose
                        }
                    >
                        إغلاق
                    </Button>
                </div>

                {!canAuthor ? (
                    <Feedback>
                        إنشاء وتعديل الإصدارات
                        متاح فقط لقالب active
                        داخل CurriculumVersion
                        draft.
                    </Feedback>
                ) : (
                    <form
                        className="admin-content-form"
                        onSubmit={
                            submitCreate
                        }
                    >
                        <label>
                            رقم الإصدار

                            <input
                                aria-label="رقم إصدار قالب الاختبار"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    versionNumber
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setVersionNumber(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            Label

                            <input
                                aria-label="تسمية إصدار قالب الاختبار"
                                maxLength={255}
                                value={label}
                                onChange={(
                                    event,
                                ) =>
                                    setLabel(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            rules_payload

                            <textarea
                                aria-label="قواعد إصدار قالب الاختبار"
                                rows={10}
                                value={
                                    rulesPayload
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setRulesPayload(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            rules_schema_version

                            <input
                                aria-label="إصدار مخطط قواعد قالب الاختبار"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    rulesSchemaVersion
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setRulesSchemaVersion(
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
                            إنشاء إصدار
                        </Button>
                    </form>
                )}

                {localValidationError ? (
                    <Feedback tone="danger">
                        {
                            localValidationError
                        }
                    </Feedback>
                ) : null}

                {createMutation.isError ? (
                    <VersionFailure
                        error={
                            createMutation
                                .error
                        }
                    >
                        تعذر إنشاء إصدار قالب الاختبار.
                    </VersionFailure>
                ) : null}

                {updateMutation.isError ? (
                    <VersionFailure
                        error={
                            updateMutation
                                .error
                        }
                    >
                        تعذر تعديل إصدار قالب الاختبار.
                    </VersionFailure>
                ) : null}

                {lifecycleMutation.isError ? (
                    <VersionFailure
                        error={
                            lifecycleMutation
                                .error
                        }
                    >
                        تعذر تغيير حالة إصدار قالب الاختبار.
                    </VersionFailure>
                ) : null}

                {versionsQuery.isPending ? (
                    <p>
                        جار تحميل الإصدارات…
                    </p>
                ) : versionsQuery.isError ? (
                    <VersionFailure
                        error={
                            versionsQuery
                                .error
                        }
                    >
                        تعذر تحميل إصدارات قالب الاختبار.
                    </VersionFailure>
                ) : versionsQuery.data
                    .length === 0 ? (
                    <Feedback>
                        لا توجد إصدارات لهذا
                        القالب.
                    </Feedback>
                ) : (
                    <div className="admin-content-list">
                        {versionsQuery.data.map(
                            (
                                item,
                            ) => (
                                <article
                                    key={
                                        item.id
                                    }
                                    className="admin-content-list__item"
                                >
                                    <div>
                                        <strong>
                                            إصدار {
                                                item.version_number
                                            }
                                            {
                                                item.label
                                                    ? ` — ${item.label}`
                                                    : ''
                                            }
                                        </strong>

                                        <p className="admin-content-list__meta">
                                            الحالة:{' '}
                                            {
                                                statusLabel(
                                                    item.status,
                                                )
                                            }
                                            {' · '}
                                            schema:{' '}
                                            {
                                                item.rules_schema_version
                                            }
                                            {
                                                template.published_version_id
                                                    === item.id
                                                    ? ' · Current'
                                                    : ''
                                            }
                                        </p>
                                    </div>

                                    <div className="admin-content-actions">
                                        {canAuthor
                                        && item.status
                                            === 'draft' ? (
                                            <>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={
                                                        updateMutation
                                                            .isPending
                                                        || lifecycleMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        beginEdit(
                                                            item,
                                                        )
                                                    }
                                                >
                                                    تعديل الإصدار
                                                </Button>

                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={
                                                        lifecycleMutation
                                                            .isPending
                                                    }
                                                    onClick={() =>
                                                        lifecycleMutation
                                                            .mutate({
                                                                versionId:
                                                                    item.id,
                                                                action:
                                                                    'publish',
                                                            })
                                                    }
                                                >
                                                    نشر الإصدار
                                                </Button>
                                            </>
                                        ) : null}

                                        {canAuthor
                                        && item.status
                                            === 'published'
                                        && template.published_version_id
                                            !== item.id ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                disabled={
                                                    lifecycleMutation
                                                        .isPending
                                                }
                                                onClick={() =>
                                                    lifecycleMutation
                                                        .mutate({
                                                            versionId:
                                                                item.id,
                                                            action:
                                                                'retire',
                                                        })
                                                }
                                            >
                                                تقاعد الإصدار
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
                        onSubmit={
                            submitEdit
                        }
                    >
                        <h3 className="foundation-card__title">
                            تعديل الإصدار {
                                editingVersion
                                    .version_number
                            }
                        </h3>

                        <label>
                            Label

                            <input
                                aria-label="تعديل تسمية إصدار قالب الاختبار"
                                maxLength={255}
                                value={
                                    editingLabel
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingLabel(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            rules_payload

                            <textarea
                                aria-label="تعديل قواعد إصدار قالب الاختبار"
                                rows={10}
                                value={
                                    editingRulesPayload
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingRulesPayload(
                                        event
                                            .target
                                            .value,
                                    )
                                }
                            />
                        </label>

                        <label>
                            rules_schema_version

                            <input
                                aria-label="تعديل إصدار مخطط قواعد قالب الاختبار"
                                type="number"
                                min="1"
                                step="1"
                                required
                                value={
                                    editingRulesSchemaVersion
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setEditingRulesSchemaVersion(
                                        event
                                            .target
                                            .value,
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
                                حفظ الإصدار
                            </Button>

                            <Button
                                type="button"
                                variant="secondary"
                                disabled={
                                    updateMutation
                                        .isPending
                                }
                                onClick={() => {
                                    setEditingVersion(
                                        null,
                                    );
                                    setLocalValidationError(
                                        null,
                                    );
                                }}
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

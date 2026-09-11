import {
    useEffect,
    useMemo,
    useState,
} from 'react';
import {
    useQuery,
} from '@tanstack/react-query';

import {
    apiRequest,
} from '../api/client';
import {
    EduCoreApiError,
} from '../api/errors';
import {
    Button,
    Feedback,
    Surface,
} from '../ui';

interface EvidenceScope {
    id: string;
    label: string | null;
    description: string | null;
    status:
        | 'active'
        | 'retired';
    definition_schema_version: number;
}

interface SkillAnalyticsItem {
    skill: {
        id: string;
        name: string;
        description: string | null;
    };
    single_primary: {
        correct_count: number;
        answered_count: number;
        accuracy: number | null;
    };
    supporting: {
        positive_count: number;
        exposure_count: number;
    };
    last_rebuilt_at: string | null;
}

interface SkillAnalytics {
    evidence_scope: {
        id: string;
        label: string | null;
        status:
            | 'active'
            | 'retired';
        definition_schema_version: number;
    };
    temporal_boundary: 'lifetime';
    skills: SkillAnalyticsItem[];
}

function evidenceScopesQueryKey() {
    return [
        'learner',
        'analytics',
        'evidence-scopes',
    ] as const;
}

function skillAnalyticsQueryKey(
    evidenceScopeId: string,
) {
    return [
        'learner',
        'analytics',
        'skills',
        evidenceScopeId,
    ] as const;
}

async function fetchEvidenceScopes():
Promise<EvidenceScope[]> {
    return apiRequest<EvidenceScope[]>({
        method: 'GET',
        url:
            '/api/analytics/evidence-scopes',
    });
}

async function fetchSkillAnalytics(
    evidenceScopeId: string,
): Promise<SkillAnalytics> {
    return apiRequest<SkillAnalytics>({
        method: 'GET',
        url:
            '/api/analytics/skills'
            + '?evidence_scope_id='
            + encodeURIComponent(
                evidenceScopeId,
            ),
    });
}

function ReadFailure({
    title,
    description,
    error,
    retry,
}: {
    title: string;
    description: string;
    error: unknown;
    retry: () => void;
}) {
    const apiError =
        error instanceof EduCoreApiError
            ? error
            : null;

    return (
        <Feedback tone="danger">
            <div className="learner-read-error">
                <div>
                    <strong>
                        {title}
                    </strong>

                    <p>
                        {description}
                    </p>

                    {apiError?.requestId ? (
                        <p className="learner-read-request-id">
                            رقم الطلب: {apiError.requestId}
                        </p>
                    ) : null}
                </div>

                <Button
                    size="sm"
                    variant="secondary"
                    onClick={retry}
                >
                    إعادة المحاولة
                </Button>
            </div>
        </Feedback>
    );
}

function scopeLabel(
    scope: EvidenceScope,
): string {
    return scope.label
        ?? 'نطاق تحليلي بدون اسم';
}

function accuracyLabel(
    accuracy: number | null,
): string {
    if (accuracy === null) {
        return 'لا توجد بيانات كافية';
    }

    return new Intl.NumberFormat(
        'ar-SA',
        {
            style: 'percent',
            maximumFractionDigits: 1,
        },
    ).format(accuracy);
}

export function SkillAnalyticsPanel() {
    const [
        selectedScopeId,
        setSelectedScopeId,
    ] = useState('');

    const scopesQuery = useQuery({
        queryKey:
            evidenceScopesQueryKey(),
        queryFn:
            fetchEvidenceScopes,
    });

    const scopes =
        useMemo(
            () =>
                scopesQuery.data
                ?? [],
            [
                scopesQuery.data,
            ],
        );

    useEffect(
        () => {
            if (
                selectedScopeId !== ''
                || scopes.length === 0
            ) {
                return;
            }

            const initialScope =
                scopes.find(
                    (scope) =>
                        scope.status
                        === 'active',
                )
                ?? scopes[0];

            if (initialScope) {
                setSelectedScopeId(
                    initialScope.id,
                );
            }
        },
        [
            scopes,
            selectedScopeId,
        ],
    );

    const analyticsQuery =
        useQuery({
            queryKey:
                skillAnalyticsQueryKey(
                    selectedScopeId,
                ),
            queryFn: () =>
                fetchSkillAnalytics(
                    selectedScopeId,
                ),
            enabled:
                selectedScopeId !== '',
        });

    if (scopesQuery.isPending) {
        return (
            <Surface>
                <div
                    className="learner-read-loading"
                    aria-busy="true"
                >
                    جار تحميل نطاقات التحليل…
                </div>
            </Surface>
        );
    }

    if (scopesQuery.isError) {
        return (
            <ReadFailure
                title="تعذر تحميل نطاقات التحليل."
                description="أعد المحاولة لاسترجاع النطاقات التحليلية المتاحة."
                error={
                    scopesQuery.error
                }
                retry={() => {
                    void scopesQuery.refetch();
                }}
            />
        );
    }

    if (scopes.length === 0) {
        return (
            <Surface>
                <div className="foundation-stack">
                    <h2 className="foundation-card__title">
                        تحليل المهارات
                    </h2>

                    <p className="foundation-page__description">
                        لا توجد نطاقات تحليلية متاحة حاليًا.
                    </p>
                </div>
            </Surface>
        );
    }

    const selectedScope =
        scopes.find(
            (scope) =>
                scope.id
                === selectedScopeId,
        )
        ?? null;

    return (
        <Surface elevated>
            <div className="foundation-stack">
                <div>
                    <h2 className="foundation-card__title">
                        تحليل المهارات
                    </h2>

                    <p className="foundation-page__description">
                        أداؤك حسب نطاق الأدلة التحليلية المحدد.
                    </p>
                </div>

                <label>
                    نطاق التحليل
                    <select
                        value={
                            selectedScopeId
                        }
                        onChange={(event) => {
                            setSelectedScopeId(
                                event
                                    .target
                                    .value,
                            );
                        }}
                    >
                        {scopes.map(
                            (scope) => (
                                <option
                                    key={
                                        scope.id
                                    }
                                    value={
                                        scope.id
                                    }
                                >
                                    {
                                        scopeLabel(
                                            scope,
                                        )
                                    }
                                    {' — '}
                                    {
                                        scope.status
                                        === 'active'
                                            ? 'نشط'
                                            : 'تاريخي'
                                    }
                                </option>
                            ),
                        )}
                    </select>
                </label>

                {selectedScope?.description ? (
                    <p className="foundation-page__description">
                        {
                            selectedScope
                                .description
                        }
                    </p>
                ) : null}

                {selectedScope?.status
                    === 'retired' ? (
                        <Feedback tone="info">
                            هذا نطاق تحليلي تاريخي ومتاح للقراءة.
                        </Feedback>
                    ) : null}

                {selectedScopeId !== ''
                && analyticsQuery.isPending ? (
                    <div
                        className="learner-read-loading"
                        aria-busy="true"
                    >
                        جار تحميل تحليل المهارات…
                    </div>
                ) : null}

                {analyticsQuery.isError ? (
                    <ReadFailure
                        title="تعذر تحميل تحليل المهارات."
                        description="أعد المحاولة لاسترجاع بيانات المهارات لهذا النطاق."
                        error={
                            analyticsQuery.error
                        }
                        retry={() => {
                            void analyticsQuery.refetch();
                        }}
                    />
                ) : null}

                {analyticsQuery.data
                && analyticsQuery.data.skills.length
                    === 0 ? (
                        <p className="foundation-page__description">
                            لا توجد بيانات مهارات ضمن هذا النطاق بعد.
                        </p>
                    ) : null}

                {analyticsQuery.data
                && analyticsQuery.data.skills.length
                    > 0 ? (
                        <div
                            className="foundation-stack"
                            aria-label="تحليل المهارات"
                        >
                            {analyticsQuery.data.skills.map(
                                (item) => (
                                    <Surface
                                        key={
                                            item
                                                .skill
                                                .id
                                        }
                                    >
                                        <div className="foundation-stack">
                                            <div>
                                                <h3 className="foundation-card__title">
                                                    {
                                                        item
                                                            .skill
                                                            .name
                                                    }
                                                </h3>

                                                {item
                                                    .skill
                                                    .description ? (
                                                        <p className="foundation-page__description">
                                                            {
                                                                item
                                                                    .skill
                                                                    .description
                                                            }
                                                        </p>
                                                    ) : null}
                                            </div>

                                            <div
                                                className="learner-results__summary"
                                                aria-label={
                                                    `أداء ${item.skill.name}`
                                                }
                                            >
                                                <div>
                                                    <strong>
                                                        {
                                                            item
                                                                .single_primary
                                                                .correct_count
                                                        }
                                                    </strong>
                                                    <span>
                                                        إجابات أساسية صحيحة
                                                    </span>
                                                </div>

                                                <div>
                                                    <strong>
                                                        {
                                                            item
                                                                .single_primary
                                                                .answered_count
                                                        }
                                                    </strong>
                                                    <span>
                                                        إجابات أساسية
                                                    </span>
                                                </div>

                                                <div>
                                                    <strong>
                                                        {
                                                            accuracyLabel(
                                                                item
                                                                    .single_primary
                                                                    .accuracy,
                                                            )
                                                        }
                                                    </strong>
                                                    <span>
                                                        دقة الإجابات الأساسية
                                                    </span>
                                                </div>

                                                <div>
                                                    <strong>
                                                        {
                                                            item
                                                                .supporting
                                                                .positive_count
                                                        }
                                                    </strong>
                                                    <span>
                                                        أدلة مساندة إيجابية
                                                    </span>
                                                </div>

                                                <div>
                                                    <strong>
                                                        {
                                                            item
                                                                .supporting
                                                                .exposure_count
                                                        }
                                                    </strong>
                                                    <span>
                                                        مرات التعرض المساند
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </Surface>
                                ),
                            )}
                        </div>
                    ) : null}
            </div>
        </Surface>
    );
}

import {
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    SkillAnalyticsPanel,
} from './SkillAnalyticsPanel';

interface TestRequest {
    method: string;
    url: string;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (
        config: TestRequest,
    ) => apiRequestMock(config),
}));

function renderPanel() {
    const client =
        new QueryClient({
            defaultOptions: {
                queries: {
                    retry: false,
                },
            },
        });

    render(
        <QueryClientProvider client={client}>
            <SkillAnalyticsPanel />
        </QueryClientProvider>,
    );
}

function scopesFixture() {
    return [
        {
            id: 'scope-active',
            label: 'النطاق الحالي',
            description:
                'الأدلة الحالية',
            status: 'active',
            definition_schema_version: 2,
        },
        {
            id: 'scope-retired',
            label: 'النطاق السابق',
            description:
                'أدلة تاريخية',
            status: 'retired',
            definition_schema_version: 1,
        },
    ];
}

describe(
    'SkillAnalyticsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'selects the first active scope and renders exact skill analytics',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve(
                                scopesFixture(),
                            );
                        }

                        if (
                            url
                            === '/api/analytics/skills?evidence_scope_id=scope-active'
                        ) {
                            return Promise.resolve({
                                evidence_scope: {
                                    id:
                                        'scope-active',
                                    label:
                                        'النطاق الحالي',
                                    status:
                                        'active',
                                    definition_schema_version:
                                        2,
                                },
                                temporal_boundary:
                                    'lifetime',
                                skills: [
                                    {
                                        skill: {
                                            id:
                                                'skill-1',
                                            name:
                                                'النسب',
                                            description:
                                                'مهارة النسب',
                                        },
                                        single_primary:
                                            {
                                                correct_count:
                                                    3,
                                                answered_count:
                                                    4,
                                                accuracy:
                                                    0.75,
                                            },
                                        supporting:
                                            {
                                                positive_count:
                                                    2,
                                                exposure_count:
                                                    5,
                                            },
                                        last_rebuilt_at:
                                            '2026-09-01T00:00:00.000000Z',
                                    },
                                ],
                            });
                        }

                        throw new Error(
                            `unexpected URL: ${url}`,
                        );
                    },
                );

                renderPanel();

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'النسب',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByLabelText(
                        'نطاق التحليل',
                    ),
                ).toHaveValue(
                    'scope-active',
                );

                const performance =
                    screen.getByLabelText(
                        'أداء النسب',
                    );

                expect(
                    performance,
                ).toHaveTextContent(
                    '3إجابات أساسية صحيحة',
                );

                expect(
                    performance,
                ).toHaveTextContent(
                    '4إجابات أساسية',
                );

                expect(
                    performance,
                ).toHaveTextContent(
                    /٧٥|75/,
                );

                expect(
                    performance,
                ).toHaveTextContent(
                    '2أدلة مساندة إيجابية',
                );

                expect(
                    performance,
                ).toHaveTextContent(
                    '5مرات التعرض المساند',
                );
            },
        );

        it(
            'uses null accuracy as insufficient data rather than zero percent',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve([
                                scopesFixture()[0],
                            ]);
                        }

                        return Promise.resolve({
                            evidence_scope: {
                                id:
                                    'scope-active',
                                label:
                                    'النطاق الحالي',
                                status:
                                    'active',
                                definition_schema_version:
                                    2,
                            },
                            temporal_boundary:
                                'lifetime',
                            skills: [
                                {
                                    skill: {
                                        id:
                                            'skill-2',
                                        name:
                                            'الاستدلال',
                                        description:
                                            null,
                                    },
                                    single_primary:
                                        {
                                            correct_count:
                                                0,
                                            answered_count:
                                                0,
                                            accuracy:
                                                null,
                                        },
                                    supporting:
                                        {
                                            positive_count:
                                                1,
                                            exposure_count:
                                                2,
                                        },
                                    last_rebuilt_at:
                                        null,
                                },
                            ],
                        });
                    },
                );

                renderPanel();

                expect(
                    await screen.findByText(
                        'لا توجد بيانات كافية',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByText(
                        /0%|٠٪/,
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );

        it(
            'switches to a retired historical scope and reloads analytics',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        url,
                    }: TestRequest) => {
                        if (
                            url
                            === '/api/analytics/evidence-scopes'
                        ) {
                            return Promise.resolve(
                                scopesFixture(),
                            );
                        }

                        if (
                            url.includes(
                                'scope-retired',
                            )
                        ) {
                            return Promise.resolve({
                                evidence_scope: {
                                    id:
                                        'scope-retired',
                                    label:
                                        'النطاق السابق',
                                    status:
                                        'retired',
                                    definition_schema_version:
                                        1,
                                },
                                temporal_boundary:
                                    'lifetime',
                                skills: [],
                            });
                        }

                        return Promise.resolve({
                            evidence_scope: {
                                id:
                                    'scope-active',
                                label:
                                    'النطاق الحالي',
                                status:
                                    'active',
                                definition_schema_version:
                                    2,
                            },
                            temporal_boundary:
                                'lifetime',
                            skills: [],
                        });
                    },
                );

                renderPanel();

                const selector =
                    await screen.findByLabelText(
                        'نطاق التحليل',
                    );

                await waitFor(() => {
                    expect(
                        selector,
                    ).toHaveValue(
                        'scope-active',
                    );
                });

                fireEvent.change(
                    selector,
                    {
                        target: {
                            value:
                                'scope-retired',
                        },
                    },
                );

                expect(
                    await screen.findByText(
                        'هذا نطاق تحليلي تاريخي ومتاح للقراءة.',
                    ),
                ).toBeInTheDocument();

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'GET',
                        url:
                            '/api/analytics/skills?evidence_scope_id=scope-retired',
                    });
                });
            },
        );

        it(
            'renders a clear empty state when no evidence scopes exist',
            async () => {
                apiRequestMock.mockResolvedValue(
                    [],
                );

                renderPanel();

                expect(
                    await screen.findByText(
                        'لا توجد نطاقات تحليلية متاحة حاليًا.',
                    ),
                ).toBeInTheDocument();

                expect(
                    apiRequestMock,
                ).toHaveBeenCalledTimes(1);
            },
        );
    },
);

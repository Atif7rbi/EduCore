import {
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
    TeacherOwnedContentInspection,
} from './TeacherOwnedContentInspection';

interface RequestConfig {
    method: string;
    url: string;
}

const apiRequestMock = vi.fn();

vi.mock('../../api/client', () => ({
    apiRequest: (
        config: RequestConfig,
    ) => apiRequestMock(config),
}));

function renderInspection() {
    const queryClient =
        new QueryClient({
            defaultOptions: {
                queries: {
                    retry: false,
                },
            },
        });

    render(
        <QueryClientProvider
            client={queryClient}
        >
            <TeacherOwnedContentInspection
                version={{
                    id: 'version-owned-1',
                    curriculum_id:
                        'curriculum-owned-1',
                    version_number: 1,
                    label: 'Owned',
                    status: 'draft',
                }}
            />
        </QueryClientProvider>,
    );
}

describe(
    'TeacherOwnedContentInspection',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();

            apiRequestMock.mockImplementation(
                ({
                    method,
                    url,
                }: RequestConfig) => {
                    expect(method)
                        .toBe('GET');

                    switch (url) {
                        case '/api/admin/curriculum-versions/version-owned-1/topics':
                            return Promise.resolve([
                                {
                                    id: 'topic-1',
                                    name: 'الجبر',
                                },
                            ]);

                        case '/api/admin/curriculum-versions/version-owned-1/lessons':
                            return Promise.resolve([
                                {
                                    id: 'lesson-1',
                                    title:
                                        'المعادلات',
                                    status:
                                        'published',
                                },
                            ]);

                        case '/api/admin/curriculum-versions/version-owned-1/assessment-items':
                            return Promise.resolve([
                                {
                                    id:
                                        'question-1',
                                    internal_label:
                                        'سؤال المعادلات',
                                    status:
                                        'published',
                                },
                            ]);

                        case '/api/admin/curriculum-versions/version-owned-1/practice-activities':
                            return Promise.resolve([
                                {
                                    id:
                                        'practice-1',
                                    name:
                                        'تدريب المعادلات',
                                    status:
                                        'active',
                                },
                            ]);

                        case '/api/admin/curriculum-versions/version-owned-1/exam-templates':
                            return Promise.resolve([
                                {
                                    id: 'exam-1',
                                    name:
                                        'اختبار المعادلات',
                                    status:
                                        'active',
                                },
                            ]);

                        case '/api/admin/curriculum-versions/version-owned-1/skill-placements':
                            return Promise.resolve([
                                {
                                    id:
                                        'placement-1',
                                },
                            ]);

                        default:
                            throw new Error(
                                `Unexpected request ${method} ${url}`,
                            );
                    }
                },
            );
        });

        it(
            'inspects teacher-owned content through GET-only contracts',
            async () => {
                renderInspection();

                expect(
                    await screen.findByText(
                        'المعادلات',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /سؤال المعادلات/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /تدريب المعادلات/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /اختبار المعادلات/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText('1'),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                    ),
                ).not.toBeInTheDocument();

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledTimes(
                        6,
                    );
                });

                for (
                    const [config]
                    of apiRequestMock.mock.calls
                ) {
                    expect(
                        config.method,
                    ).toBe('GET');
                }
            },
        );
    },
);

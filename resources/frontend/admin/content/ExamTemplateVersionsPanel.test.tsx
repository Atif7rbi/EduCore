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
    ExamTemplateVersionsPanel,
} from './ExamTemplateVersionsPanel';

import type {
    CurriculumVersion,
    ExamTemplate,
} from './types';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../../api/client', () => ({
    apiRequest: (
        config: RequestConfig,
    ) => apiRequestMock(config),
}));

const curriculumVersion:
CurriculumVersion = {
    id: 'version-1',
    curriculum_id:
        'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const template:
ExamTemplate = {
    id: 'template-1',
    curriculum_version_id:
        'version-1',
    name: 'اختبار تجريبي',
    description: null,
    status: 'active',
    published_version_id:
        null,
    versions_count: 1,
    created_at: null,
    updated_at: null,
};

const draftTemplateVersion = {
    id: 'template-version-1',
    exam_template_id:
        'template-1',
    curriculum_version_id:
        'version-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
    rules_payload: [],
    rules_schema_version: 1,
    created_at: null,
    updated_at: null,
};

function renderPanel(
    currentTemplate:
        ExamTemplate =
            template,
    currentVersion:
        CurriculumVersion =
            curriculumVersion,
) {
    const client =
        new QueryClient({
            defaultOptions: {
                queries: {
                    retry: false,
                },
                mutations: {
                    retry: false,
                },
            },
        });

    render(
        <QueryClientProvider client={client}>
            <ExamTemplateVersionsPanel
                version={
                    currentVersion
                }
                template={
                    currentTemplate
                }
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe(
    'ExamTemplateVersionsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists versions in backend order',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([
                                draftTemplateVersion,
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                expect(
                    await screen.findByText(
                        'إصدار 1 — الإصدار الأول',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /الحالة: مسودة/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates a draft version with schema-neutral rules payload',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                        data,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                version_number:
                                    2,
                                label:
                                    'الإصدار الثاني',
                                rules_payload: {
                                    mode:
                                        'adaptive',
                                },
                                rules_schema_version:
                                    1,
                            });

                            return Promise.resolve({
                                ...draftTemplateVersion,
                                id:
                                    'template-version-2',
                                version_number:
                                    2,
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'لا توجد إصدارات لهذا القالب.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'رقم إصدار قالب الاختبار',
                    ),
                    {
                        target: {
                            value: '2',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تسمية إصدار قالب الاختبار',
                    ),
                    {
                        target: {
                            value:
                                'الإصدار الثاني',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'قواعد إصدار قالب الاختبار',
                    ),
                    {
                        target: {
                            value:
                                '{"mode":"adaptive"}',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنشاء إصدار',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/admin/exam-templates/template-1/versions',
                        data: {
                            version_number:
                                2,
                            label:
                                'الإصدار الثاني',
                            rules_payload: {
                                mode:
                                    'adaptive',
                            },
                            rules_schema_version:
                                1,
                        },
                    });
                });
            },
        );

        it(
            'updates only mutable draft-version fields and never version number',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                        data,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([
                                draftTemplateVersion,
                            ]);
                        }

                        if (
                            method === 'PUT'
                            && url
                                === '/api/admin/exam-template-versions/template-version-1'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                label:
                                    'نسخة معدلة',
                                rules_payload: [],
                                rules_schema_version:
                                    1,
                            });

                            expect(
                                data,
                            ).not.toHaveProperty(
                                'version_number',
                            );

                            return Promise.resolve({
                                ...draftTemplateVersion,
                                label:
                                    'نسخة معدلة',
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'إصدار 1 — الإصدار الأول',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'تعديل الإصدار',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل تسمية إصدار قالب الاختبار',
                    ),
                    {
                        target: {
                            value:
                                'نسخة معدلة',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'حفظ الإصدار',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'PUT',
                        url:
                            '/api/admin/exam-template-versions/template-version-1',
                        data: {
                            label:
                                'نسخة معدلة',
                            rules_payload:
                                [],
                            rules_schema_version:
                                1,
                        },
                    });
                });
            },
        );

        it(
            'does not expose version editing outside the exact draft authoring boundary',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([
                                draftTemplateVersion,
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel({
                    ...template,
                    status:
                        'archived',
                });

                expect(
                    await screen.findByText(
                        'إنشاء وتعديل الإصدارات متاح فقط لقالب active داخل CurriculumVersion draft.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إنشاء إصدار',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'تعديل الإصدار',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );

        it(
            'publishes a draft version and refreshes the current published pointer',
            async () => {
                let published = false;

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    ...draftTemplateVersion,
                                    status:
                                        published
                                            ? 'published'
                                            : 'draft',
                                },
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/exam-template-versions/template-version-1/publish'
                        ) {
                            published = true;

                            return Promise.resolve({
                                version: {
                                    ...draftTemplateVersion,
                                    status:
                                        'published',
                                },
                                template: {
                                    ...template,
                                    published_version_id:
                                        'template-version-1',
                                },
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'إصدار 1 — الإصدار الأول',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'نشر الإصدار',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/admin/exam-template-versions/template-version-1/publish',
                    });
                });
            },
        );

        it(
            'allows retiring only a published version that is no longer current',
            async () => {
                let retired = false;

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    ...draftTemplateVersion,
                                    status:
                                        retired
                                            ? 'retired'
                                            : 'published',
                                },
                                {
                                    ...draftTemplateVersion,
                                    id:
                                        'template-version-2',
                                    version_number:
                                        2,
                                    label:
                                        'الإصدار الثاني',
                                    status:
                                        'published',
                                },
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/exam-template-versions/template-version-1/retire'
                        ) {
                            retired = true;

                            return Promise.resolve({
                                ...draftTemplateVersion,
                                status:
                                    'retired',
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel({
                    ...template,
                    published_version_id:
                        'template-version-2',
                });

                await screen.findByText(
                    'إصدار 1 — الإصدار الأول',
                );

                const retireButtons =
                    screen.getAllByRole(
                        'button',
                        {
                            name:
                                'تقاعد الإصدار',
                        },
                    );

                expect(
                    retireButtons,
                ).toHaveLength(1);

                fireEvent.click(
                    retireButtons[0],
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/admin/exam-template-versions/template-version-1/retire',
                    });
                });

                expect(
                    await screen.findByText(
                        /الحالة: متقاعد/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'never exposes retire for the current published version',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    ...draftTemplateVersion,
                                    status:
                                        'published',
                                },
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel({
                    ...template,
                    published_version_id:
                        'template-version-1',
                });

                expect(
                    await screen.findByText(
                        /Current/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'تقاعد الإصدار',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'نشر الإصدار',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );

        it(
            'freezes version lifecycle when the template or curriculum version is not authorable',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/exam-templates/template-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    ...draftTemplateVersion,
                                    status:
                                        'published',
                                },
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel(
                    {
                        ...template,
                        status:
                            'archived',
                        published_version_id:
                            null,
                    },
                );

                await screen.findByText(
                    'إنشاء وتعديل الإصدارات متاح فقط لقالب active داخل CurriculumVersion draft.',
                );

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'نشر الإصدار',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'تقاعد الإصدار',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );

    },
);

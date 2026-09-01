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
    ExamTemplatesPanel,
} from './ExamTemplatesPanel';

import type {
    CurriculumVersion,
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

const draftVersion:
CurriculumVersion = {
    id: 'version-1',
    curriculum_id:
        'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const activeTemplate = {
    id: 'template-1',
    curriculum_version_id:
        'version-1',
    name: 'اختبار تجريبي',
    description:
        'قالب أساسي',
    status: 'active',
    published_version_id:
        null,
    versions_count: 2,
    created_at: null,
    updated_at: null,
};

function renderPanel(
    version:
        CurriculumVersion =
            draftVersion,
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
            <ExamTemplatesPanel
                version={version}
            />
        </QueryClientProvider>,
    );
}

describe(
    'ExamTemplatesPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists exam templates for the selected curriculum version',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/exam-templates'
                        ) {
                            return Promise.resolve([
                                activeTemplate,
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
                        'اختبار تجريبي',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /الحالة: نشط/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /الإصدارات: 2/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates an active exam template using the exact backend payload',
            async () => {
                let created = false;

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                        data,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/exam-templates'
                        ) {
                            return Promise.resolve(
                                created
                                    ? [
                                        activeTemplate,
                                    ]
                                    : [],
                            );
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/curriculum-versions/version-1/exam-templates'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                name:
                                    'اختبار تجريبي',
                                description:
                                    'قالب أساسي',
                            });

                            created = true;

                            return Promise.resolve(
                                activeTemplate,
                            );
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'لا توجد قوالب اختبارات لهذا الإصدار.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'اسم قالب الاختبار',
                    ),
                    {
                        target: {
                            value:
                                'اختبار تجريبي',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'وصف قالب الاختبار',
                    ),
                    {
                        target: {
                            value:
                                'قالب أساسي',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنشاء قالب اختبار',
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
                            '/api/admin/curriculum-versions/version-1/exam-templates',
                        data: {
                            name:
                                'اختبار تجريبي',
                            description:
                                'قالب أساسي',
                        },
                    });
                });
            },
        );

        it(
            'updates metadata only for an active template',
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
                                === '/api/admin/curriculum-versions/version-1/exam-templates'
                        ) {
                            return Promise.resolve([
                                activeTemplate,
                            ]);
                        }

                        if (
                            method === 'PUT'
                            && url
                                === '/api/admin/exam-templates/template-1'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                name:
                                    'اختبار محدّث',
                                description:
                                    'قالب أساسي',
                            });

                            return Promise.resolve({
                                ...activeTemplate,
                                name:
                                    'اختبار محدّث',
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'اختبار تجريبي',
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'تعديل',
                        },
                    ),
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'تعديل اسم قالب الاختبار',
                    ),
                    {
                        target: {
                            value:
                                'اختبار محدّث',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'حفظ التعديل',
                        },
                    ),
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'PUT',
                        url:
                            '/api/admin/exam-templates/template-1',
                        data: {
                            name:
                                'اختبار محدّث',
                            description:
                                'قالب أساسي',
                        },
                    });
                });
            },
        );

        it(
            'archives and reactivates templates using backend lifecycle routes',
            async () => {
                let active = true;

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/exam-templates'
                        ) {
                            return Promise.resolve([
                                {
                                    ...activeTemplate,
                                    status:
                                        active
                                            ? 'active'
                                            : 'archived',
                                },
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/exam-templates/template-1/archive'
                        ) {
                            active = false;

                            return Promise.resolve({
                                ...activeTemplate,
                                status:
                                    'archived',
                            });
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/exam-templates/template-1/activate'
                        ) {
                            active = true;

                            return Promise.resolve({
                                ...activeTemplate,
                                status:
                                    'active',
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    /الحالة: نشط/,
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'أرشفة',
                        },
                    ),
                );

                expect(
                    await screen.findByText(
                        /الحالة: مؤرشف/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'تعديل',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'تفعيل',
                        },
                    ),
                );

                expect(
                    await screen.findByText(
                        /الحالة: نشط/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'keeps template authoring read only outside draft curriculum versions',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/exam-templates'
                        ) {
                            return Promise.resolve([
                                activeTemplate,
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel({
                    ...draftVersion,
                    status:
                        'published',
                });

                expect(
                    await screen.findByText(
                        'Exam Templates للقراءة فقط لأن CurriculumVersion ليست draft.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إنشاء قالب اختبار',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'تعديل',
                        },
                    ),
                ).not
                    .toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'أرشفة',
                        },
                    ),
                ).not
                    .toBeInTheDocument();
            },
        );
    },
);

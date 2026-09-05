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
    apiRequest: (config: RequestConfig) =>
        apiRequestMock(config),
}));

const curriculumVersion: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const template: ExamTemplate = {
    id: 'template-1',
    curriculum_version_id: 'version-1',
    name: 'اختبار تجريبي',
    description: null,
    status: 'active',
    published_version_id: null,
    versions_count: 1,
    created_at: null,
    updated_at: null,
};

const draftSettings = {
    id: 'template-version-1',
    exam_template_id: 'template-1',
    curriculum_version_id: 'version-1',
    version_number: 1,
    label: 'الإعدادات الأساسية',
    status: 'draft',
    rules_payload: [],
    rules_schema_version: 1,
    created_at: null,
    updated_at: null,
};

function renderPanel(
    currentTemplate: ExamTemplate = template,
    currentVersion: CurriculumVersion =
        curriculumVersion,
) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <ExamTemplateVersionsPanel
                version={currentVersion}
                template={currentTemplate}
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

function installListMock(items: unknown[]) {
    apiRequestMock.mockImplementation(
        ({ method, url }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/exam-templates/template-1/versions'
            ) {
                return Promise.resolve(items);
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe('ExamTemplateVersionsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('shows exam settings without schema, JSON, or version-number controls', async () => {
        installListMock([draftSettings]);
        renderPanel();

        expect(
            await screen.findByText(
                'الإعدادات الأساسية',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('مسودة'),
        ).toBeInTheDocument();
        expect(
            screen.queryByLabelText(
                'رقم إصدار قالب الاختبار',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText(
                'قواعد إصدار قالب الاختبار',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/schema/i),
        ).not.toBeInTheDocument();
    });

    it('creates the next internal settings version automatically', async () => {
        apiRequestMock.mockImplementation(
            ({ method, url, data }: RequestConfig) => {
                if (
                    method === 'GET'
                    && url === '/api/admin/exam-templates/template-1/versions'
                ) {
                    return Promise.resolve([
                        draftSettings,
                        {
                            ...draftSettings,
                            id: 'template-version-2',
                            version_number: 2,
                        },
                    ]);
                }

                if (
                    method === 'POST'
                    && url === '/api/admin/exam-templates/template-1/versions'
                ) {
                    expect(data).toEqual({
                        version_number: 3,
                        label: 'إعدادات جديدة',
                        rules_payload: [],
                        rules_schema_version: 1,
                    });

                    return Promise.resolve({
                        ...draftSettings,
                        id: 'template-version-3',
                        version_number: 3,
                    });
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPanel();

        await screen.findByText(
            'الإعدادات الأساسية',
        );
        fireEvent.change(
            screen.getByLabelText(
                'اسم إعدادات الاختبار',
            ),
            {
                target: {
                    value: 'إعدادات جديدة',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'إنشاء إعدادات جديدة',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/exam-templates/template-1/versions',
                data: {
                    version_number: 3,
                    label: 'إعدادات جديدة',
                    rules_payload: [],
                    rules_schema_version: 1,
                },
            });
        });
    });

    it('edits only the visible name while preserving hidden backend rules', async () => {
        apiRequestMock.mockImplementation(
            ({ method, url, data }: RequestConfig) => {
                if (
                    method === 'GET'
                    && url === '/api/admin/exam-templates/template-1/versions'
                ) {
                    return Promise.resolve([
                        {
                            ...draftSettings,
                            rules_payload: {
                                mode: 'fixed',
                            },
                            rules_schema_version: 4,
                        },
                    ]);
                }

                if (
                    method === 'PUT'
                    && url === '/api/admin/exam-template-versions/template-version-1'
                ) {
                    expect(data).toEqual({
                        label: 'اسم محدث',
                        rules_payload: {
                            mode: 'fixed',
                        },
                        rules_schema_version: 4,
                    });

                    return Promise.resolve({
                        ...draftSettings,
                        label: 'اسم محدث',
                    });
                }

                throw new Error(
                    `Unexpected request ${method} ${url}`,
                );
            },
        );

        renderPanel();
        await screen.findByText(
            'الإعدادات الأساسية',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'تعديل الاسم',
            }),
        );
        fireEvent.change(
            screen.getByLabelText(
                'تعديل اسم إعدادات الاختبار',
            ),
            {
                target: { value: 'اسم محدث' },
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'حفظ',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'PUT',
                url: '/api/admin/exam-template-versions/template-version-1',
                data: {
                    label: 'اسم محدث',
                    rules_payload: {
                        mode: 'fixed',
                    },
                    rules_schema_version: 4,
                },
            });
        });
    });

    it('publishes settings using user-facing approval wording', async () => {
        apiRequestMock.mockImplementation(
            ({ method, url }: RequestConfig) => {
                if (
                    method === 'GET'
                    && url === '/api/admin/exam-templates/template-1/versions'
                ) {
                    return Promise.resolve([
                        draftSettings,
                    ]);
                }

                if (
                    method === 'POST'
                    && url === '/api/admin/exam-template-versions/template-version-1/publish'
                ) {
                    return Promise.resolve({
                        version: {
                            ...draftSettings,
                            status: 'published',
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
            'الإعدادات الأساسية',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'اعتماد الإعدادات',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/exam-template-versions/template-version-1/publish',
            });
        });
    });

    it('marks the current approved settings without exposing Current or version internals', async () => {
        installListMock([
            {
                ...draftSettings,
                status: 'published',
            },
        ]);

        renderPanel({
            ...template,
            published_version_id:
                'template-version-1',
        });

        expect(
            await screen.findByText(
                'المعتمدة حاليًا',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Current'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'إيقاف الإعدادات السابقة',
            }),
        ).not.toBeInTheDocument();
    });

    it('keeps settings read only outside the authoring boundary', async () => {
        installListMock([draftSettings]);

        renderPanel({
            ...template,
            status: 'archived',
        });

        expect(
            await screen.findByText(
                'إعدادات الاختبار للقراءة فقط لأن الاختبار أو المنهج غير متاح للتعديل.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'إنشاء إعدادات جديدة',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'تعديل الاسم',
            }),
        ).not.toBeInTheDocument();
    });
});

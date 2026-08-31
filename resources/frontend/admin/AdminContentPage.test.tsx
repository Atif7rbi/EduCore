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
    AdminContentPage,
} from './AdminContentPage';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (
        config: RequestConfig,
    ) => apiRequestMock(config),
}));

vi.mock('./content/TopicsPanel', () => ({
    TopicsPanel: () => (
        <div data-testid="topics-panel">
            Topics panel
        </div>
    ),
}));

vi.mock('./content/SkillsPanel', () => ({
    SkillsPanel: () => (
        <div data-testid="skills-panel">
            Skills panel
        </div>
    ),
}));

vi.mock('./content/SkillPlacementsPanel', () => ({
    SkillPlacementsPanel: ({
        version,
    }: {
        version: {
            status:
                | 'draft'
                | 'published'
                | 'retired';
        };
    }) => (
        <div
            data-testid="placements-panel"
            data-version-status={
                version.status
            }
        >
            Skill placements panel
        </div>
    ),
}));

vi.mock('./content/LessonsPanel', () => ({
    LessonsPanel: () => (
        <div data-testid="lessons-panel">
            Lessons panel
        </div>
    ),
}));

function renderPage() {
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
            <AdminContentPage />
        </QueryClientProvider>,
    );
}

function installDraftContext() {
    apiRequestMock.mockImplementation(
        ({
            method,
            url,
        }: RequestConfig) => {
            if (
                method === 'GET'
                && url
                    === '/api/admin/subjects'
            ) {
                return Promise.resolve([
                    {
                        id: 'subject-1',
                        name: 'القدرات الكمية',
                        created_at: null,
                        updated_at: null,
                    },
                ]);
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/subjects/subject-1/curricula'
            ) {
                return Promise.resolve([
                    {
                        id: 'curriculum-1',
                        subject_id:
                            'subject-1',
                        name: 'المنهج الكمي',
                        created_at: null,
                        updated_at: null,
                    },
                ]);
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/curricula/curriculum-1/versions'
            ) {
                return Promise.resolve([
                    {
                        id: 'version-1',
                        curriculum_id:
                            'curriculum-1',
                        version_number: 1,
                        label: 'الإصدار الأول',
                        status: 'draft',
                    },
                ]);
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe(
    'AdminContentPage',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'resolves the default subject curriculum and curriculum version context',
            async () => {
                installDraftContext();

                renderPage();

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'المحتوى والتصنيف',
                        },
                    ),
                ).toBeInTheDocument();

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'GET',
                        url:
                            '/api/admin/subjects/subject-1/curricula',
                    });
                });

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'GET',
                        url:
                            '/api/admin/curricula/curriculum-1/versions',
                    });
                });

                expect(
                    await screen.findByText(
                        'هذه النسخة مسودة ويمكن تعديل محتواها.',
                    ),
                ).toBeInTheDocument();

                expect(
                    await screen.findByTestId(
                        'placements-panel',
                    ),
                ).toHaveAttribute(
                    'data-version-status',
                    'draft',
                );
            },
        );

        it(
            'resets curriculum and version selection when subject changes',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'subject-1',
                                    name:
                                        'القدرات الكمية',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                                {
                                    id:
                                        'subject-2',
                                    name:
                                        'القدرات اللفظية',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects/subject-1/curricula'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'curriculum-1',
                                    subject_id:
                                        'subject-1',
                                    name:
                                        'المنهج الكمي',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curricula/curriculum-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'version-1',
                                    curriculum_id:
                                        'curriculum-1',
                                    version_number:
                                        1,
                                    label:
                                        'الإصدار الأول',
                                    status:
                                        'draft',
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects/subject-2/curricula'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'curriculum-2',
                                    subject_id:
                                        'subject-2',
                                    name:
                                        'المنهج اللفظي',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curricula/curriculum-2/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'version-2',
                                    curriculum_id:
                                        'curriculum-2',
                                    version_number:
                                        1,
                                    label:
                                        'الإصدار اللفظي',
                                    status:
                                        'draft',
                                },
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPage();

                await screen.findByText(
                    'هذه النسخة مسودة ويمكن تعديل محتواها.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'المادة',
                    ),
                    {
                        target: {
                            value:
                                'subject-2',
                        },
                    },
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'GET',
                        url:
                            '/api/admin/subjects/subject-2/curricula',
                    });
                });

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method: 'GET',
                        url:
                            '/api/admin/curricula/curriculum-2/versions',
                    });
                });

                expect(
                    await screen.findByDisplayValue(
                        'الإصدار اللفظي — الإصدار 1 — مسودة',
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'marks a published curriculum version as read only',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'subject-1',
                                    name:
                                        'القدرات الكمية',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects/subject-1/curricula'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'curriculum-1',
                                    subject_id:
                                        'subject-1',
                                    name:
                                        'المنهج الكمي',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curricula/curriculum-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'version-1',
                                    curriculum_id:
                                        'curriculum-1',
                                    version_number:
                                        1,
                                    label:
                                        'الإصدار الأول',
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

                renderPage();

                expect(
                    await screen.findByText(
                        'هذه النسخة للقراءة فقط؛ عمليات التأليف مجمدة.',
                    ),
                ).toBeInTheDocument();

                expect(
                    await screen.findByTestId(
                        'placements-panel',
                    ),
                ).toHaveAttribute(
                    'data-version-status',
                    'published',
                );
            },
        );

        it(
            'marks a retired curriculum version as read only',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'subject-1',
                                    name:
                                        'القدرات الكمية',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/subjects/subject-1/curricula'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'curriculum-1',
                                    subject_id:
                                        'subject-1',
                                    name:
                                        'المنهج الكمي',
                                    created_at:
                                        null,
                                    updated_at:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curricula/curriculum-1/versions'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'version-1',
                                    curriculum_id:
                                        'curriculum-1',
                                    version_number:
                                        1,
                                    label:
                                        'إصدار قديم',
                                    status:
                                        'retired',
                                },
                            ]);
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPage();

                expect(
                    await screen.findByText(
                        'هذه النسخة للقراءة فقط؛ عمليات التأليف مجمدة.',
                    ),
                ).toBeInTheDocument();
            },
        );
    },
);

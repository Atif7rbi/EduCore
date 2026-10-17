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
    EduCoreApiError,
} from '../api/errors';
import {
    TeacherSkillPlacementsPanel,
} from './TeacherSkillPlacementsPanel';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

const context = {
    assignmentId: 'assignment-a',
    curriculumId: 'curriculum-a',
    curriculumVersionId: 'version-a',
    versionStatus: 'draft' as const,
};

function renderPanel(
    versionStatus: 'draft' | 'published' | 'retired' = 'draft',
    onLifecycleConflict: () => Promise<void> =
        async () => {},
) {
    const client = new QueryClient({
        defaultOptions: {
            mutations: {
                retry: false,
            },
            queries: {
                retry: false,
            },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <TeacherSkillPlacementsPanel
                authenticatedUserId="teacher-a"
                context={{
                    ...context,
                    versionStatus,
                }}
                onContextUnavailable={() => {}}
                onDirtyChange={() => {}}
                onLifecycleConflict={onLifecycleConflict}
            />
        </QueryClientProvider>,
    );
}

function readResponse({
    method,
    url,
}: RequestConfig) {
    if (method === 'GET' && url.endsWith('/skill-placements')) {
        return Promise.resolve([
            {
                curriculum_version_id: 'version-a',
                home_topics: [],
                id: 'placement-a',
                skill: {
                    id: 'skill-a',
                    name: 'النسب',
                },
                skill_id: 'skill-a',
            },
            {
                curriculum_version_id: 'foreign-version',
                home_topics: [],
                id: 'placement-foreign',
                skill: {
                    id: 'skill-foreign',
                    name: 'مخفي',
                },
                skill_id: 'skill-foreign',
            },
        ]);
    }

    if (method === 'GET' && url.endsWith('/topics')) {
        return Promise.resolve([
            {
                curriculum_version_id: 'version-a',
                display_order: 1,
                id: 'topic-a',
                name: 'موضوع رئيس',
            },
            {
                curriculum_version_id: 'foreign-version',
                display_order: 2,
                id: 'topic-foreign',
                name: 'موضوع مخفي',
            },
        ]);
    }

    if (method === 'GET' && url === '/api/teacher/skills') {
        return Promise.resolve([
            {
                description: null,
                id: 'skill-a',
                name: 'النسب',
            },
            {
                description: null,
                id: 'skill-b',
                name: 'الكسور',
            },
        ]);
    }

    throw new Error(
        'Unexpected request '
        + method
        + ' '
        + url,
    );
}

describe('TeacherSkillPlacementsPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
        vi.spyOn(window, 'confirm').mockReturnValue(true);
    });

    it('uses exact-version resources and adds a home topic', async () => {
        apiRequestMock.mockImplementation(
            (config: RequestConfig) => {
                if (
                    config.method === 'POST'
                    && config.url.endsWith('/home-topics')
                ) {
                    return Promise.resolve({
                        id: 'home-a',
                    });
                }

                return readResponse(config);
            },
        );

        renderPanel();

        expect(
            await screen.findByText('النسب'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('مخفي'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('موضوع مخفي'),
        ).not.toBeInTheDocument();

        fireEvent.change(
            screen.getByLabelText(
                'موضوع رئيس للمهارة النسب',
            ),
            {
                target: {
                    value: 'topic-a',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'إضافة موضوع رئيس',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: {
                        topic_id: 'topic-a',
                    },
                    method: 'POST',
                    url:
                        '/api/teacher/subject-assignments/'
                        + 'assignment-a/curricula/curriculum-a/'
                        + 'versions/version-a/skill-placements/'
                        + 'placement-a/home-topics',
                }),
            );
        });
    });

    it('adds an unplaced catalog skill only in draft state', async () => {
        apiRequestMock.mockImplementation(readResponse);

        renderPanel();

        await screen.findByText('النسب');
        fireEvent.change(
            screen.getByLabelText('إضافة مهارة'),
            {
                target: {
                    value: 'skill-b',
                },
            },
        );
        apiRequestMock.mockImplementation(
            (config: RequestConfig) => {
                if (
                    config.method === 'POST'
                    && config.url.endsWith('/skill-placements')
                ) {
                    return Promise.resolve({
                        curriculum_version_id: 'version-a',
                        home_topics: [],
                        id: 'placement-b',
                        skill: {
                            id: 'skill-b',
                            name: 'الكسور',
                        },
                        skill_id: 'skill-b',
                    });
                }

                return readResponse(config);
            },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'إضافة مهارة',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: {
                        skill_id: 'skill-b',
                    },
                    method: 'POST',
                    url:
                        '/api/teacher/subject-assignments/'
                        + 'assignment-a/curricula/curriculum-a/'
                        + 'versions/version-a/skill-placements',
                }),
            );
        });
    });

    it('keeps published placements read only', async () => {
        apiRequestMock.mockImplementation(readResponse);

        renderPanel('published');

        expect(
            await screen.findByText('هذا الإصدار للقراءة فقط.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'إضافة مهارة',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'إضافة موضوع رئيس',
            }),
        ).not.toBeInTheDocument();
    });

    it('preserves the placement-in-use conflict and refreshes', async () => {
        const conflict = new EduCoreApiError({
            code: 'skill_placement_in_use',
            message: 'لا يمكن إزالة المهارة لأنها مستخدمة.',
            requestId: 'request-b',
            status: 409,
        });
        apiRequestMock.mockImplementation(
            (config: RequestConfig) => {
                if (
                    config.method === 'DELETE'
                    && config.url.endsWith('/placement-a')
                ) {
                    return Promise.reject(conflict);
                }

                return readResponse(config);
            },
        );

        const onLifecycleConflict = vi.fn(async () => {});
        renderPanel('draft', onLifecycleConflict);
        await screen.findByText('النسب');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'إزالة المهارة',
            }),
        );

        expect(
            await screen.findByText(
                'لا يمكن إزالة المهارة لأنها مستخدمة.',
            ),
        ).toBeInTheDocument();
        expect(onLifecycleConflict).toHaveBeenCalledTimes(1);
        await waitFor(() => {
            expect(
                apiRequestMock.mock.calls.filter(
                    ([config]) => (
                        config.method === 'GET'
                        && config.url.endsWith('/skill-placements')
                    ),
                ).length,
            ).toBeGreaterThan(1);
        });
    });

    it('does not render mismatched home-topic provenance', async () => {
        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: RequestConfig) => {
                if (
                    method === 'GET'
                    && url.endsWith('/skill-placements')
                ) {
                    return Promise.resolve([
                        {
                            curriculum_version_id: 'version-a',
                            home_topics: [
                                {
                                    curriculum_version_id:
                                        'version-a',
                                    id: 'home-foreign',
                                    placement_id: 'placement-a',
                                    topic: {
                                        id: 'topic-other',
                                        name: 'لا تعرض',
                                    },
                                    topic_id: 'topic-a',
                                },
                            ],
                            id: 'placement-a',
                            skill: {
                                id: 'skill-a',
                                name: 'النسب',
                            },
                            skill_id: 'skill-a',
                        },
                    ]);
                }

                if (
                    method === 'GET'
                    && url.endsWith('/topics')
                ) {
                    return Promise.resolve([
                        {
                            curriculum_version_id: 'version-a',
                            display_order: 1,
                            id: 'topic-a',
                            name: 'موضوع صحيح',
                        },
                    ]);
                }

                if (
                    method === 'GET'
                    && url === '/api/teacher/skills'
                ) {
                    return Promise.resolve([
                        {
                            description: null,
                            id: 'skill-a',
                            name: 'النسب',
                        },
                    ]);
                }

                throw new Error('Unexpected request');
            },
        );

        renderPanel();

        expect(
            await screen.findByText('النسب'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('لا تعرض'),
        ).not.toBeInTheDocument();
    });


    it('confirms and deletes exact-version placement and home-topic links', async () => {
        apiRequestMock.mockImplementation(
            (config: RequestConfig) => {
                if (
                    config.method === 'DELETE'
                    && config.url.endsWith('/placement-a')
                ) {
                    return Promise.resolve({
                        deleted: true,
                        id: 'placement-a',
                    });
                }

                return readResponse(config);
            },
        );

        renderPanel();
        await screen.findByText('النسب');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'إزالة المهارة',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    method: 'DELETE',
                    url:
                        '/api/teacher/subject-assignments/'
                        + 'assignment-a/curricula/curriculum-a/'
                        + 'versions/version-a/skill-placements/'
                        + 'placement-a',
                }),
            );
        });
    });

    it('confirms and deletes a validated home-topic link', async () => {
        apiRequestMock.mockImplementation(
            ({
                method,
                url,
            }: RequestConfig) => {
                if (
                    method === 'GET'
                    && url.endsWith('/skill-placements')
                ) {
                    return Promise.resolve([
                        {
                            curriculum_version_id: 'version-a',
                            home_topics: [
                                {
                                    curriculum_version_id:
                                        'version-a',
                                    id: 'home-a',
                                    placement_id: 'placement-a',
                                    topic: {
                                        id: 'topic-a',
                                        name: 'موضوع رئيس',
                                    },
                                    topic_id: 'topic-a',
                                },
                            ],
                            id: 'placement-a',
                            skill: {
                                id: 'skill-a',
                                name: 'النسب',
                            },
                            skill_id: 'skill-a',
                        },
                    ]);
                }

                if (
                    method === 'GET'
                    && url.endsWith('/topics')
                ) {
                    return Promise.resolve([
                        {
                            curriculum_version_id: 'version-a',
                            display_order: 1,
                            id: 'topic-a',
                            name: 'موضوع رئيس',
                        },
                    ]);
                }

                if (
                    method === 'GET'
                    && url === '/api/teacher/skills'
                ) {
                    return Promise.resolve([
                        {
                            description: null,
                            id: 'skill-a',
                            name: 'النسب',
                        },
                    ]);
                }

                if (
                    method === 'DELETE'
                    && url.endsWith('/home-topics/home-a')
                ) {
                    return Promise.resolve({
                        deleted: true,
                        id: 'home-a',
                    });
                }

                throw new Error('Unexpected request');
            },
        );

        renderPanel();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'إزالة',
            }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    method: 'DELETE',
                    url:
                        '/api/teacher/subject-assignments/'
                        + 'assignment-a/curricula/curriculum-a/'
                        + 'versions/version-a/skill-placements/'
                        + 'placement-a/home-topics/home-a',
                }),
            );
        });
    });

});

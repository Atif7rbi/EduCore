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
    PracticeActivitiesPanel,
} from './PracticeActivitiesPanel';

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

const lesson = {
    id: 'lesson-1',
    curriculum_version_id:
        'version-1',
    title: 'النسب',
    description: null,
    status: 'draft',
    display_order: 0,
    published_revision_id: null,
    created_at: null,
    updated_at: null,
};

const archivedActivity = {
    id: 'activity-1',
    curriculum_version_id:
        'version-1',
    lesson_id: 'lesson-1',
    name: 'تدريب النسب',
    description:
        'مجموعة تدريبية',
    status: 'archived',
    items_count: 2,
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
            <PracticeActivitiesPanel
                version={version}
            />
        </QueryClientProvider>,
    );
}

describe(
    'PracticeActivitiesPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'lists practice activities and lessons for the selected version',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve([
                                archivedActivity,
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
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
                        'تدريب النسب',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        /الحالة: مؤرشفة/,
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByRole(
                        'option',
                        {
                            name:
                                'النسب',
                        },
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'creates an archived practice activity using the exact backend payload',
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
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve(
                                created
                                    ? [
                                        archivedActivity,
                                    ]
                                    : [],
                            );
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                lesson_id:
                                    'lesson-1',
                                name:
                                    'تدريب النسب',
                                description:
                                    'مجموعة تدريبية',
                            });

                            created = true;

                            return Promise.resolve(
                                archivedActivity,
                            );
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'لا توجد مجموعات تدريب لهذا الإصدار.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'اسم مجموعة التدريب',
                    ),
                    {
                        target: {
                            value:
                                'تدريب النسب',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'درس مجموعة التدريب',
                    ),
                    {
                        target: {
                            value:
                                'lesson-1',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'وصف مجموعة التدريب',
                    ),
                    {
                        target: {
                            value:
                                'مجموعة تدريبية',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنشاء مجموعة تدريب',
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
                            '/api/admin/curriculum-versions/version-1/practice-activities',
                        data: {
                            lesson_id:
                                'lesson-1',
                            name:
                                'تدريب النسب',
                            description:
                                'مجموعة تدريبية',
                        },
                    });
                });
            },
        );

        it(
            'updates metadata only for an archived activity',
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
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve([
                                archivedActivity,
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
                            ]);
                        }

                        if (
                            method === 'PUT'
                            && url
                                === '/api/admin/practice-activities/activity-1'
                        ) {
                            expect(
                                data,
                            ).toEqual({
                                lesson_id:
                                    'lesson-1',
                                name:
                                    'تدريب النسب المحدّث',
                                description:
                                    'مجموعة تدريبية',
                            });

                            return Promise.resolve({
                                ...archivedActivity,
                                name:
                                    'تدريب النسب المحدّث',
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    'تدريب النسب',
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
                        'تعديل اسم مجموعة التدريب',
                    ),
                    {
                        target: {
                            value:
                                'تدريب النسب المحدّث',
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
                            '/api/admin/practice-activities/activity-1',
                        data: {
                            lesson_id:
                                'lesson-1',
                            name:
                                'تدريب النسب المحدّث',
                            description:
                                'مجموعة تدريبية',
                        },
                    });
                });
            },
        );

        it(
            'does not expose metadata editing for an active activity',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve([
                                {
                                    ...archivedActivity,
                                    status:
                                        'active',
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
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
                        /الحالة: نشطة/,
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
            },
        );

        it(
            'keeps practice authoring read only outside a draft curriculum version',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve([
                                archivedActivity,
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
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
                        'Practice Activities للقراءة فقط لأن CurriculumVersion ليست draft.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'إنشاء مجموعة تدريب',
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
            },
        );

        it(
            'activates an archived practice activity through the backend lifecycle route',
            async () => {
                let active = false;

                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve([
                                {
                                    ...archivedActivity,
                                    status:
                                        active
                                            ? 'active'
                                            : 'archived',
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/practice-activities/activity-1/activate'
                        ) {
                            active = true;

                            return Promise.resolve({
                                ...archivedActivity,
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
                    /الحالة: مؤرشفة/,
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'تفعيل',
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
                            '/api/admin/practice-activities/activity-1/activate',
                    });
                });

                expect(
                    await screen.findByText(
                        /الحالة: نشطة/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'archives an active practice activity through the backend lifecycle route',
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
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve([
                                {
                                    ...archivedActivity,
                                    status:
                                        active
                                            ? 'active'
                                            : 'archived',
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/admin/practice-activities/activity-1/archive'
                        ) {
                            active = false;

                            return Promise.resolve({
                                ...archivedActivity,
                                status:
                                    'archived',
                            });
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderPanel();

                await screen.findByText(
                    /الحالة: نشطة/,
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

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/admin/practice-activities/activity-1/archive',
                    });
                });

                expect(
                    await screen.findByText(
                        /الحالة: مؤرشفة/,
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'does not expose lifecycle mutations outside a draft curriculum version',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: RequestConfig) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/practice-activities'
                        ) {
                            return Promise.resolve([
                                archivedActivity,
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/admin/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                lesson,
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

                await screen.findByText(
                    'Practice Activities للقراءة فقط لأن CurriculumVersion ليست draft.',
                );

                expect(
                    screen.queryByRole(
                        'button',
                        {
                            name:
                                'تفعيل',
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

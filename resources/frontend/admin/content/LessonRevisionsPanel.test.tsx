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
    LessonRevisionsPanel,
} from './LessonRevisionsPanel';

import type {
    CurriculumVersion,
    Lesson,
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

const version:
CurriculumVersion = {
    id: 'version-1',
    curriculum_id:
        'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const lesson:
Lesson = {
    id: 'lesson-1',
    curriculum_version_id:
        'version-1',
    title: 'النسب',
    description: null,
    status: 'draft',
    display_order: 1,
    published_revision_id: null,
    created_at: null,
    updated_at: null,
};

function renderPanel(
    currentLesson:
        Lesson = lesson,
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
            <LessonRevisionsPanel
                version={version}
                lesson={
                    currentLesson
                }
                onClose={() => {}}
            />
        </QueryClientProvider>,
    );
}

describe(
    'LessonRevisionsPanel',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'creates a lesson revision with topic and content payload',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce([
                        {
                            id:
                                'topic-1',
                            curriculum_version_id:
                                'version-1',
                            name:
                                'النسب',
                            display_order:
                                1,
                            created_at:
                                null,
                            updated_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce({
                        id:
                            'revision-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            lesson_id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            released_at:
                                null,
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                await screen.findByText(
                    'لا توجد مراجعات لهذا الدرس.',
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'رقم مراجعة الدرس',
                    ),
                    {
                        target: {
                            value: '1',
                        },
                    },
                );

                fireEvent.change(
                    screen.getByLabelText(
                        'الموضوع الرئيسي للمراجعة',
                    ),
                    {
                        target: {
                            value:
                                'topic-1',
                        },
                    },
                );

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنشاء Revision',
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
                            '/api/admin/lessons/lesson-1/revisions',
                        data: {
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                        },
                    });
                });
            },
        );

        it(
            'releases an unreleased revision',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            lesson_id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            released_at:
                                null,
                            created_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        id:
                            'revision-1',
                        released_at:
                            '2026-08-31T00:00:00Z',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            lesson_id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            released_at:
                                '2026-08-31T00:00:00Z',
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                const button =
                    await screen
                        .findByRole(
                            'button',
                            {
                                name:
                                    'تحرير Revision',
                            },
                        );

                fireEvent.click(
                    button,
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/lesson-revisions/revision-1/release',
                    });
                });
            },
        );

        it(
            'publishes a draft lesson with a released revision',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            lesson_id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            released_at:
                                '2026-08-31T00:00:00Z',
                            created_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        ...lesson,
                        status:
                            'published',
                        published_revision_id:
                            'revision-1',
                    })
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            lesson_id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            released_at:
                                '2026-08-31T00:00:00Z',
                            created_at:
                                null,
                        },
                    ]);

                renderPanel();

                const button =
                    await screen
                        .findByRole(
                            'button',
                            {
                                name:
                                    'نشر بهذه المراجعة',
                            },
                        );

                fireEvent.click(
                    button,
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/lessons/lesson-1/publish',
                        data: {
                            published_revision_id:
                                'revision-1',
                        },
                    });
                });
            },
        );

        it(
            'retires a published lesson',
            async () => {
                apiRequestMock
                    .mockResolvedValueOnce([
                        {
                            id:
                                'revision-1',
                            lesson_id:
                                'lesson-1',
                            curriculum_version_id:
                                'version-1',
                            revision_number:
                                1,
                            primary_topic_id:
                                'topic-1',
                            content_payload:
                                [],
                            content_schema_version:
                                1,
                            released_at:
                                '2026-08-31T00:00:00Z',
                            created_at:
                                null,
                        },
                    ])
                    .mockResolvedValueOnce([])
                    .mockResolvedValueOnce({
                        ...lesson,
                        status:
                            'retired',
                    });

                renderPanel({
                    ...lesson,
                    status:
                        'published',
                    published_revision_id:
                        'revision-1',
                });

                const button =
                    await screen
                        .findByRole(
                            'button',
                            {
                                name:
                                    'تقاعد الدرس',
                            },
                        );

                fireEvent.click(
                    button,
                );

                await waitFor(() => {
                    expect(
                        apiRequestMock,
                    ).toHaveBeenCalledWith({
                        method:
                            'POST',
                        url:
                            '/api/lessons/lesson-1/retire',
                    });
                });
            },
        );
    },
);

import {
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    MemoryRouter,
    Route,
    Routes,
} from 'react-router-dom';
import {
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    AttemptPage,
} from '../learner/AttemptPage';
import {
    CurriculumDiscoveryPage,
} from '../learner/CurriculumDiscoveryPage';
import {
    CurriculumVersionPage,
} from '../learner/CurriculumVersionPage';
import {
    ExamsPage,
} from '../learner/ExamsPage';
import {
    LessonPage,
} from '../learner/LessonPage';
import {
    PracticeActivityPage,
} from '../learner/PracticeActivityPage';
import {
    ResultsPage,
} from '../learner/ResultsPage';

interface TestRequest {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (
        config: TestRequest,
    ) => apiRequestMock(config),
}));

function queryClient() {
    return new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
            mutations: {
                retry: false,
            },
        },
    });
}

function renderProduct(
    initialEntry: string,
) {
    render(
        <QueryClientProvider
            client={queryClient()}
        >
            <MemoryRouter
                initialEntries={[
                    initialEntry,
                ]}
            >
                <Routes>
                    <Route
                        path="/app/curriculum"
                        element={
                            <CurriculumDiscoveryPage />
                        }
                    />

                    <Route
                        path="/app/curriculum/:curriculumVersionId"
                        element={
                            <CurriculumVersionPage />
                        }
                    />

                    <Route
                        path="/app/lessons/:lessonId"
                        element={<LessonPage />}
                    />

                    <Route
                        path="/app/practice/:practiceActivityId"
                        element={
                            <PracticeActivityPage />
                        }
                    />

                    <Route
                        path="/app/exams"
                        element={<ExamsPage />}
                    />

                    <Route
                        path="/app/results"
                        element={<ResultsPage />}
                    />

                    <Route
                        path="/app/attempts/:attemptId"
                        element={<AttemptPage />}
                    />
                </Routes>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

function inProgressAttempt({
    id,
    examGenerationId,
    practiceActivityId,
}: {
    id: string;
    examGenerationId: string | null;
    practiceActivityId: string | null;
}) {
    return {
        id,
        exam_generation_id:
            examGenerationId,
        practice_activity_id:
            practiceActivityId,
        curriculum_version_id:
            'version-1',
        status: 'in_progress',
        started_at:
            '2026-08-30T12:00:00Z',
        finalized_at: null,
        items: [
            {
                id:
                    `${id}-item-1`,
                assessment_item_revision_id:
                    'question-revision-1',
                assessment_item_id:
                    'question-1',
                presentation_position: 0,
                presented_payload: {
                    stem: '8 + 7 = ؟',
                    options: [
                        13,
                        14,
                        15,
                        16,
                    ],
                },
                presented_schema_version: 1,
                response: {
                    id:
                        `${id}-response-1`,
                    response_payload: null,
                    answer_change_count: 0,
                    time_spent_ms: 0,
                },
            },
        ],
    };
}

function submittedAttempt() {
    const attempt =
        inProgressAttempt({
            id: 'attempt-result',
            examGenerationId:
                'generation-1',
            practiceActivityId: null,
        });

    return {
        ...attempt,
        status: 'submitted',
        finalized_at:
            '2026-08-30T12:10:00Z',
        items: [
            {
                ...attempt.items[0],
                response: {
                    ...attempt.items[0]
                        .response,
                    response_payload: {
                        selected_option: 2,
                    },
                    time_spent_ms: 1500,
                },
                result: {
                    original_is_correct:
                        true,
                    effective_is_correct:
                        true,
                    correction_number:
                        null,
                },
            },
        ],
        summary: {
            answered: 1,
            correct: 1,
            incorrect: 0,
            unanswered: 0,
            total: 1,
        },
    };
}

describe(
    'P3 learner product integration',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'moves from curriculum discovery through lesson and practice into an attempt',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: TestRequest) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/curricula'
                        ) {
                            return Promise.resolve([
                                {
                                    subject: {
                                        id:
                                            'subject-1',
                                        name:
                                            'القدرات الكمية',
                                    },
                                    curriculum: {
                                        id:
                                            'curriculum-1',
                                        name:
                                            'المنهج الكمي',
                                    },
                                    published_versions:
                                        [
                                            {
                                                id:
                                                    'version-1',
                                                version_number:
                                                    1,
                                                label:
                                                    'الإصدار الأول',
                                            },
                                        ],
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/curriculum-versions/version-1'
                        ) {
                            return Promise.resolve({
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
                                topics: [
                                    {
                                        id:
                                            'topic-1',
                                        name:
                                            'النسب',
                                        display_order:
                                            1,
                                    },
                                ],
                            });
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/curriculum-versions/version-1/lessons'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'lesson-1',
                                    curriculum_version_id:
                                        'version-1',
                                    title:
                                        'درس النسب',
                                    description:
                                        'مقدمة في النسب.',
                                    status:
                                        'published',
                                    display_order:
                                        1,
                                    published_revision_id:
                                        'lesson-revision-1',
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/lessons/lesson-1'
                        ) {
                            return Promise.resolve({
                                id:
                                    'lesson-1',
                                curriculum_version_id:
                                    'version-1',
                                title:
                                    'درس النسب',
                                description:
                                    'تعلم أساسيات النسب.',
                                status:
                                    'published',
                                display_order:
                                    1,
                                published_revision: {
                                    id:
                                        'lesson-revision-1',
                                    revision_number:
                                        1,
                                    primary_topic_id:
                                        'topic-1',
                                    content_payload: {
                                        blocks: [
                                            {
                                                type:
                                                    'text',
                                                value:
                                                    'النسبة تقارن بين مقدارين.',
                                            },
                                        ],
                                    },
                                    content_schema_version:
                                        1,
                                    released_at:
                                        '2026-08-30T00:00:00Z',
                                },
                                practice_activities:
                                    [
                                        {
                                            id:
                                                'practice-1',
                                            name:
                                                'تدريب النسب',
                                            description:
                                                'أسئلة تدريبية.',
                                            status:
                                                'active',
                                        },
                                    ],
                            });
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/lessons/lesson-1/progress'
                        ) {
                            return Promise.resolve(
                                null,
                            );
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/practice-activities/practice-1'
                        ) {
                            return Promise.resolve({
                                id:
                                    'practice-1',
                                curriculum_version_id:
                                    'version-1',
                                lesson_id:
                                    'lesson-1',
                                name:
                                    'تدريب النسب',
                                description:
                                    'أسئلة تدريبية.',
                                status:
                                    'active',
                                items: [
                                    {
                                        id:
                                            'membership-1',
                                        assessment_item_revision_id:
                                            'question-revision-1',
                                        assessment_item_id:
                                            'question-1',
                                        display_order:
                                            0,
                                    },
                                ],
                            });
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/practice-activities/practice-1/attempts'
                        ) {
                            return Promise.resolve(
                                inProgressAttempt({
                                    id:
                                        'attempt-practice',
                                    examGenerationId:
                                        null,
                                    practiceActivityId:
                                        'practice-1',
                                }),
                            );
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/attempts/attempt-practice'
                        ) {
                            return Promise.resolve(
                                inProgressAttempt({
                                    id:
                                        'attempt-practice',
                                    examGenerationId:
                                        null,
                                    practiceActivityId:
                                        'practice-1',
                                }),
                            );
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderProduct(
                    '/app/curriculum',
                );

                fireEvent.click(
                    await screen.findByRole(
                        'link',
                        {
                            name:
                                /الإصدار الأول.*الإصدار 1/s,
                        },
                    ),
                );

                fireEvent.click(
                    await screen.findByRole(
                        'link',
                        {
                            name:
                                /درس النسب.*فتح الدرس/s,
                        },
                    ),
                );

                expect(
                    await screen.findByText(
                        'النسبة تقارن بين مقدارين.',
                    ),
                ).toBeInTheDocument();

                fireEvent.click(
                    screen.getByRole(
                        'link',
                        {
                            name:
                                /تدريب النسب.*فتح الممارسة/s,
                        },
                    ),
                );

                fireEvent.click(
                    await screen.findByRole(
                        'button',
                        {
                            name:
                                'ابدأ الممارسة',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                '8 + 7 = ؟',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنهاء الممارسة',
                        },
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'starts an exam and enters the shared exam attempt experience',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: TestRequest) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/exam-generations'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'generation-1',
                                    curriculum_version_id:
                                        'version-1',
                                    exam_template_version_id:
                                        'template-version-1',
                                    template: {
                                        id:
                                            'template-1',
                                        name:
                                            'اختبار النسب',
                                        description:
                                            'اختبار قصير.',
                                    },
                                    template_version: {
                                        id:
                                            'template-version-1',
                                        version_number:
                                            1,
                                        label:
                                            'الإصدار الأول',
                                    },
                                    generated_at:
                                        '2026-08-30T00:00:00Z',
                                    item_count:
                                        1,
                                    current_attempt:
                                        null,
                                },
                            ]);
                        }

                        if (
                            method === 'POST'
                            && url
                                === '/api/exam-generations/generation-1/attempts'
                        ) {
                            return Promise.resolve(
                                inProgressAttempt({
                                    id:
                                        'attempt-exam',
                                    examGenerationId:
                                        'generation-1',
                                    practiceActivityId:
                                        null,
                                }),
                            );
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/attempts/attempt-exam'
                        ) {
                            return Promise.resolve(
                                inProgressAttempt({
                                    id:
                                        'attempt-exam',
                                    examGenerationId:
                                        'generation-1',
                                    practiceActivityId:
                                        null,
                                }),
                            );
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderProduct(
                    '/app/exams',
                );

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'اختبار النسب',
                        },
                    ),
                ).toBeInTheDocument();

                fireEvent.click(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'ابدأ الاختبار',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                '8 + 7 = ؟',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'اختبار',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByRole(
                        'button',
                        {
                            name:
                                'إنهاء الاختبار',
                        },
                    ),
                ).toBeInTheDocument();
            },
        );

        it(
            'opens finalized history through the shared attempt result view',
            async () => {
                apiRequestMock.mockImplementation(
                    ({
                        method,
                        url,
                    }: TestRequest) => {
                        if (
                            method === 'GET'
                            && url
                                === '/api/attempts'
                        ) {
                            return Promise.resolve([
                                {
                                    id:
                                        'attempt-result',
                                    exam_generation_id:
                                        'generation-1',
                                    practice_activity_id:
                                        null,
                                    curriculum_version_id:
                                        'version-1',
                                    status:
                                        'submitted',
                                    started_at:
                                        '2026-08-30T12:00:00Z',
                                    finalized_at:
                                        '2026-08-30T12:10:00Z',
                                    summary: {
                                        answered:
                                            1,
                                        correct:
                                            1,
                                        incorrect:
                                            0,
                                        unanswered:
                                            0,
                                        total:
                                            1,
                                    },
                                },
                            ]);
                        }

                        if (
                            method === 'GET'
                            && url
                                === '/api/attempts/attempt-result'
                        ) {
                            return Promise.resolve(
                                submittedAttempt(),
                            );
                        }

                        throw new Error(
                            `Unexpected request ${method} ${url}`,
                        );
                    },
                );

                renderProduct(
                    '/app/results',
                );

                expect(
                    await screen.findByText(
                        'صحيحة',
                    ),
                ).toBeInTheDocument();

                fireEvent.click(
                    screen.getByRole(
                        'link',
                        {
                            name:
                                'عرض النتيجة',
                        },
                    ),
                );

                expect(
                    await screen.findByRole(
                        'heading',
                        {
                            name:
                                'اكتمل الاختبار',
                        },
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'صحيحة',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'الإجمالي',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByText(
                        'الإجابة صحيحة',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByRole(
                        'link',
                        {
                            name:
                                'العودة إلى الاختبارات',
                        },
                    ),
                ).toHaveAttribute(
                    'href',
                    '/app/exams',
                );
            },
        );
    },
);

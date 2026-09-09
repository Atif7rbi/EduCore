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
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    EduCoreApiError,
} from '../../api/errors';
import {
    ContentReadinessPanel,
} from './ContentReadinessPanel';

interface RequestConfig {
    method: string;
    url: string;
}

const apiRequestMock = vi.fn();

vi.mock('../../api/client', () => ({
    apiRequest: (config: RequestConfig) =>
        apiRequestMock(config),
}));

const version = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft' as const,
};

function renderPanel(
    onNavigateToSection?: (
        section: string,
    ) => void,
) {
    const client = new QueryClient({
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
            <ContentReadinessPanel
                version={version}
                onNavigateToSection={
                    onNavigateToSection
                }
            />
        </QueryClientProvider>,
    );
}

function response({
    ready,
    status = 'draft',
}: {
    ready: boolean;
    status?: 'draft' | 'published';
}) {
    const lessonReady =
        status === 'published'
            ? true
            : ready;

    const blockers =
        status === 'published'
            ? [
                {
                    code:
                        'curriculum_version_is_draft',
                    message: 'Draft.',
                    value: 'published',
                },
            ]
            : ready
                ? []
                : [
                    {
                        code:
                            'has_published_lesson',
                        message: 'Lesson.',
                        value: 0,
                    },
                ];

    return {
        curriculum_version: {
            ...version,
            status,
        },
        ready_to_publish: ready,
        checks: [
            {
                code:
                    'curriculum_version_is_draft',
                message: 'Draft.',
                passed: status === 'draft',
                value: status,
            },
            {
                code: 'has_topic',
                message: 'Topic.',
                passed: true,
                value: 1,
            },
            {
                code:
                    'has_skill_placement',
                message: 'Skill.',
                passed: true,
                value: 1,
            },
            {
                code:
                    'has_published_lesson',
                message: 'Lesson.',
                passed: lessonReady,
                value: lessonReady ? 1 : 0,
            },
            {
                code:
                    'has_published_assessment_item',
                message: 'Assessment.',
                passed: true,
                value: 1,
            },
            {
                code:
                    'has_learner_usable_practice',
                message: 'Practice.',
                passed: true,
                value: 1,
            },
            {
                code:
                    'has_usable_exam_template',
                message: 'Exam.',
                passed: true,
                value: 1,
            },
        ],
        counts: {},
        blockers,
        warnings: [
            {
                code: 'draft_lessons',
                message: 'Draft lessons.',
                value: 2,
            },
        ],
    };
}

describe('ContentReadinessPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows blockers without exposing the publish action', async () => {
        apiRequestMock.mockResolvedValue(
            response({
                ready: false,
            })
        );

        renderPanel();

        expect(
            await screen.findByText(
                'غير جاهز للنشر'
            )
        ).toBeInTheDocument();

        expect(
            screen.getAllByText(
                'لا يوجد درس منشور'
            ).length
        ).toBeGreaterThan(0);

        expect(
            screen.getByText(
                'دروس ما زالت مسودة (2)'
            )
        ).toBeInTheDocument();

        expect(
            screen.queryByRole(
                'button',
                { name: 'نشر المنهج' }
            )
        ).not.toBeInTheDocument();

        expect(
            apiRequestMock
        ).toHaveBeenCalledWith({
            method: 'GET',
            url:
                '/api/admin/curriculum-versions/version-1/readiness',
        });
    });

    it('explains a blocker and navigates directly to the responsible section', async () => {
        const blocked = response({
            ready: false,
        });

        blocked.checks = blocked.checks.map(
            (check) => {
                if (
                    check.code
                    === 'has_published_lesson'
                ) {
                    return {
                        ...check,
                        passed: true,
                        value: 1,
                    };
                }

                if (
                    check.code
                    === 'has_learner_usable_practice'
                ) {
                    return {
                        ...check,
                        passed: false,
                        value: 0,
                    };
                }

                return check;
            }
        );

        blocked.blockers = [
            {
                code:
                    'has_learner_usable_practice',
                message: 'Practice.',
                value: 0,
            },
        ];

        apiRequestMock.mockResolvedValue(
            blocked
        );

        const navigate = vi.fn();

        renderPanel(navigate);

        const infoButton =
            await screen.findByRole(
                'button',
                {
                    name:
                        'تفاصيل لا يوجد تدريب نشط قابل للعرض للمتعلم',
                }
            );

        fireEvent.click(infoButton);

        expect(
            screen.getByText(
                'يجب إتاحة تدريب واحد على الأقل للطلاب حتى تصبح نسخة المنهج جاهزة للنشر.'
            )
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'القسم المطلوب:'
            )
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole(
                'button',
                {
                    name:
                        'الانتقال إلى التدريبات',
                }
            )
        );

        expect(
            navigate
        ).toHaveBeenCalledWith(
            'practice-activities'
        );
    });

    it('exposes publish only for a ready draft', async () => {
        apiRequestMock.mockResolvedValue(
            response({
                ready: true,
            })
        );

        renderPanel();

        expect(
            await screen.findByText(
                'جاهز للنشر'
            )
        ).toBeInTheDocument();

        expect(
            screen.queryByText('الموانع')
        ).not.toBeInTheDocument();

        expect(
            screen.getByRole(
                'button',
                { name: 'نشر المنهج' }
            )
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'النشر إجراء نهائي لهذه النسخة وسيوقف التأليف عليها.'
            )
        ).toBeInTheDocument();
    });

    it('publishes a ready curriculum and switches to published state', async () => {
        apiRequestMock
            .mockResolvedValueOnce(
                response({
                    ready: true,
                })
            )
            .mockResolvedValueOnce({
                ...version,
                status: 'published',
            })
            .mockResolvedValueOnce(
                response({
                    ready: false,
                    status: 'published',
                })
            );

        vi.spyOn(
            window,
            'confirm'
        ).mockReturnValue(true);

        renderPanel();

        const publishButton =
            await screen.findByRole(
                'button',
                { name: 'نشر المنهج' }
            );

        fireEvent.click(
            publishButton
        );

        await waitFor(() => {
            expect(
                apiRequestMock
            ).toHaveBeenCalledWith({
                method: 'POST',
                url:
                    '/api/curriculum-versions/version-1/publish',
            });
        });

        expect(
            await screen.findByText(
                'تم نشر النسخة'
            )
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /تم نشر المنهج بنجاح/
            )
        ).toBeInTheDocument();

        expect(
            screen.queryByRole(
                'button',
                { name: 'نشر المنهج' }
            )
        ).not.toBeInTheDocument();

        expect(
            apiRequestMock
        ).toHaveBeenCalledTimes(3);
    });

    it('refreshes readiness when authoritative publishing rejects stale readiness', async () => {
        apiRequestMock
            .mockResolvedValueOnce(
                response({
                    ready: true,
                })
            )
            .mockRejectedValueOnce(
                new EduCoreApiError({
                    code:
                        'curriculum_version_not_ready',
                    message:
                        'Publishing requirements changed.',
                    status: 409,
                    details: {
                        blockers: [
                            'has_published_lesson',
                        ],
                    },
                    requestId: null,
                })
            )
            .mockResolvedValueOnce(
                response({
                    ready: false,
                })
            );

        vi.spyOn(
            window,
            'confirm'
        ).mockReturnValue(true);

        renderPanel();

        fireEvent.click(
            await screen.findByRole(
                'button',
                { name: 'نشر المنهج' }
            )
        );

        expect(
            await screen.findByText(
                'تعذر نشر المنهج لأن متطلبات النشر تغيرت. تم تحديث المراجعة.'
            )
        ).toBeInTheDocument();

        expect(
            await screen.findByText(
                'غير جاهز للنشر'
            )
        ).toBeInTheDocument();

        expect(
            screen.queryByRole(
                'button',
                { name: 'نشر المنهج' }
            )
        ).not.toBeInTheDocument();

        await waitFor(() => {
            expect(
                apiRequestMock
            ).toHaveBeenCalledTimes(3);
        });
    });
});

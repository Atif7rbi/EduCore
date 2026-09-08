import {
    render,
    screen,
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

function renderPanel() {
    const client = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <ContentReadinessPanel
                version={version}
            />
        </QueryClientProvider>,
    );
}

function response({
    ready,
}: {
    ready: boolean;
}) {
    return {
        curriculum_version: version,
        ready_to_publish: ready,
        checks: [
            {
                code:
                    'curriculum_version_is_draft',
                message: 'Draft.',
                passed: true,
                value: 'draft',
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
                passed: ready,
                value: ready ? 1 : 0,
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
        blockers: ready
            ? []
            : [
                {
                    code:
                        'has_published_lesson',
                    message: 'Lesson.',
                    value: 0,
                },
            ],
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

    it('shows publishing blockers without exposing a publish action', async () => {
        apiRequestMock.mockResolvedValue(
            response({ ready: false })
        );

        renderPanel();

        expect(
            await screen.findByText(
                'غير جاهز للنشر'
            )
        ).toBeInTheDocument();

        expect(
            screen.getAllByText(
                'يوجد درس منشور واحد على الأقل'
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
                { name: /نشر/ }
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

    it('shows ready state as review-only', async () => {
        apiRequestMock.mockResolvedValue(
            response({ ready: true })
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
            screen.getByText(
                'هذه الصفحة للمراجعة فقط. لا يتم نشر المنهج من هنا.'
            )
        ).toBeInTheDocument();

        expect(
            screen.getByRole(
                'button',
                { name: 'تحديث المراجعة' }
            )
        ).toBeInTheDocument();
    });
});

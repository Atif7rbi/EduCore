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
    MemoryRouter,
} from 'react-router-dom';
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
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

vi.mock('./content/TopicsPanel', () => ({
    TopicsPanel: () => <div data-testid="topics-panel">لوحة الوحدات</div>,
}));

vi.mock('./content/SkillsPanel', () => ({
    SkillsPanel: () => <div data-testid="skills-panel">لوحة المهارات</div>,
}));

vi.mock('./content/SkillPlacementsPanel', () => ({
    SkillPlacementsPanel: ({ version }: {
        version: { status: 'draft' | 'published' | 'retired' };
    }) => (
        <div
            data-testid="placements-panel"
            data-version-status={version.status}
        >
            ربط المهارات
        </div>
    ),
}));

vi.mock('./content/LessonsPanel', () => ({
    LessonsPanel: () => <div data-testid="lessons-panel">لوحة الدروس</div>,
}));

vi.mock('./content/AssessmentItemsPanel', () => ({
    AssessmentItemsPanel: () => <div data-testid="assessment-items-panel">بنك الأسئلة</div>,
}));

vi.mock('./content/PracticeActivitiesPanel', () => ({
    PracticeActivitiesPanel: () => <div data-testid="practice-activities-panel">لوحة التدريبات</div>,
}));

vi.mock('./content/ExamTemplatesPanel', () => ({
    ExamTemplatesPanel: () => <div data-testid="exam-templates-panel">لوحة الاختبارات</div>,
}));

vi.mock('./content/ContentReadinessPanel', () => ({
    contentReadinessKey: (
        curriculumVersionId: string,
    ) => [
        'admin',
        'content',
        'curriculum-versions',
        curriculumVersionId,
        'readiness',
    ] as const,

    fetchContentReadiness: (
        curriculumVersionId: string,
    ) =>
        apiRequestMock({
            method: 'GET',
            url:
                `/api/admin/curriculum-versions/${curriculumVersionId}/readiness`,
        }),

    ContentReadinessPanel: ({ version }: {
        version: {
            status: 'draft' | 'published' | 'retired';
        };
    }) => (
        <div
            data-testid="readiness-panel"
            data-version-status={version.status}
        >
            مراجعة النشر
        </div>
    ),
}));

function renderPage(initialEntry = '/admin/content') {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <MemoryRouter initialEntries={[initialEntry]}>
            <QueryClientProvider client={client}>
                <AdminContentPage />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

function readinessResponse({
    practiceReady = true,
    examWarning = false,
}: {
    practiceReady?: boolean;
    examWarning?: boolean;
} = {}) {
    return {
        curriculum_version: {
            id: 'version-1',
            curriculum_id: 'curriculum-1',
            version_number: 1,
            label: 'الإصدار الأول',
            status: 'draft',
        },
        ready_to_publish:
            practiceReady,
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
                passed: true,
                value: 1,
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
                passed: practiceReady,
                value:
                    practiceReady ? 1 : 0,
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
        blockers:
            practiceReady
                ? []
                : [
                    {
                        code:
                            'has_learner_usable_practice',
                        message: 'Practice.',
                        value: 0,
                    },
                ],
        warnings:
            examWarning
                ? [
                    {
                        code:
                            'draft_exam_template_versions',
                        message: 'Draft exam.',
                        value: 1,
                    },
                ]
                : [],
    };
}

function installContext(
    status:
        'draft'
        | 'published'
        | 'retired' = 'draft',
    readinessData =
        readinessResponse(),
) {
    apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
        if (method === 'GET' && url === '/api/admin/subjects') {
            return Promise.resolve([
                {
                    id: 'subject-1',
                    name: 'القدرات الكمية',
                    created_at: null,
                    updated_at: null,
                },
            ]);
        }

        if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
            return Promise.resolve([
                {
                    id: 'curriculum-1',
                    subject_id: 'subject-1',
                    name: 'المنهج الكمي',
                    created_at: null,
                    updated_at: null,
                },
            ]);
        }

        if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
            return Promise.resolve([
                {
                    id: 'version-1',
                    curriculum_id: 'curriculum-1',
                    version_number: 1,
                    label: 'الإصدار الأول',
                    status,
                },
            ]);
        }

        if (
            method === 'GET'
            && url
                === '/api/admin/curriculum-versions/version-1/readiness'
        ) {
            return Promise.resolve(
                readinessData
            );
        }

        throw new Error(`Unexpected request ${method} ${url}`);
    });
}

describe('AdminContentPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('opens the redesigned workspace on lessons with compact context', async () => {
        installContext();
        renderPage();

        expect(
            await screen.findByRole('heading', { name: 'إدارة المحتوى' }),
        ).toBeInTheDocument();

        expect(await screen.findByTestId('lessons-panel')).toBeInTheDocument();
        expect(screen.queryByTestId('topics-panel')).not.toBeInTheDocument();
        expect(screen.getByLabelText('المادة')).toHaveValue('subject-1');
        expect(screen.getByLabelText('المنهج')).toHaveValue('curriculum-1');
        expect(screen.getByText('مسودة')).toBeInTheDocument();
        expect(screen.queryByText('الإصدار الأول')).not.toBeInTheDocument();
    });

    it('opens the requested content tab from a deep link', async () => {
        installContext();
        renderPage('/admin/content?section=exam-templates');

        expect(await screen.findByTestId('exam-templates-panel'))
            .toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'الاختبارات' }))
            .toHaveAttribute('aria-selected', 'true');
        expect(screen.queryByTestId('lessons-panel')).not.toBeInTheDocument();
    });

    it('opens publishing readiness from a deep link as review-only workspace', async () => {
        installContext();
        renderPage('/admin/content?section=readiness');

        expect(
            await screen.findByTestId(
                'readiness-panel'
            )
        ).toHaveAttribute(
            'data-version-status',
            'draft',
        );

        expect(
            screen.getByRole(
                'tab',
                { name: 'مراجعة النشر' }
            )
        ).toHaveAttribute(
            'aria-selected',
            'true',
        );
    });

    it('orders authoring tabs by user workflow and keeps placements inside skills', async () => {
        installContext();
        renderPage();

        await screen.findByTestId('lessons-panel');

        const tabs = screen.getAllByRole('tab').map((tab) => tab.textContent);
        expect(tabs).toEqual([
            'الوحدات',
            'الدروس',
            'بنك الأسئلة',
            'التدريبات',
            'الاختبارات',
            'المهارات',
            'مراجعة النشر',
        ]);

        fireEvent.click(screen.getByRole('tab', { name: 'المهارات' }));

        expect(await screen.findByTestId('skills-panel')).toBeInTheDocument();
        expect(screen.getByTestId('placements-panel')).toHaveAttribute(
            'data-version-status',
            'draft',
        );
        expect(screen.queryByTestId('lessons-panel')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('tab', { name: 'الوحدات' }));
        expect(await screen.findByTestId('topics-panel')).toBeInTheDocument();
        expect(screen.queryByTestId('skills-panel')).not.toBeInTheDocument();
    });

    it('shows publishing readiness as a subtle state on the responsible tabs', async () => {
        installContext(
            'draft',
            readinessResponse({
                practiceReady: false,
                examWarning: true,
            }),
        );

        renderPage();

        await screen.findByTestId(
            'lessons-panel'
        );

        await waitFor(() => {
            expect(
                screen.getByRole(
                    'tab',
                    { name: 'التدريبات' }
                )
            ).toHaveAttribute(
                'data-readiness-state',
                'blocker',
            );
        });

        expect(
            screen.getByRole(
                'tab',
                { name: 'الدروس' }
            )
        ).toHaveAttribute(
            'data-readiness-state',
            'pass',
        );

        expect(
            screen.getByRole(
                'tab',
                { name: 'الاختبارات' }
            )
        ).toHaveAttribute(
            'data-readiness-state',
            'warning',
        );

        expect(
            screen.getByRole(
                'tab',
                { name: 'مراجعة النشر' }
            )
        ).toHaveAttribute(
            'data-readiness-state',
            'blocker',
        );
    });

    it('resets curriculum context when the subject changes', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/subjects') {
                return Promise.resolve([
                    { id: 'subject-1', name: 'القدرات الكمية' },
                    { id: 'subject-2', name: 'القدرات اللفظية' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
                return Promise.resolve([
                    { id: 'curriculum-1', subject_id: 'subject-1', name: 'المنهج الكمي' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
                return Promise.resolve([
                    { id: 'version-1', curriculum_id: 'curriculum-1', version_number: 1, label: 'الأول', status: 'draft' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-2/curricula') {
                return Promise.resolve([
                    { id: 'curriculum-2', subject_id: 'subject-2', name: 'المنهج اللفظي' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-2/versions') {
                return Promise.resolve([
                    { id: 'version-2', curriculum_id: 'curriculum-2', version_number: 1, label: 'الأول', status: 'draft' },
                ]);
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/curriculum-versions/version-1/readiness'
            ) {
                return Promise.resolve(
                    readinessResponse()
                );
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/curriculum-versions/version-2/readiness'
            ) {
                return Promise.resolve({
                    ...readinessResponse(),
                    curriculum_version: {
                        ...readinessResponse()
                            .curriculum_version,
                        id: 'version-2',
                        curriculum_id:
                            'curriculum-2',
                    },
                });
            }

            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPage();
        await screen.findByTestId('lessons-panel');

        fireEvent.change(screen.getByLabelText('المادة'), {
            target: { value: 'subject-2' },
        });

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'GET',
                url: '/api/admin/subjects/subject-2/curricula',
            });
        });

        await waitFor(() => {
            expect(screen.getByLabelText('المنهج')).toHaveValue('curriculum-2');
        });
    });

    it('exposes published lifecycle as a compact status while preserving read-only context', async () => {
        installContext('published');
        renderPage();

        expect(await screen.findByText('منشور')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'المهارات' }));
        expect(await screen.findByTestId('placements-panel')).toHaveAttribute(
            'data-version-status',
            'published',
        );
    });

    it('exposes retired lifecycle as stopped without surfacing internal version labels', async () => {
        installContext('retired');
        renderPage();

        expect(await screen.findByText('موقوف')).toBeInTheDocument();
        expect(screen.queryByText('الإصدار الأول')).not.toBeInTheDocument();
    });
});

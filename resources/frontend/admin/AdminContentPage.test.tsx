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
    useLocation,
    useNavigate,
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

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (error: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

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

vi.mock('./content/TeacherOwnedContentInspection', () => ({
    TeacherOwnedContentInspection: () => (
        <div data-testid="teacher-owned-content-inspection">
            Teacher-owned content inspection
        </div>
    ),
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

    ContentReadinessPanel: ({
        version,
        readOnly = false,
    }: {
        version: {
            status: 'draft' | 'published' | 'retired';
        };
        readOnly?: boolean;
    }) => (
        <div
            data-testid="readiness-panel"
            data-version-status={version.status}
            data-read-only={
                readOnly ? 'true' : 'false'
            }
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
                <HistoryProbe />
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

function HistoryProbe() {
    const location = useLocation();
    const navigate = useNavigate();

    return (
        <div>
            <output aria-label="عنوان السياق الحالي">
                {location.pathname}{location.search}
            </output>
            <button type="button" onClick={() => navigate(-1)}>رجوع اختباري</button>
            <button type="button" onClick={() => navigate(1)}>تقدم اختباري</button>
        </div>
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
    teacherSubjectAssignmentId:
        string | null = null,
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
                    teacher_subject_assignment_id:
                        teacherSubjectAssignmentId,

                    ownership_kind:
                        teacherSubjectAssignmentId
                            ? 'teacher_owned'
                            : 'legacy_ownerless',

                    teacher_user_id:
                        teacherSubjectAssignmentId
                            ? 'teacher-1'
                            : null,

                    teacher_subject_assignment_status:
                        teacherSubjectAssignmentId
                            ? 'active'
                            : null,

                    teacher:
                        teacherSubjectAssignmentId
                            ? {
                                user_id:
                                    'teacher-1',
                                name:
                                    'معلم الرياضيات',
                                email:
                                    'teacher@example.test',
                                status:
                                    'active',
                            }
                            : null,

                    subject: {
                        id: 'subject-1',
                        code: 'mathematics',
                        name: 'القدرات الكمية',
                        status: 'active',
                    },

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

function installVersionedContext() {
    apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
        if (method === 'GET' && url === '/api/admin/subjects') {
            return Promise.resolve([
                { id: 'subject-1', name: 'القدرات الكمية' },
            ]);
        }

        if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
            return Promise.resolve([
                {
                    id: 'curriculum-1',
                    subject_id: 'subject-1',
                    name: 'المنهج الكمي',
                },
            ]);
        }

        if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
            return Promise.resolve([
                {
                    id: 'version-1',
                    curriculum_id: 'curriculum-1',
                    version_number: 1,
                    label: 'الأول',
                    status: 'published',
                },
                {
                    id: 'version-2',
                    curriculum_id: 'curriculum-1',
                    version_number: 2,
                    label: 'الثاني',
                    status: 'draft',
                },
            ]);
        }

        if (method === 'GET' && url.endsWith('/readiness')) {
            return Promise.resolve({
                ...readinessResponse(),
                curriculum_version: {
                    ...readinessResponse().curriculum_version,
                    id: 'version-2',
                },
            });
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

    it('does not expose late content from a previous subject context', async () => {
        const oldCurricula = deferred<Array<Record<string, unknown>>>();

        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/subjects') {
                return Promise.resolve([
                    { id: 'subject-1', name: 'الرياضيات' },
                    { id: 'subject-2', name: 'الفيزياء' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
                return oldCurricula.promise;
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-2/curricula') {
                return Promise.resolve([
                    { id: 'curriculum-2', subject_id: 'subject-2', name: 'منهج الفيزياء' },
                ]);
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-2/versions') {
                return Promise.resolve([
                    { id: 'version-2', curriculum_id: 'curriculum-2', version_number: 1, label: 'الأول', status: 'published' },
                ]);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPage('/admin/content?subject_id=subject-1');
        const subjectSelect = await screen.findByLabelText('المادة');

        fireEvent.change(subjectSelect, {
            target: { value: 'subject-2' },
        });

        expect(await screen.findByTestId('lessons-panel')).toBeInTheDocument();
        expect(screen.getByLabelText('المنهج')).toHaveValue('curriculum-2');

        oldCurricula.resolve([
            { id: 'curriculum-1', subject_id: 'subject-1', name: 'منهج الرياضيات القديم' },
        ]);

        await waitFor(() => {
            expect(screen.getByLabelText('المنهج')).toHaveValue('curriculum-2');
        });
        expect(screen.queryByText('منهج الرياضيات القديم')).not.toBeInTheDocument();
        expect(screen.getByLabelText('عنوان السياق الحالي')).toHaveTextContent(
            'subject_id=subject-2&curriculum_id=curriculum-2&version_id=version-2',
        );
    });

    it('exposes published lifecycle as a compact status while preserving read-only context', async () => {
        installContext('published');
        renderPage();

        expect(await screen.findByText('منشور')).toBeInTheDocument();
        fireEvent.click(await screen.findByRole('tab', { name: 'المهارات' }));
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

    it('restores an exact authorized version and section from the URL', async () => {
        installVersionedContext();
        renderPage(
            '/admin/content?subject_id=subject-1&curriculum_id=curriculum-1&version_id=version-2&section=exam-templates',
        );

        expect(await screen.findByTestId('exam-templates-panel'))
            .toBeInTheDocument();
        expect(screen.getByLabelText('إصدار المنهج')).toHaveValue('version-2');
        expect(screen.getByText('مسودة')).toBeInTheDocument();
        expect(screen.getByLabelText('عنوان السياق الحالي')).toHaveTextContent(
            'subject_id=subject-1&curriculum_id=curriculum-1&version_id=version-2&section=exam-templates',
        );
    });

    it('rejects an invalid explicit version without silently substituting another version', async () => {
        installVersionedContext();
        renderPage(
            '/admin/content?subject_id=subject-1&curriculum_id=curriculum-1&version_id=version-missing',
        );

        expect(
            await screen.findByText('إصدار المنهج المحدد غير متاح ضمن المنهج الحالي.'),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('lessons-panel')).not.toBeInTheDocument();
        expect(screen.getByLabelText('عنوان السياق الحالي')).toHaveTextContent(
            'version_id=version-missing',
        );
    });

    it('preserves exact version context across browser back and forward navigation', async () => {
        installVersionedContext();
        renderPage(
            '/admin/content?subject_id=subject-1&curriculum_id=curriculum-1&version_id=version-1',
        );

        const versionSelect = await screen.findByLabelText('إصدار المنهج');
        expect(versionSelect).toHaveValue('version-1');

        fireEvent.change(versionSelect, {
            target: { value: 'version-2' },
        });
        await waitFor(() => expect(versionSelect).toHaveValue('version-2'));

        fireEvent.click(screen.getByRole('button', { name: 'رجوع اختباري' }));
        await waitFor(() => expect(versionSelect).toHaveValue('version-1'));

        fireEvent.click(screen.getByRole('button', { name: 'تقدم اختباري' }));
        await waitFor(() => expect(versionSelect).toHaveValue('version-2'));
    });

    it('offers a read-only retry after version discovery fails', async () => {
        let versionAttempts = 0;
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/subjects') {
                return Promise.resolve([{ id: 'subject-1', name: 'الرياضيات' }]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
                return Promise.resolve([{ id: 'curriculum-1', subject_id: 'subject-1', name: 'المنهج' }]);
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
                versionAttempts += 1;
                return versionAttempts === 1
                    ? Promise.reject(new Error('read failed'))
                    : Promise.resolve([{ id: 'version-1', curriculum_id: 'curriculum-1', version_number: 1, label: 'الأول', status: 'published' }]);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPage(
            '/admin/content?subject_id=subject-1&curriculum_id=curriculum-1&version_id=version-1',
        );

        expect(await screen.findByText('تعذر تحميل إصدارات المنهج.')).toBeInTheDocument();
        expect(screen.queryByTestId('lessons-panel')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', {
            name: 'إعادة محاولة تحميل الإصدارات',
        }));

        expect(await screen.findByTestId('lessons-panel')).toBeInTheDocument();
        expect(versionAttempts).toBe(2);
    });

    it('shows contextual loading feedback while curriculum and version reads are pending', async () => {
        const curricula = deferred<Array<Record<string, unknown>>>();
        const versions = deferred<Array<Record<string, unknown>>>();

        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/subjects') {
                return Promise.resolve([{ id: 'subject-1', name: 'الرياضيات' }]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
                return curricula.promise;
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
                return versions.promise;
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPage(
            '/admin/content?subject_id=subject-1&curriculum_id=curriculum-1&version_id=version-1',
        );

        expect(await screen.findByText(
            'جار تحميل مناهج المادة…',
        )).toBeInTheDocument();
        expect(screen.queryByText(
            'جار تحميل إصدارات المنهج…',
        )).not.toBeInTheDocument();
        expect(screen.queryByText(
            'اختر منهجًا لعرض المحتوى.',
        )).not.toBeInTheDocument();

        curricula.resolve([{
            id: 'curriculum-1',
            subject_id: 'subject-1',
            name: 'المنهج',
        }]);

        expect(await screen.findByText(
            'جار تحميل إصدارات المنهج…',
        )).toBeInTheDocument();
        expect(screen.queryByTestId(
            'lessons-panel',
        )).not.toBeInTheDocument();

        versions.resolve([{
            id: 'version-1',
            curriculum_id: 'curriculum-1',
            version_number: 1,
            label: 'الأول',
            status: 'published',
        }]);

        expect(await screen.findByTestId(
            'lessons-panel',
        )).toBeInTheDocument();
    });

    it('retries a failed curriculum GET without changing the explicit URL context', async () => {
        let curriculumAttempts = 0;
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/subjects') {
                return Promise.resolve([{ id: 'subject-1', name: 'الرياضيات' }]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
                curriculumAttempts += 1;
                return curriculumAttempts === 1
                    ? Promise.reject(new Error('read failed'))
                    : Promise.resolve([{
                        id: 'curriculum-1',
                        subject_id: 'subject-1',
                        name: 'المنهج',
                    }]);
            }
            if (method === 'GET' && url === '/api/admin/curricula/curriculum-1/versions') {
                return Promise.resolve([{
                    id: 'version-1',
                    curriculum_id: 'curriculum-1',
                    version_number: 1,
                    label: 'الأول',
                    status: 'published',
                }]);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        const exactUrl =
            '/admin/content?subject_id=subject-1&curriculum_id=curriculum-1&version_id=version-1';
        renderPage(exactUrl);

        expect(await screen.findByText(
            'تعذر تحميل المناهج.',
        )).toBeInTheDocument();
        expect(screen.queryByText(
            'جار تحميل إصدارات المنهج…',
        )).not.toBeInTheDocument();
        expect(screen.getByLabelText(
            'عنوان السياق الحالي',
        )).toHaveTextContent(exactUrl);
        expect(screen.queryByTestId(
            'lessons-panel',
        )).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', {
            name: 'إعادة محاولة تحميل المناهج',
        }));

        expect(await screen.findByTestId(
            'lessons-panel',
        )).toBeInTheDocument();
        expect(curriculumAttempts).toBe(2);
        expect(apiRequestMock.mock.calls.every(
            ([request]) => request.method === 'GET',
        )).toBe(true);
        expect(screen.getByLabelText(
            'عنوان السياق الحالي',
        )).toHaveTextContent(exactUrl);
    });

    it('does not report version loading when curriculum discovery is empty', async () => {
        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (method === 'GET' && url === '/api/admin/subjects') {
                return Promise.resolve([{
                    id: 'subject-1',
                    name: 'الرياضيات',
                }]);
            }
            if (method === 'GET' && url === '/api/admin/subjects/subject-1/curricula') {
                return Promise.resolve([]);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPage(
            '/admin/content?subject_id=subject-1',
        );

        expect(await screen.findByText(
            'لا توجد مناهج متاحة لهذه المادة.',
        )).toBeInTheDocument();
        expect(screen.queryByText(
            'جار تحميل إصدارات المنهج…',
        )).not.toBeInTheDocument();
        expect(apiRequestMock).not.toHaveBeenCalledWith(
            expect.objectContaining({
                url: expect.stringContaining('/versions'),
            }),
        );
    });

    it('maintains valid ARIA tab relationships and RTL keyboard navigation', async () => {
        installContext();
        renderPage();
        await screen.findByTestId('lessons-panel');

        const tabs = screen.getAllByRole('tab');
        const panels = screen.getAllByRole('tabpanel', { hidden: true });
        expect(panels).toHaveLength(tabs.length);
        expect(panels.filter(
            (panel) => !panel.hasAttribute('hidden'),
        )).toHaveLength(1);
        expect(panels.filter(
            (panel) => panel.hasAttribute('hidden'),
        )).toHaveLength(tabs.length - 1);

        for (const tab of tabs) {
            const panelId = tab.getAttribute('aria-controls');
            const panel = document.getElementById(panelId ?? '');
            expect(panel).not.toBeNull();
            expect(panel).toHaveAttribute('aria-labelledby', tab.id);
        }

        const lessonsTab = screen.getByRole('tab', { name: 'الدروس' });
        lessonsTab.focus();
        fireEvent.keyDown(lessonsTab, { key: 'ArrowLeft' });
        expect(screen.getByRole('tab', { name: 'بنك الأسئلة' }))
            .toHaveAttribute('aria-selected', 'true');

        fireEvent.keyDown(screen.getByRole('tab', { name: 'بنك الأسئلة' }), {
            key: 'Home',
        });
        expect(screen.getByRole('tab', { name: 'الوحدات' }))
            .toHaveAttribute('aria-selected', 'true');

        fireEvent.keyDown(screen.getByRole('tab', { name: 'الوحدات' }), {
            key: 'End',
        });
        expect(screen.getByRole('tab', { name: 'مراجعة النشر' }))
            .toHaveAttribute('aria-selected', 'true');
    });

    it('renders teacher-owned curriculum as read-only inspection without authoring workspace', async () => {
        installContext(
            'draft',
            readinessResponse(),
            'assignment-owned-1',
        );

        renderPage();

        expect(
            await screen.findByRole(
                'heading',
                {
                    name:
                        'استعراض المحتوى',
                },
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /واجهة الـAdmin هنا للاستعراض فقط/
            ),
        ).toBeInTheDocument();

        expect(
            await screen.findByTestId(
                'teacher-owned-content-inspection',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /معلم الرياضيات/,
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /teacher-1/,
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /assignment-owned-1/,
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /المادة:\s*القدرات الكمية\s*\(\s*mathematics\s*\)/,
            ),
        ).toBeInTheDocument();

        expect(
            await screen.findByTestId(
                'readiness-panel',
            ),
        ).toHaveAttribute(
            'data-read-only',
            'true',
        );

        expect(
            screen.queryByRole('tab')
        ).not.toBeInTheDocument();

        expect(
            screen.queryByTestId(
                'lessons-panel'
            )
        ).not.toBeInTheDocument();

        expect(
            screen.queryByTestId(
                'assessment-items-panel'
            )
        ).not.toBeInTheDocument();

        expect(
            screen.queryByTestId(
                'exam-templates-panel'
            )
        ).not.toBeInTheDocument();
    });
});

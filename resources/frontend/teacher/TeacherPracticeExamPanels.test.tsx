import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
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
    TeacherExamTemplatesPanel,
} from './TeacherExamTemplatesPanel';
import {
    TeacherPracticeActivitiesPanel,
} from './TeacherPracticeActivitiesPanel';

interface RequestConfig {
    data?: unknown;
    method: string;
    signal?: AbortSignal;
    url: string;
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
const prefix = '/api/teacher/subject-assignments/assignment-a'
    + '/curricula/curriculum-a/versions/version-a';

function testClient() {
    return new QueryClient({
        defaultOptions: {
            mutations: { retry: false },
            queries: { retry: false },
        },
    });
}

function renderPractice(
    versionStatus: 'draft' | 'published' | 'retired' = 'draft',
    callbacks = {
        onContextUnavailable: vi.fn(),
        onDirtyChange: vi.fn(),
        onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
    },
) {
    render(
        <QueryClientProvider client={testClient()}>
            <TeacherPracticeActivitiesPanel
                authenticatedUserId="teacher-a"
                context={{ ...context, versionStatus }}
                {...callbacks}
            />
        </QueryClientProvider>,
    );

    return callbacks;
}

function renderExam(
    versionStatus: 'draft' | 'published' | 'retired' = 'draft',
    callbacks = {
        onContextUnavailable: vi.fn(),
        onDirtyChange: vi.fn(),
        onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
    },
) {
    render(
        <QueryClientProvider client={testClient()}>
            <TeacherExamTemplatesPanel
                authenticatedUserId="teacher-a"
                context={{ ...context, versionStatus }}
                {...callbacks}
            />
        </QueryClientProvider>,
    );

    return callbacks;
}

function practice(status: 'archived' | 'active' = 'archived') {
    return {
        curriculum_version_id: 'version-a',
        description: null,
        id: 'practice-a',
        lesson_id: null,
        name: 'تدريب الجبر',
        status,
    };
}

function template(status: 'active' | 'archived' = 'active') {
    return {
        curriculum_version_id: 'version-a',
        description: null,
        id: 'template-a',
        name: 'اختبار الجبر',
        published_version_id: 'template-version-a',
        status,
    };
}

function templateVersion(status: 'draft' | 'published' | 'retired' = 'draft') {
    return {
        curriculum_version_id: 'version-a',
        exam_template_id: 'template-a',
        id: 'template-version-a',
        label: 'v1',
        rules_payload: {},
        rules_schema_version: 1,
        status,
        version_number: 1,
    };
}

describe('Teacher Practice activities', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('reads exact-version practices and creates an archived Practice payload', async () => {
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/practice-activities')) {
                return Promise.resolve([
                    practice(),
                    { ...practice(), curriculum_version_id: 'version-b', id: 'foreign', name: 'مخفي' },
                ]);
            }
            if (config.method === 'GET' && config.url.endsWith('/lessons')) {
                return Promise.resolve([]);
            }

            return Promise.resolve(practice());
        });

        renderPractice();
        expect(await screen.findByText('تدريب الجبر')).toBeInTheDocument();
        expect(screen.queryByText('مخفي')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة تدريب' }));
        fireEvent.change(screen.getByLabelText('اسم التدريب'), {
            target: { value: 'تدريب جديد' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'إنشاء التدريب' }));

        await waitFor(() => expect(apiRequestMock).toHaveBeenCalledWith(
            expect.objectContaining({
                data: {
                    description: null,
                    lesson_id: null,
                    name: 'تدريب جديد',
                },
                method: 'POST',
                url: prefix + '/practice-activities',
            }),
        ));
    });

    it('preserves submitted revision order and server-provided membership order', async () => {
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method !== 'GET') return Promise.resolve([]);
            if (config.url.endsWith('/practice-activities')) return Promise.resolve([practice()]);
            if (config.url.endsWith('/lessons')) return Promise.resolve([]);
            if (config.url.endsWith('/practice-activities/practice-a/items')) {
                return Promise.resolve([
                    {
                        assessment_item_id: 'item-b',
                        assessment_item_revision_id: 'revision-b',
                        curriculum_version_id: 'version-a',
                        display_order: 8,
                        id: 'membership-b',
                        practice_activity_id: 'practice-a',
                    },
                    {
                        assessment_item_id: 'item-a',
                        assessment_item_revision_id: 'revision-a',
                        curriculum_version_id: 'version-a',
                        display_order: 2,
                        id: 'membership-a',
                        practice_activity_id: 'practice-a',
                    },
                ]);
            }
            if (config.url.endsWith('/assessment-items')) {
                return Promise.resolve([{
                    curriculum_version_id: 'version-a',
                    id: 'item-a',
                    internal_label: 'عنصر',
                    item_type: 'choice',
                    published_revision_id: 'revision-a',
                    status: 'published',
                }]);
            }
            if (config.url.endsWith('/assessment-items/item-a/revisions')) {
                return Promise.resolve([
                    {
                        assessment_item_id: 'item-a',
                        content_payload: {},
                        content_schema_version: 1,
                        curriculum_version_id: 'version-a',
                        difficulty: 'medium',
                        id: 'revision-a',
                        primary_topic_id: null,
                        released_at: '2026-10-11T00:00:00Z',
                        revision_number: 1,
                        scoring_payload: {},
                        scoring_schema_version: 1,
                    },
                    {
                        assessment_item_id: 'item-a',
                        content_payload: {},
                        content_schema_version: 1,
                        curriculum_version_id: 'version-a',
                        difficulty: 'medium',
                        id: 'revision-c',
                        primary_topic_id: null,
                        released_at: '2026-10-11T00:00:00Z',
                        revision_number: 3,
                        scoring_payload: {},
                        scoring_schema_version: 1,
                    },
                ]);
            }

            return Promise.resolve([]);
        });

        renderPractice();
        await screen.findByText('تدريب الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'العضويات' }));

        const membershipSection = await screen.findByLabelText('عضويات التدريب: تدريب الجبر');
        const membershipRows = await within(membershipSection).findAllByText(/الترتيب:/);
        expect(membershipRows[0]).toHaveTextContent('8');
        expect(membershipRows[1]).toHaveTextContent('2');

        fireEvent.change(screen.getByLabelText('عنصر تقييم عضوية التدريب'), {
            target: { value: 'item-a' },
        });
        await screen.findByRole('option', { name: 'نسخة 1' });
        fireEvent.change(screen.getByLabelText('نسخة تقييم عضوية التدريب'), {
            target: { value: 'revision-c' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'أضف إلى ترتيب الإرسال' }));
        fireEvent.change(screen.getByLabelText('نسخة تقييم عضوية التدريب'), {
            target: { value: 'revision-a' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'أضف إلى ترتيب الإرسال' }));
        fireEvent.click(screen.getByRole('button', { name: 'إضافة العضويات' }));

        await waitFor(() => expect(apiRequestMock).toHaveBeenCalledWith(
            expect.objectContaining({
                data: {
                    assessment_item_revision_ids: ['revision-c', 'revision-a'],
                    display_order: 0,
                },
                method: 'POST',
                url: prefix + '/practice-activities/practice-a/items',
            }),
        ));
    });

    it('isolates a late practice A mutation after selecting practice B', async () => {
        let rejectOldRequest!: (error: unknown) => void;
        const pending = new Promise<unknown>((_resolve, reject) => {
            rejectOldRequest = reject;
        });

        const unavailableCallback = vi.fn();
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/practice-activities')) {
                return Promise.resolve([
                    practice(),
                    { ...practice(), id: 'practice-b', name: 'تدريب الهندسة' },
                ]);
            }
            if (config.method === 'GET' && config.url.endsWith('/lessons')) {
                return Promise.resolve([]);
            }
            if (config.method === 'GET' && config.url.endsWith('/items')) {
                return Promise.resolve([{
                    assessment_item_id: 'item-a',
                    assessment_item_revision_id: 'revision-a',
                    curriculum_version_id: 'version-a',
                    display_order: 0,
                    id: 'membership-a',
                    practice_activity_id: config.url.includes('/practice-b/')
                        ? 'practice-b' : 'practice-a',
                }]);
            }
            if (config.method === 'DELETE'
                && config.url.endsWith('/practice-activities/practice-a/items/membership-a')) {
                return pending;
            }
            return Promise.resolve([]);
        });

        renderPractice('draft', {
            onContextUnavailable: unavailableCallback,
            onDirtyChange: vi.fn(),
            onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
        });

        const practiceA = (await screen.findByText('تدريب الجبر')).closest('article');
        expect(practiceA).not.toBeNull();

        fireEvent.click(
            within(practiceA!).getByRole('button', { name: 'العضويات' }),
        );

        const sectionA = await screen.findByLabelText(
            'عضويات التدريب: تدريب الجبر',
        );
        fireEvent.click(
            await within(sectionA).findByRole('button', { name: 'إزالة العضوية' }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    method: 'DELETE',
                    url: prefix + '/practice-activities/practice-a/items/membership-a',
                }),
            );
        });

        const practiceB = screen.getByText('تدريب الهندسة').closest('article');
        expect(practiceB).not.toBeNull();
        fireEvent.click(
            within(practiceB!).getByRole('button', { name: 'العضويات' }),
        );

        const sectionB = await screen.findByLabelText(
            'عضويات التدريب: تدريب الهندسة',
        );
        expect(sectionB).toBeInTheDocument();

        await act(async () => {
            rejectOldRequest(new EduCoreApiError({
                code: 'not_found',
                message: 'Practice A unavailable',
                requestId: 'late-practice-a',
                status: 404,
            }));
            try {
                await pending;
            } catch {
                // Expected rejection.
            }
        });

        expect(unavailableCallback).not.toHaveBeenCalled();
        expect(screen.getByLabelText('عضويات التدريب: تدريب الهندسة'))
            .toBeInTheDocument();
    });

    it('guards replacement of the practice edit form only when modified', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/practice-activities')) {
                return Promise.resolve([
                    practice(),
                    { ...practice(), id: 'practice-b', name: 'تدريب الهندسة' },
                ]);
            }
            return Promise.resolve([]);
        });

        renderPractice();
        const a = (await screen.findByText('تدريب الجبر')).closest('article');
        const b = screen.getByText('تدريب الهندسة').closest('article');
        expect(a).not.toBeNull();
        expect(b).not.toBeNull();

        fireEvent.click(within(a!).getByRole('button', { name: 'تعديل التدريب' }));
        confirm.mockClear();

        fireEvent.click(within(b!).getByRole('button', { name: 'تعديل التدريب' }));
        expect(confirm).not.toHaveBeenCalled();
        expect(screen.getByLabelText('اسم التدريب')).toHaveValue('تدريب الهندسة');

        fireEvent.change(screen.getByLabelText('اسم التدريب'), {
            target: { value: 'مسودة تدريب غير محفوظة' },
        });

        fireEvent.click(within(a!).getByRole('button', { name: 'تعديل التدريب' }));
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('اسم التدريب')).toHaveValue('مسودة تدريب غير محفوظة');

        confirm.mockReturnValue(true);
        fireEvent.click(within(a!).getByRole('button', { name: 'تعديل التدريب' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('اسم التدريب')).toHaveValue('تدريب الجبر');
    });

    it('guards practice create-to-edit replacement', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/practice-activities')) {
                return Promise.resolve([practice()]);
            }
            return Promise.resolve([]);
        });

        renderPractice();
        const article = (await screen.findByText('تدريب الجبر')).closest('article');
        expect(article).not.toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة تدريب' }));
        confirm.mockClear();

        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل التدريب' }));
        expect(confirm).not.toHaveBeenCalled();
        expect(screen.getByLabelText('اسم التدريب')).toHaveValue('تدريب الجبر');

        fireEvent.click(screen.getByRole('button', { name: 'إضافة تدريب' }));
        fireEvent.change(screen.getByLabelText('اسم التدريب'), {
            target: { value: 'مسودة إنشاء تدريب' },
        });

        confirm.mockClear();
        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل التدريب' }));
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('اسم التدريب')).toHaveValue('مسودة إنشاء تدريب');

        confirm.mockReturnValue(true);
        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل التدريب' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('اسم التدريب')).toHaveValue('تدريب الجبر');
    });

    it('guards dirty practice membership selection and hiding', async () => {
        const confirm = vi.spyOn(window, 'confirm');
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/practice-activities')) {
                return Promise.resolve([
                    practice(),
                    { ...practice(), id: 'practice-b', name: 'تدريب الهندسة' },
                ]);
            }
            return Promise.resolve([]);
        });

        renderPractice();
        const practiceA = (await screen.findByText('تدريب الجبر')).closest('article');
        const practiceB = screen.getByText('تدريب الهندسة').closest('article');
        expect(practiceA).not.toBeNull();
        expect(practiceB).not.toBeNull();

        fireEvent.click(within(practiceA!).getByRole('button', { name: 'العضويات' }));
        const sectionA = await screen.findByLabelText('عضويات التدريب: تدريب الجبر');

        fireEvent.change(within(sectionA).getByLabelText('ترتيب عضوية التدريب'), {
            target: { value: '5' },
        });

        confirm.mockClear();
        confirm.mockReturnValue(false);
        fireEvent.click(within(practiceB!).getByRole('button', { name: 'العضويات' }));
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('عضويات التدريب: تدريب الجبر')).toBeInTheDocument();

        fireEvent.click(within(practiceA!).getByRole('button', { name: 'إخفاء العضويات' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('عضويات التدريب: تدريب الجبر')).toBeInTheDocument();

        confirm.mockReturnValue(true);
        fireEvent.click(within(practiceB!).getByRole('button', { name: 'العضويات' }));
        expect(confirm).toHaveBeenCalledTimes(3);
        expect(await screen.findByLabelText('عضويات التدريب: تدريب الهندسة')).toBeInTheDocument();
        expect(screen.queryByLabelText('عضويات التدريب: تدريب الجبر')).not.toBeInTheDocument();
    });

    it('prevents removal of the last active membership and keeps historical reads read-only', async () => {
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.url.endsWith('/practice-activities')) return Promise.resolve([practice('active')]);
            if (config.url.endsWith('/lessons')) return Promise.resolve([]);
            if (config.url.endsWith('/items')) {
                return Promise.resolve([{
                    assessment_item_id: 'item-a',
                    assessment_item_revision_id: 'revision-a',
                    curriculum_version_id: 'version-a',
                    display_order: 0,
                    id: 'membership-a',
                    practice_activity_id: 'practice-a',
                }]);
            }
            if (config.url.endsWith('/assessment-items')) return Promise.resolve([]);

            return Promise.resolve([]);
        });

        renderPractice();
        await screen.findByText('تدريب الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'العضويات' }));
        expect(await screen.findByRole('button', { name: 'إزالة العضوية' })).toBeDisabled();
        expect(screen.getByText('لا يمكن إزالة العضوية الأخيرة من تدريب نشط.')).toBeInTheDocument();

        cleanup();
        renderPractice('published');
        const historicalPanels = await screen.findAllByText('تدريب الجبر');
        expect(historicalPanels.length).toBeGreaterThan(0);
        expect(screen.queryByRole('button', { name: 'إضافة تدريب' })).not.toBeInTheDocument();
    });
});

describe('Teacher Exam templates and generation', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('ignores a late 404 after the old exam context unmounts', async () => {
        let rejectRequest!: (error: unknown) => void;

        const pendingRequest = new Promise<unknown>((_resolve, reject) => {
            rejectRequest = reject;
        });

        const unavailableCallback = vi.fn();

        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([]);
            }

            if (config.method === 'POST' && config.url.endsWith('/exam-templates')) {
                return pendingRequest;
            }

            return Promise.resolve([]);
        });

        renderExam('draft', {
            onContextUnavailable: unavailableCallback,
            onDirtyChange: vi.fn(),
            onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
        });

        await screen.findByText('لا توجد نماذج امتحان.');

        fireEvent.click(
            screen.getByRole('button', { name: 'إضافة نموذج امتحان' }),
        );

        fireEvent.change(screen.getByLabelText('اسم نموذج الامتحان'), {
            target: { value: 'نموذج قديم' },
        });

        fireEvent.click(
            screen.getByRole('button', { name: 'إنشاء نموذج الامتحان' }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    method: 'POST',
                    url: prefix + '/exam-templates',
                }),
            );
        });

        cleanup();

        rejectRequest(new EduCoreApiError({
            code: 'not_found',
            message: 'السياق القديم غير متاح.',
            requestId: 'late-context-response',
            status: 404,
        }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalled();
        });

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(unavailableCallback).not.toHaveBeenCalled();
    });

    it('isolates a late template A mutation after selecting template B', async () => {
        let rejectOldRequest!: (error: unknown) => void;

        const pending = new Promise<unknown>((_resolve, reject) => {
            rejectOldRequest = reject;
        });

        const unavailableCallback = vi.fn();

        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([
                    template(),
                    {
                        ...template(),
                        id: 'template-b',
                        name: 'اختبار الهندسة',
                        published_version_id: null,
                    },
                ]);
            }

            if (config.method === 'GET' && config.url.endsWith('/versions')) {
                return Promise.resolve([]);
            }

            if (
                config.method === 'POST'
                && config.url.endsWith('/exam-templates/template-a/versions')
            ) {
                return pending;
            }

            return Promise.resolve([]);
        });

        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderExam('draft', {
            onContextUnavailable: unavailableCallback,
            onDirtyChange: vi.fn(),
            onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
        });

        await screen.findByText('اختبار الجبر');
        await screen.findByText('اختبار الهندسة');

        const templateA = screen.getByText('اختبار الجبر').closest('article');
        expect(templateA).not.toBeNull();

        fireEvent.click(
            within(templateA!).getByRole('button', { name: 'نسخ النموذج' }),
        );

        fireEvent.click(
            await screen.findByRole('button', { name: 'إضافة نسخة نموذج' }),
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'إنشاء نسخة النموذج' }),
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    method: 'POST',
                    url: prefix + '/exam-templates/template-a/versions',
                }),
            );
        });

        const templateB = screen.getByText('اختبار الهندسة').closest('article');
        expect(templateB).not.toBeNull();

        fireEvent.click(
            within(templateB!).getByRole('button', { name: 'نسخ النموذج' }),
        );

        expect(await screen.findByText('لا توجد نسخ لهذا النموذج.'))
            .toBeInTheDocument();
        expect(screen.getByLabelText(
            'نسخ نموذج الامتحان: اختبار الهندسة',
        )).toBeInTheDocument();


        await act(async () => {
            rejectOldRequest(new EduCoreApiError({
                code: 'not_found',
                message: 'Template A is unavailable.',
                requestId: 'late-template-a',
                status: 404,
            }));

            try {
                await pending;
            } catch {
                // Expected rejection from the old request.
            }
        });

        expect(unavailableCallback).not.toHaveBeenCalled();

        expect(screen.getByText('اختبار الهندسة'))
            .toBeInTheDocument();

        expect(screen.getByText('لا توجد نسخ لهذا النموذج.'))
            .toBeInTheDocument();
    });

    it('guards replacement of the exam template edit form only when modified', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([
                    template(),
                    { ...template(), id: 'template-b', name: 'اختبار الهندسة' },
                ]);
            }
            return Promise.resolve([]);
        });

        renderExam();
        const a = (await screen.findByText('اختبار الجبر')).closest('article');
        const b = screen.getByText('اختبار الهندسة').closest('article');
        expect(a).not.toBeNull();
        expect(b).not.toBeNull();

        fireEvent.click(within(a!).getByRole('button', { name: 'تعديل النموذج' }));
        confirm.mockClear();

        fireEvent.click(within(b!).getByRole('button', { name: 'تعديل النموذج' }));
        expect(confirm).not.toHaveBeenCalled();
        expect(screen.getByLabelText('اسم نموذج الامتحان')).toHaveValue('اختبار الهندسة');

        fireEvent.change(screen.getByLabelText('اسم نموذج الامتحان'), {
            target: { value: 'مسودة نموذج غير محفوظة' },
        });

        fireEvent.click(within(a!).getByRole('button', { name: 'تعديل النموذج' }));
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('اسم نموذج الامتحان')).toHaveValue('مسودة نموذج غير محفوظة');

        confirm.mockReturnValue(true);
        fireEvent.click(within(a!).getByRole('button', { name: 'تعديل النموذج' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('اسم نموذج الامتحان')).toHaveValue('اختبار الجبر');
    });

    it('guards exam template create-to-edit replacement', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([template()]);
            }
            return Promise.resolve([]);
        });

        renderExam();
        const article = (await screen.findByText('اختبار الجبر')).closest('article');
        expect(article).not.toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة نموذج امتحان' }));
        confirm.mockClear();

        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل النموذج' }));
        expect(confirm).not.toHaveBeenCalled();
        expect(screen.getByLabelText('اسم نموذج الامتحان')).toHaveValue('اختبار الجبر');

        fireEvent.click(screen.getByRole('button', { name: 'إضافة نموذج امتحان' }));
        fireEvent.change(screen.getByLabelText('اسم نموذج الامتحان'), {
            target: { value: 'مسودة إنشاء امتحان' },
        });

        confirm.mockClear();
        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل النموذج' }));
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('اسم نموذج الامتحان')).toHaveValue('مسودة إنشاء امتحان');

        confirm.mockReturnValue(true);
        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل النموذج' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('اسم نموذج الامتحان')).toHaveValue('اختبار الجبر');
    });

    it('guards dirty exam version selection and hiding', async () => {
        const confirm = vi.spyOn(window, 'confirm');
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([
                    template(),
                    { ...template(), id: 'template-b', name: 'اختبار الهندسة', published_version_id: null },
                ]);
            }
            return Promise.resolve([]);
        });

        renderExam();
        const templateA = (await screen.findByText('اختبار الجبر')).closest('article');
        const templateB = screen.getByText('اختبار الهندسة').closest('article');
        expect(templateA).not.toBeNull();
        expect(templateB).not.toBeNull();

        fireEvent.click(within(templateA!).getByRole('button', { name: 'نسخ النموذج' }));
        fireEvent.click(await screen.findByRole('button', { name: 'إضافة نسخة نموذج' }));
        fireEvent.change(screen.getByLabelText('تسمية نسخة نموذج الامتحان'), {
            target: { value: 'مسودة غير محفوظة' },
        });

        confirm.mockClear();
        confirm.mockReturnValue(false);
        fireEvent.click(within(templateB!).getByRole('button', { name: 'نسخ النموذج' }));
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('تسمية نسخة نموذج الامتحان')).toHaveValue('مسودة غير محفوظة');

        fireEvent.click(within(templateA!).getByRole('button', { name: 'إخفاء النسخ' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('تسمية نسخة نموذج الامتحان')).toHaveValue('مسودة غير محفوظة');

        confirm.mockReturnValue(true);
        fireEvent.click(within(templateB!).getByRole('button', { name: 'نسخ النموذج' }));
        expect(confirm).toHaveBeenCalledTimes(3);
        expect(await screen.findByLabelText('نسخ نموذج الامتحان: اختبار الهندسة')).toBeInTheDocument();
        expect(screen.queryByDisplayValue('مسودة غير محفوظة')).not.toBeInTheDocument();
    });

    it('guards replacement of draft exam versions using actual JSON changes', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([template()]);
            }

            if (config.method === 'GET' && config.url.endsWith('/versions')) {
                return Promise.resolve([
                    templateVersion(),
                    {
                        ...templateVersion(),
                        id: 'template-version-b',
                        version_number: 2,
                        label: 'v2',
                        rules_payload: { selection: 'second' },
                    },
                ]);
            }

            return Promise.resolve([]);
        });

        renderExam();

        await screen.findByText('اختبار الجبر');
        fireEvent.click(
            screen.getByRole('button', { name: 'نسخ النموذج' }),
        );

        const versionOne = (await screen.findByText('نسخة 1')).closest('article');
        const versionTwo = screen.getByText('نسخة 2').closest('article');

        expect(versionOne).not.toBeNull();
        expect(versionTwo).not.toBeNull();

        fireEvent.click(
            within(versionOne!).getByRole('button', { name: 'تعديل النسخة' }),
        );

        confirm.mockClear();

        // An unchanged editor must not require confirmation.
        fireEvent.click(
            within(versionTwo!).getByRole('button', { name: 'تعديل النسخة' }),
        );

        expect(confirm).not.toHaveBeenCalled();
        expect(screen.getByLabelText('قواعد نموذج الامتحان'))
            .toHaveValue(JSON.stringify({ selection: 'second' }, null, 2));

        // Changing JSON makes the current draft genuinely dirty.
        const changedRules = '{"selection":"unsaved"}';

        fireEvent.change(
            screen.getByLabelText('قواعد نموذج الامتحان'),
            { target: { value: changedRules } },
        );

        fireEvent.click(
            within(versionOne!).getByRole('button', { name: 'تعديل النسخة' }),
        );

        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('قواعد نموذج الامتحان'))
            .toHaveValue(changedRules);

        // Restoring the original value removes the local dirty condition.
        fireEvent.change(
            screen.getByLabelText('قواعد نموذج الامتحان'),
            {
                target: {
                    value: JSON.stringify({ selection: 'second' }, null, 2),
                },
            },
        );

        confirm.mockClear();

        fireEvent.click(
            within(versionOne!).getByRole('button', { name: 'تعديل النسخة' }),
        );

        expect(confirm).not.toHaveBeenCalled();
        expect(screen.getByLabelText('قواعد نموذج الامتحان'))
            .toHaveValue('{}');

        // A second dirty draft requires consent before replacement.
        fireEvent.change(
            screen.getByLabelText('قواعد نموذج الامتحان'),
            { target: { value: '{"unsaved":true}' } },
        );

        confirm.mockReturnValue(true);
        confirm.mockClear();

        fireEvent.click(
            within(versionTwo!).getByRole('button', { name: 'تعديل النسخة' }),
        );

        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('قواعد نموذج الامتحان'))
            .toHaveValue(JSON.stringify({ selection: 'second' }, null, 2));
    });

    it('guards exam version create-to-edit replacement', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([template()]);
            }
            if (config.method === 'GET' && config.url.endsWith('/versions')) {
                return Promise.resolve([templateVersion()]);
            }
            return Promise.resolve([]);
        });

        renderExam();
        await screen.findByText('اختبار الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'نسخ النموذج' }));

        const article = (await screen.findByText('نسخة 1')).closest('article');
        expect(article).not.toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة نسخة نموذج' }));
        confirm.mockClear();

        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل النسخة' }));
        expect(confirm).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة نسخة نموذج' }));
        fireEvent.change(screen.getByLabelText('قواعد نموذج الامتحان'), {
            target: { value: '{"draft":true}' },
        });

        confirm.mockClear();
        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل النسخة' }));
        expect(confirm).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('قواعد نموذج الامتحان'))
            .toHaveValue('{"draft":true}');

        confirm.mockReturnValue(true);
        fireEvent.click(within(article!).getByRole('button', { name: 'تعديل النسخة' }));
        expect(confirm).toHaveBeenCalledTimes(2);
        expect(screen.getByLabelText('قواعد نموذج الامتحان'))
            .toHaveValue('{}');
    });

    it('creates a template version with an empty JSON array and renders 422 details', async () => {
        const validation = new EduCoreApiError({
            code: 'validation_failed',
            details: {
                rules_schema_version: ['إصدار المخطط غير صالح.'],
            },
            message: 'بيانات غير صالحة.',
            requestId: 'request-validation',
            status: 422,
        });
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) {
                return Promise.resolve([template()]);
            }
            if (config.method === 'GET' && config.url.endsWith('/versions')) {
                return Promise.resolve([]);
            }
            if (config.method === 'POST' && config.url.endsWith('/versions')) {
                return Promise.reject(validation);
            }

            return Promise.resolve([]);
        });

        renderExam();
        await screen.findByText('اختبار الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'نسخ النموذج' }));
        fireEvent.click(await screen.findByRole('button', { name: 'إضافة نسخة نموذج' }));
        fireEvent.change(screen.getByLabelText('قواعد نموذج الامتحان'), {
            target: { value: '[]' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'إنشاء نسخة النموذج' }));

        expect(await screen.findByText('إصدار المخطط غير صالح.')).toBeInTheDocument();
        expect(screen.getByLabelText('قواعد نموذج الامتحان')).toHaveValue('[]');
        expect(apiRequestMock).toHaveBeenCalledWith(expect.objectContaining({
            data: {
                label: null,
                rules_payload: [],
                rules_schema_version: 1,
                version_number: 1,
            },
            method: 'POST',
        }));
    });

    it('keeps published history immutable and does not offer retirement for the current version', async () => {
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.url.endsWith('/exam-templates')) return Promise.resolve([template()]);
            if (config.url.endsWith('/versions')) return Promise.resolve([templateVersion('published')]);

            return Promise.resolve([]);
        });

        renderExam('published');
        await screen.findByText('اختبار الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'نسخ النموذج' }));
        expect(await screen.findByText('نسخة 1')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'تعديل النسخة' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'تقاعد النسخة' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'إنشاء توليد امتحان' })).toBeInTheDocument();
    });

    it('ignores a late generation success after the old exam editor unmounts', async () => {
        const user = userEvent.setup();
        let resolveGeneration!: (value: unknown) => void;
        const pendingGeneration = new Promise<unknown>((resolve) => {
            resolveGeneration = resolve;
        });

        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) return Promise.resolve([template()]);
            if (config.method === 'GET' && config.url.endsWith('/exam-templates/template-a/versions')) return Promise.resolve([templateVersion('published')]);
            if (config.method === 'GET' && config.url.endsWith('/assessment-items')) {
                return Promise.resolve([{
                    curriculum_version_id: 'version-a',
                    id: 'item-a',
                    internal_label: 'عنصر',
                    item_type: 'choice',
                    published_revision_id: 'revision-a',
                    status: 'published',
                }]);
            }
            if (config.method === 'GET' && config.url.endsWith('/assessment-items/item-a/revisions')) {
                return Promise.resolve(['b', 'a'].map((suffix, index) => ({
                    assessment_item_id: 'item-a',
                    content_payload: {},
                    content_schema_version: 1,
                    curriculum_version_id: 'version-a',
                    difficulty: 'medium',
                    id: 'revision-' + suffix,
                    primary_topic_id: null,
                    released_at: '2026-10-11T00:00:00Z',
                    revision_number: index + 1,
                    scoring_payload: {},
                    scoring_schema_version: 1,
                })));
            }
            if (config.method === 'POST' && config.url.endsWith('/generations')) {
                return pendingGeneration;
            }

            return Promise.resolve([]);
        });

        renderExam('published');
        await screen.findByText('اختبار الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'نسخ النموذج' }));
        fireEvent.click(await screen.findByRole('button', { name: 'إنشاء توليد امتحان' }));
        fireEvent.change(screen.getByLabelText('إصدار مولد الامتحان'), {
            target: { value: 'h6' },
        });
        fireEvent.change(screen.getByLabelText('بذرة توليد الامتحان'), {
            target: { value: 'seed-a' },
        });
        await screen.findByRole('option', { name: 'عنصر' });
        await user.selectOptions(screen.getByLabelText('عنصر تقييم توليد الامتحان'), 'item-a');
        await screen.findByRole('option', { name: 'نسخة 1' });
        await user.selectOptions(screen.getByLabelText('نسخة تقييم توليد الامتحان'), 'revision-b');
        fireEvent.click(screen.getByRole('button', { name: 'أضف إلى ترتيب الطلب' }));
        await user.selectOptions(screen.getByLabelText('نسخة تقييم توليد الامتحان'), 'revision-a');
        fireEvent.click(screen.getByRole('button', { name: 'أضف إلى ترتيب الطلب' }));
        fireEvent.click(screen.getByRole('button', { name: 'إنشاء الامتحان' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    method: 'POST',
                    url: prefix
                        + '/exam-templates/template-a/versions/template-version-a/generations',
                    data: {
                        assessment_item_revision_ids: ['revision-b', 'revision-a'],
                        generator_version: 'h6',
                        seed: 'seed-a',
                    },
                }),
            );
        });

        cleanup();

        // Mount a new editor instance while the old mutation remains pending.
        renderExam('published');
        await screen.findByText('اختبار الجبر');
        expect(
            screen.queryByLabelText('نتيجة توليد الامتحان'),
        ).not.toBeInTheDocument();

        await act(async () => {
            resolveGeneration({
                curriculum_version_id: 'version-a',
                exam_template_version_id: 'template-version-a',
                generated_at: '2026-10-11T00:00:00Z',
                id: 'generation-old',
                items: [
                    {
                        assessment_item_id: 'item-a',
                        assessment_item_revision_id: 'revision-b',
                    },
                    {
                        assessment_item_id: 'item-a',
                        assessment_item_revision_id: 'revision-a',
                    },
                ],
            });
            await pendingGeneration;
        });

        expect(
            screen.queryByLabelText('نتيجة توليد الامتحان'),
        ).not.toBeInTheDocument();

        expect(screen.queryByText(/generation-old/))
            .not.toBeInTheDocument();

    });

    it('creates one generation, preserves request order, and displays only the immediate result', async () => {
        const user = userEvent.setup();
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) return Promise.resolve([template()]);
            if (config.method === 'GET' && config.url.endsWith('/exam-templates/template-a/versions')) return Promise.resolve([templateVersion('published')]);
            if (config.method === 'GET' && config.url.endsWith('/assessment-items')) {
                return Promise.resolve([{
                    curriculum_version_id: 'version-a',
                    id: 'item-a',
                    internal_label: 'عنصر',
                    item_type: 'choice',
                    published_revision_id: 'revision-a',
                    status: 'published',
                }]);
            }
            if (config.method === 'GET' && config.url.endsWith('/assessment-items/item-a/revisions')) {
                return Promise.resolve(['b', 'a'].map((suffix, index) => ({
                    assessment_item_id: 'item-a',
                    content_payload: {},
                    content_schema_version: 1,
                    curriculum_version_id: 'version-a',
                    difficulty: 'medium',
                    id: 'revision-' + suffix,
                    primary_topic_id: null,
                    released_at: '2026-10-11T00:00:00Z',
                    revision_number: index + 1,
                    scoring_payload: {},
                    scoring_schema_version: 1,
                })));
            }
            if (config.method === 'POST' && config.url.endsWith('/generations')) {
                return Promise.resolve({
                    curriculum_version_id: 'version-a',
                    exam_template_version_id: 'template-version-a',
                    generated_at: '2026-10-11T00:00:00Z',
                    id: 'generation-a',
                    items: [
                        {
                            assessment_item_id: 'item-a',
                            assessment_item_revision_id: 'revision-b',
                        },
                        {
                            assessment_item_id: 'item-a',
                            assessment_item_revision_id: 'revision-a',
                        },
                    ],
                });
            }

            return Promise.resolve([]);
        });

        renderExam('published');
        await screen.findByText('اختبار الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'نسخ النموذج' }));
        fireEvent.click(await screen.findByRole('button', { name: 'إنشاء توليد امتحان' }));
        fireEvent.change(screen.getByLabelText('إصدار مولد الامتحان'), {
            target: { value: 'h6' },
        });
        fireEvent.change(screen.getByLabelText('بذرة توليد الامتحان'), {
            target: { value: 'seed-a' },
        });
        await screen.findByRole('option', { name: 'عنصر' });
        await user.selectOptions(screen.getByLabelText('عنصر تقييم توليد الامتحان'), 'item-a');
        await screen.findByRole('option', { name: 'نسخة 1' });
        await user.selectOptions(screen.getByLabelText('نسخة تقييم توليد الامتحان'), 'revision-b');
        fireEvent.click(screen.getByRole('button', { name: 'أضف إلى ترتيب الطلب' }));
        await user.selectOptions(screen.getByLabelText('نسخة تقييم توليد الامتحان'), 'revision-a');
        fireEvent.click(screen.getByRole('button', { name: 'أضف إلى ترتيب الطلب' }));
        fireEvent.click(screen.getByRole('button', { name: 'إنشاء الامتحان' }));

        const result = await screen.findByLabelText('نتيجة توليد الامتحان');
        expect(within(result).getByText('المعرف: generation-a')).toBeInTheDocument();
        expect(within(result).getByText(/ليس إثباتًا لترتيب التخزين/)).toBeInTheDocument();
        expect(apiRequestMock).toHaveBeenCalledWith(expect.objectContaining({
            data: {
                assessment_item_revision_ids: ['revision-b', 'revision-a'],
                generator_version: 'h6',
                seed: 'seed-a',
            },
            method: 'POST',
            url: prefix + '/exam-templates/template-a/versions/template-version-a/generations',
        }));
        expect(apiRequestMock.mock.calls.some((call) => {
            const config = call[0] as RequestConfig;

            return config.method === 'GET' && config.url.includes('/generations');
        })).toBe(false);
    });

    it('refreshes lifecycle authority after 409 while retaining rules input', async () => {
        const lifecycle = vi.fn().mockResolvedValue(undefined);
        const conflict = new EduCoreApiError({
            code: 'curriculum_version_not_draft',
            message: 'الإصدار لم يعد مسودة.',
            requestId: 'request-conflict',
            status: 409,
        });
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/exam-templates')) return Promise.resolve([template()]);
            if (config.method === 'GET' && config.url.endsWith('/versions')) return Promise.resolve([]);
            if (config.method === 'POST') return Promise.reject(conflict);

            return Promise.resolve([]);
        });

        renderExam('draft', {
            onContextUnavailable: vi.fn(),
            onDirtyChange: vi.fn(),
            onLifecycleConflict: lifecycle,
        });
        await screen.findByText('اختبار الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'نسخ النموذج' }));
        fireEvent.click(await screen.findByRole('button', { name: 'إضافة نسخة نموذج' }));
        fireEvent.change(screen.getByLabelText('قواعد نموذج الامتحان'), {
            target: { value: '{"count":2}' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'إنشاء نسخة النموذج' }));

        await waitFor(() => expect(lifecycle).toHaveBeenCalledTimes(1));
        expect(screen.getByLabelText('قواعد نموذج الامتحان')).toHaveValue('{"count":2}');
    });

    it('fails closed and clears protected content after a 404', async () => {
        const unavailable = new EduCoreApiError({
            code: 'not_found',
            message: 'غير موجود.',
            requestId: 'request-missing',
            status: 404,
        });
        const contextUnavailable = vi.fn();
        apiRequestMock.mockRejectedValue(unavailable);

        renderExam('draft', {
            onContextUnavailable: contextUnavailable,
            onDirtyChange: vi.fn(),
            onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
        });

        await waitFor(() => expect(contextUnavailable).toHaveBeenCalled());
        expect(screen.queryByText('اختبار الجبر')).not.toBeInTheDocument();
    });
});

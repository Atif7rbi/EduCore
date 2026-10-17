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
    TeacherAssessmentItemsPanel,
} from './TeacherAssessmentItemsPanel';
import {
    TeacherLessonsPanel,
} from './TeacherLessonsPanel';

interface RequestConfig {
    data?: unknown;
    method: string;
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

function renderPanel(
    panel: 'lesson' | 'assessment',
    versionStatus: 'draft' | 'published' | 'retired' = 'draft',
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
    const props = {
        authenticatedUserId: 'teacher-a',
        context: {
            ...context,
            versionStatus,
        },
        onContextUnavailable: vi.fn(),
        onDirtyChange: vi.fn(),
        onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
    };

    render(
        <QueryClientProvider client={client}>
            {panel === 'lesson'
                ? <TeacherLessonsPanel {...props} />
                : <TeacherAssessmentItemsPanel {...props} />}
        </QueryClientProvider>,
    );
}

describe('Teacher lesson and assessment panels', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('creates a lesson through the exact draft context and hides foreign results', async () => {
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET') {
                return Promise.resolve([
                    {
                        curriculum_version_id: 'version-a',
                        description: null,
                        display_order: 0,
                        id: 'lesson-a',
                        published_revision_id: null,
                        status: 'draft',
                        title: 'الجبر',
                    },
                    {
                        curriculum_version_id: 'version-b',
                        description: null,
                        display_order: 1,
                        id: 'lesson-b',
                        published_revision_id: null,
                        status: 'draft',
                        title: 'مخفي',
                    },
                ]);
            }

            return Promise.resolve({});
        });

        renderPanel('lesson');

        expect(await screen.findByText('الجبر')).toBeInTheDocument();
        expect(screen.queryByText('مخفي')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', {
            name: 'إضافة درس',
        }));
        fireEvent.change(screen.getByLabelText('عنوان الدرس'), {
            target: {
                value: 'الهندسة',
            },
        });
        fireEvent.click(screen.getByRole('button', {
            name: 'إنشاء الدرس',
        }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: {
                        description: null,
                        display_order: 0,
                        title: 'الهندسة',
                    },
                    method: 'POST',
                    url: '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/lessons',
                }),
            );
        });
    });

    it('keeps historical lessons readable without structural controls', async () => {
        apiRequestMock.mockResolvedValue([
            {
                curriculum_version_id: 'version-a',
                description: null,
                display_order: 0,
                id: 'lesson-a',
                published_revision_id: 'revision-a',
                status: 'published',
                title: 'الجبر',
            },
        ]);

        renderPanel('lesson', 'published');

        expect(await screen.findByText('الجبر')).toBeInTheDocument();
        expect(screen.queryByRole('button', {
            name: 'إضافة درس',
        })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', {
            name: 'تعديل',
        })).not.toBeInTheDocument();
    });

    it('creates an assessment item with its declared type in the exact context', async () => {
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET') return Promise.resolve([]);

            return Promise.resolve(config.method === 'GET' ? [] : {});
        });

        renderPanel('assessment');
        await screen.findByText('لا توجد عناصر تقييم في هذا الإصدار.');
        fireEvent.click(screen.getByRole('button', {
            name: 'إضافة عنصر تقييم',
        }));
        fireEvent.change(screen.getByLabelText('نوع عنصر التقييم'), {
            target: {
                value: 'multiple_choice',
            },
        });
        fireEvent.change(screen.getByLabelText('التسمية الداخلية لعنصر التقييم'), {
            target: {
                value: 'سؤال 1',
            },
        });
        fireEvent.click(screen.getByRole('button', {
            name: 'إنشاء العنصر',
        }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: {
                        internal_label: 'سؤال 1',
                        item_type: 'multiple_choice',
                    },
                    method: 'POST',
                    url: '/api/teacher/subject-assignments/assignment-a/curricula/curriculum-a/versions/version-a/assessment-items',
                }),
            );
        });
    });

    it('keeps published assessment items readable without retirement controls', async () => {
        apiRequestMock.mockResolvedValue([
            {
                curriculum_version_id: 'version-a',
                id: 'item-a',
                internal_label: 'سؤال',
                item_type: 'multiple_choice',
                published_revision_id: 'revision-a',
                status: 'published',
            },
        ]);

        renderPanel('assessment', 'published');

        expect(await screen.findByText('سؤال')).toBeInTheDocument();
        expect(screen.queryByRole('button', {
            name: 'تقاعد',
        })).not.toBeInTheDocument();
    });
});

describe('teacher lesson lifecycle conflicts', () => {
    it('refreshes lifecycle authority while preserving draft input after a 409', async () => {
        const conflict = new EduCoreApiError({
            code: 'teacher_authoring_conflict',
            message: 'الإصدار لم يعد مسودة.',
            requestId: 'request-a',
            status: 409,
        });
        const lifecycle = vi.fn().mockResolvedValue(undefined);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET') return Promise.resolve([]);

            return Promise.reject(conflict);
        });
        const client = new QueryClient({
            defaultOptions: {
                mutations: { retry: false },
                queries: { retry: false },
            },
        });

        render(
            <QueryClientProvider client={client}>
                <TeacherLessonsPanel
                    authenticatedUserId="teacher-a"
                    context={context}
                    onContextUnavailable={() => {}}
                    onDirtyChange={() => {}}
                    onLifecycleConflict={lifecycle}
                />
            </QueryClientProvider>,
        );

        await screen.findByText('لا توجد دروس في هذا الإصدار.');
        fireEvent.click(screen.getByRole('button', {
            name: 'إضافة درس',
        }));
        fireEvent.change(screen.getByLabelText('عنوان الدرس'), {
            target: { value: 'مسودة محفوظة' },
        });
        fireEvent.click(screen.getByRole('button', {
            name: 'إنشاء الدرس',
        }));

        await waitFor(() => expect(lifecycle).toHaveBeenCalledTimes(1));
        expect(screen.getByLabelText('عنوان الدرس')).toHaveValue('مسودة محفوظة');
    });
});

describe('selected parent lifecycle synchronization', () => {
    function client() {
        return new QueryClient({
            defaultOptions: {
                mutations: { retry: false },
                queries: { retry: false },
            },
        });
    }

    function assessment(status: 'draft' | 'published' | 'retired') {
        return {
            curriculum_version_id: 'version-a',
            id: 'item-a',
            internal_label: 'سؤال',
            item_type: 'multiple_choice',
            published_revision_id: status === 'draft' ? null : 'revision-a',
            status,
        };
    }

    it.each([
        ['published' as const, 'published'],
        ['retired' as const, 'retired'],
    ])('keeps a %s assessment item readable but removes revision authoring controls', async (status, _label) => {
        apiRequestMock.mockResolvedValue([assessment(status)]);

        renderPanel('assessment');

        await screen.findByText('سؤال');
        fireEvent.click(screen.getByRole('button', { name: 'النسخ' }));

        expect(await screen.findByText('هذه النسخ للقراءة فقط.')).toBeInTheDocument();
        expect(screen.queryByRole('button', {
            name: 'إضافة نسخة',
        })).not.toBeInTheDocument();
    });

    it('re-resolves an open assessment editor after publication and removes draft controls', async () => {
        let itemReads = 0;
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/assessment-items')) {
                itemReads += 1;

                return Promise.resolve([
                    assessment(itemReads === 1 ? 'draft' : 'published'),
                ]);
            }

            if (config.method === 'GET' && config.url.endsWith('/revisions')) {
                return Promise.resolve([
                    {
                        assessment_item_id: 'item-a',
                        content_payload: {},
                        content_schema_version: 1,
                        curriculum_version_id: 'version-a',
                        difficulty: 'medium',
                        id: 'revision-a',
                        primary_topic_id: null,
                        released_at: '2026-01-01T00:00:00Z',
                        revision_number: 1,
                        scoring_payload: {},
                        scoring_schema_version: 1,
                    },
                ]);
            }

            return Promise.resolve(config.method === 'GET' ? [] : {});
        });

        renderPanel('assessment');
        await screen.findByText('سؤال');
        fireEvent.click(screen.getByRole('button', { name: 'النسخ' }));
        expect(await screen.findByRole('button', {
            name: 'إضافة نسخة',
        })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'نشر' }));
        fireEvent.change(screen.getByLabelText('نسخة عنصر التقييم المنشورة'), {
            target: { value: 'revision-a' },
        });
        fireEvent.click(screen.getByRole('button', {
            name: 'تأكيد النشر',
        }));

        await waitFor(() => {
            expect(screen.getByText('هذه النسخ للقراءة فقط.')).toBeInTheDocument();
        });
        expect(screen.queryByRole('button', {
            name: 'إضافة نسخة',
        })).not.toBeInTheDocument();
        vi.restoreAllMocks();
    });

    it('closes an open lesson editor when its parent no longer exists after a lifecycle refetch', async () => {
        let lessonReads = 0;
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/lessons')) {
                lessonReads += 1;

                return Promise.resolve(lessonReads === 1 ? [
                    {
                        curriculum_version_id: 'version-a',
                        description: null,
                        display_order: 0,
                        id: 'lesson-a',
                        published_revision_id: 'revision-a',
                        status: 'published',
                        title: 'الجبر',
                    },
                ] : []);
            }

            return Promise.resolve([]);
        });

        renderPanel('lesson');
        await screen.findByText('الجبر');
        fireEvent.click(screen.getByRole('button', { name: 'النسخ' }));
        expect(await screen.findByText('نسخ الدرس: الجبر')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', {
            name: 'إلغاء النشر',
        }));

        await waitFor(() => {
            expect(screen.queryByText('نسخ الدرس: الجبر')).not.toBeInTheDocument();
        });
        vi.restoreAllMocks();
    });
});

describe('parent transitions use the latest exact-context record', () => {
    it('updates an open assessment from published to retired after the lifecycle refetch', async () => {
        let reads = 0;
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/assessment-items')) {
                reads += 1;

                return Promise.resolve([{
                    curriculum_version_id: 'version-a',
                    id: 'item-a',
                    internal_label: 'سؤال',
                    item_type: 'multiple_choice',
                    published_revision_id: 'revision-a',
                    status: reads === 1 ? 'published' : 'retired',
                }]);
            }

            return Promise.resolve(config.method === 'GET' ? [] : {});
        });

        renderPanel('assessment');
        await screen.findByText(/published/);
        fireEvent.click(screen.getByRole('button', { name: 'النسخ' }));
        expect(await screen.findByText('هذه النسخ للقراءة فقط.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'تقاعد' }));

        await waitFor(() => expect(screen.getByText(/retired/)).toBeInTheDocument());
        expect(screen.queryByRole('button', { name: 'تقاعد' })).not.toBeInTheDocument();
        vi.restoreAllMocks();
    });

    it('updates an open lesson from published to unpublished after unpublish', async () => {
        let reads = 0;
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/lessons')) {
                reads += 1;

                return Promise.resolve([{
                    curriculum_version_id: 'version-a',
                    description: null,
                    display_order: 0,
                    id: 'lesson-a',
                    published_revision_id: 'revision-a',
                    status: reads === 1 ? 'published' : 'unpublished',
                    title: 'الجبر',
                }]);
            }

            return Promise.resolve(config.method === 'GET' ? [] : {});
        });

        renderPanel('lesson');
        await screen.findByText(/published/);
        fireEvent.click(screen.getByRole('button', { name: 'النسخ' }));
        expect(await screen.findByText('نسخ الدرس: الجبر')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'إلغاء النشر' }));

        await waitFor(() => expect(screen.getByText(/unpublished/)).toBeInTheDocument());
        expect(screen.getByRole('button', { name: 'نشر' })).toBeInTheDocument();
        vi.restoreAllMocks();
    });
});

describe('assessment edit recovery after authoritative conflicts', () => {
    function renderAssessmentWithCallbacks(
        onContextUnavailable = vi.fn(),
        onLifecycleConflict = vi.fn().mockResolvedValue(undefined),
    ) {
        const queryClient = new QueryClient({
            defaultOptions: {
                mutations: { retry: false },
                queries: { retry: false },
            },
        });

        render(
            <QueryClientProvider client={queryClient}>
                <TeacherAssessmentItemsPanel
                    authenticatedUserId="teacher-a"
                    context={context}
                    onContextUnavailable={onContextUnavailable}
                    onDirtyChange={() => {}}
                    onLifecycleConflict={onLifecycleConflict}
                />
            </QueryClientProvider>,
        );

        return {
            onContextUnavailable,
            onLifecycleConflict,
        };
    }

    it.each([
        ['published' as const],
        ['retired' as const],
    ])('preserves entered values read-only when a 409 refresh returns %s', async (nextStatus) => {
        let reads = 0;
        const conflict = new EduCoreApiError({
            code: 'teacher_authoring_conflict',
            message: 'تغيّرت دورة حياة عنصر التقييم.',
            requestId: 'request-conflict',
            status: 409,
        });
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/assessment-items')) {
                reads += 1;

                return Promise.resolve([{
                    curriculum_version_id: 'version-a',
                    id: 'item-a',
                    internal_label: 'التسمية الأصلية',
                    item_type: 'original_type',
                    published_revision_id: nextStatus === 'published' ? 'revision-a' : 'revision-a',
                    status: reads === 1 ? 'draft' : nextStatus,
                }]);
            }

            if (config.method === 'PUT') {
                return Promise.reject(conflict);
            }

            return Promise.resolve(config.method === 'GET' ? [] : {});
        });

        const callbacks = renderAssessmentWithCallbacks();
        await screen.findByText('التسمية الأصلية');
        fireEvent.click(screen.getByRole('button', { name: 'تعديل' }));
        fireEvent.change(screen.getByLabelText('نوع عنصر التقييم'), {
            target: { value: 'recoverable_type' },
        });
        fireEvent.change(screen.getByLabelText('التسمية الداخلية لعنصر التقييم'), {
            target: { value: 'قيمة قابلة للاسترداد' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ العنصر' }));

        expect(await screen.findByLabelText('قيم تعديل عنصر التقييم المحفوظة')).toBeInTheDocument();
        expect(screen.getByLabelText('نوع العنصر المحفوظ للاسترداد')).toHaveValue('recoverable_type');
        expect(screen.getByLabelText('نوع العنصر المحفوظ للاسترداد')).toHaveAttribute('readonly');
        expect(screen.getByLabelText('التسمية الداخلية المحفوظة للاسترداد')).toHaveValue('قيمة قابلة للاسترداد');
        expect(screen.queryByRole('button', { name: 'حفظ العنصر' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'تعديل' })).not.toBeInTheDocument();
        expect(callbacks.onLifecycleConflict).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', {
            name: 'تجاهل القيم المحفوظة',
        }));
        expect(screen.queryByLabelText('قيم تعديل عنصر التقييم المحفوظة')).not.toBeInTheDocument();
        expect(screen.queryByDisplayValue('recoverable_type')).not.toBeInTheDocument();
    });

    it('discards local values and fails closed when the edited parent disappears after 409', async () => {
        let reads = 0;
        const conflict = new EduCoreApiError({
            code: 'teacher_authoring_conflict',
            message: 'لم يعد العنصر متاحًا.',
            requestId: 'request-missing',
            status: 409,
        });
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET' && config.url.endsWith('/assessment-items')) {
                reads += 1;

                return Promise.resolve(reads === 1 ? [{
                    curriculum_version_id: 'version-a',
                    id: 'item-a',
                    internal_label: 'عنصر سيختفي',
                    item_type: 'original_type',
                    published_revision_id: null,
                    status: 'draft',
                }] : []);
            }

            if (config.method === 'PUT') return Promise.reject(conflict);

            return Promise.resolve(config.method === 'GET' ? [] : {});
        });

        renderAssessmentWithCallbacks();
        await screen.findByText('عنصر سيختفي');
        fireEvent.click(screen.getByRole('button', { name: 'تعديل' }));
        fireEvent.change(screen.getByLabelText('نوع عنصر التقييم'), {
            target: { value: 'secret_draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ العنصر' }));

        await screen.findByText('لا توجد عناصر تقييم في هذا الإصدار.');
        expect(screen.queryByDisplayValue('secret_draft')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('قيم تعديل عنصر التقييم المحفوظة')).not.toBeInTheDocument();
    });

    it('clears local edit values and reports context loss on 403', async () => {
        const denied = new EduCoreApiError({
            code: 'teacher_forbidden',
            message: 'غير مصرح.',
            requestId: 'request-denied',
            status: 403,
        });
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.method === 'GET') {
                return Promise.resolve([{
                    curriculum_version_id: 'version-a',
                    id: 'item-a',
                    internal_label: 'عنصر',
                    item_type: 'original_type',
                    published_revision_id: null,
                    status: 'draft',
                }]);
            }

            return Promise.reject(denied);
        });
        const callbacks = renderAssessmentWithCallbacks();

        await screen.findByText('عنصر');
        fireEvent.click(screen.getByRole('button', { name: 'تعديل' }));
        fireEvent.change(screen.getByLabelText('نوع عنصر التقييم'), {
            target: { value: 'unauthorized_value' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ العنصر' }));

        await waitFor(() => {
            expect(callbacks.onContextUnavailable).toHaveBeenCalledTimes(1);
        });
        expect(screen.queryByDisplayValue('unauthorized_value')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('قيم تعديل عنصر التقييم المحفوظة')).not.toBeInTheDocument();
    });
});

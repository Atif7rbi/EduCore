import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    useState,
} from 'react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    MemoryRouter,
    Route,
    Routes,
    useNavigate,
} from 'react-router-dom';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    TeacherMonitoringDetailPage,
    TeacherMonitoringOverviewPage,
} from './TeacherMonitoringPages';

const authState = vi.hoisted(() => ({
    userId: 'teacher-1',
}));
const apiMocks = vi.hoisted(() => ({
    assignments: vi.fn(),
    attempts: vi.fn(),
    curricula: vi.fn(),
    enrollments: vi.fn(),
    enrollmentContext: vi.fn(),
    progress: vi.fn(),
    result: vi.fn(),
    versions: vi.fn(),
}));

vi.mock('../auth/AuthProvider', () => ({
    useAuth: () => ({
        status: 'authenticated',
        user: {
            id: authState.userId,
            name: 'المعلم التجريبي',
            email: 'teacher@example.test',
            role: 'teacher',
            status: 'active',
            learner_profile_id: null,
        },
    }),
}));

vi.mock('./api', async () => {
    const actual = await vi.importActual<typeof import('./api')>('./api');

    return {
        ...actual,
        fetchTeacherAssignments: apiMocks.assignments,
        fetchTeacherCurricula: apiMocks.curricula,
        fetchTeacherCurriculumVersions: apiMocks.versions,
    };
});

vi.mock('./enrollmentApi', async () => {
    const actual = await vi.importActual<typeof import('./enrollmentApi')>('./enrollmentApi');

    return {
        ...actual,
        fetchTeacherEnrollments: apiMocks.enrollments,
    };
});

vi.mock('./monitoringApi', async () => {
    const actual = await vi.importActual<typeof import('./monitoringApi')>('./monitoringApi');

    return {
        ...actual,
        fetchTeacherAttempts: apiMocks.attempts,
        fetchTeacherAttemptResult: apiMocks.result,
        fetchTeacherLessonProgress: apiMocks.progress,
        resolveTeacherMonitoringEnrollment: apiMocks.enrollmentContext,
    };
});

const assignment = {
    id: 'assignment-1',
    teacher_user_id: 'teacher-1',
    subject: {
        id: 'subject-1',
        code: 'mathematics',
        name: 'الرياضيات',
        status: 'active' as const,
    },
    status: 'active' as const,
    created_at: null,
    updated_at: null,
};

const enrollment = {
    id: 'enrollment-1',
    student: {
        user_id: 'student-1',
        name: 'الطالب التجريبي',
    },
    teacher_subject_assignment: {
        id: assignment.id,
        status: 'active' as const,
        subject: assignment.subject,
    },
    status: 'active' as const,
    created_at: '2026-10-20T10:00:00.000Z',
    accepted_at: '2026-10-21T10:00:00.000Z',
    updated_at: '2026-10-21T10:00:00.000Z',
};

const secondEnrollment = {
    ...enrollment,
    id: 'enrollment-2',
    student: {
        user_id: 'student-2',
        name: 'الطالب الثاني',
    },
    teacher_subject_assignment: {
        ...enrollment.teacher_subject_assignment,
        id: 'assignment-2',
        subject: {
            ...enrollment.teacher_subject_assignment.subject,
            id: 'subject-2',
            code: 'science',
            name: 'العلوم',
        },
    },
};

const scope = {
    enrollment: {
        id: enrollment.id,
        status: 'active' as const,
        student: enrollment.student,
    },
    teacher_subject_assignment: enrollment.teacher_subject_assignment,
    curriculum: {
        id: 'curriculum-1',
        name: 'منهج الرياضيات',
    },
    curriculum_version: {
        id: 'version-1',
        version_number: 1,
        label: 'الإصدار المنشور',
        status: 'published' as const,
    },
};

const submittedAttempt = {
    id: 'attempt-submitted',
    kind: 'practice' as const,
    source: {
        practice_activity_id: 'practice-1',
        exam_generation_id: null,
    },
    curriculum_version_id: 'version-1',
    status: 'submitted' as const,
    started_at: '2026-10-21T10:00:00.000Z',
    finalized_at: '2026-10-21T10:05:00.000Z',
    result: {
        availability: 'available' as const,
        summary: {
            answered: 2,
            correct: 1,
            incorrect: 1,
            unanswered: 1,
            total: 3,
        },
    },
};

const abandonedAttempt = {
    ...submittedAttempt,
    id: 'attempt-abandoned',
    kind: 'exam' as const,
    status: 'abandoned' as const,
    source: {
        practice_activity_id: null,
        exam_generation_id: 'generation-1',
    },
    result: {
        availability: 'not_applicable' as const,
        reason: 'attempt_abandoned' as const,
        summary: null,
    },
};

function enrollmentPage(items = [enrollment], currentPage = 1, lastPage = 1) {
    return {
        items,
        pagination: {
            current_page: currentPage,
            per_page: 20,
            total: items.length,
            last_page: lastPage,
        },
    };
}

function progressResponse(versionId = 'version-1', lessonTitle = 'درس الأعداد') {
    return {
        ...scope,
        curriculum_version: {
            ...scope.curriculum_version,
            id: versionId,
            label: versionId === 'version-1' ? 'الإصدار المنشور' : 'الإصدار المتقاعد',
            status: versionId === 'version-1' ? 'published' as const : 'retired' as const,
        },
        items: [
            {
                lesson: {
                    id: 'lesson-1',
                    title: lessonTitle,
                    display_order: 1,
                },
                lesson_revision: {
                    id: 'revision-1',
                    revision_number: 1,
                    released_at: '2026-10-20T10:00:00.000Z',
                },
                progress: {
                    status: 'completed' as const,
                    started_at: '2026-10-21T10:00:00.000Z',
                    completed_at: '2026-10-21T11:00:00.000Z',
                },
            },
            {
                lesson: {
                    id: 'lesson-2',
                    title: 'درس غير مبدوء',
                    display_order: 2,
                },
                lesson_revision: {
                    id: 'revision-2',
                    revision_number: 1,
                    released_at: '2026-10-20T10:00:00.000Z',
                },
                progress: {
                    status: 'not_recorded' as const,
                    started_at: null,
                    completed_at: null,
                },
            },
        ],
        recorded_started_count: 1,
        recorded_completed_count: 1,
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return {
        promise,
        reject,
        resolve,
    };
}

function MonitoringTestRoutes({ withControls }: { withControls: boolean }) {
    const navigate = useNavigate();
    const [, setPrincipalRevision] = useState(0);

    return (
        <>
            {withControls ? (
                <div>
                    <button
                        type="button"
                        onClick={() => navigate(
                            '/teacher/monitoring/enrollments/enrollment-2?curriculum_id=curriculum-2',
                        )}
                    >
                        انتقل إلى الطالب الثاني
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            authState.userId = 'teacher-2';
                            setPrincipalRevision((current) => current + 1);
                        }}
                    >
                        بدّل هوية المعلم
                    </button>
                </div>
            ) : null}
            <Routes>
                <Route path="/teacher/monitoring" element={<TeacherMonitoringOverviewPage />} />
                <Route path="/teacher/monitoring/enrollments/:enrollmentId" element={<TeacherMonitoringDetailPage />} />
            </Routes>
        </>
    );
}

function renderMonitoring(path: string, withControls = false) {
    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
        },
    });

    render(
        <MemoryRouter initialEntries={[path]}>
            <QueryClientProvider client={queryClient}>
                <MonitoringTestRoutes withControls={withControls} />
            </QueryClientProvider>
        </MemoryRouter>,
    );

    return queryClient;
}

describe('TeacherMonitoringPages', () => {
    beforeEach(() => {
        authState.userId = 'teacher-1';
        Object.values(apiMocks).forEach((mock) => mock.mockReset());
        apiMocks.assignments.mockResolvedValue([assignment]);
        apiMocks.enrollments.mockResolvedValue(enrollmentPage());
        apiMocks.enrollmentContext.mockResolvedValue(enrollment);
        apiMocks.curricula.mockResolvedValue([
            {
                id: 'curriculum-1',
                subject_id: 'subject-1',
                education_stage_id: null,
                teacher_subject_assignment_id: 'assignment-1',
                name: 'منهج الرياضيات',
            },
        ]);
        apiMocks.versions.mockResolvedValue([
            {
                id: 'version-1',
                curriculum_id: 'curriculum-1',
                version_number: 1,
                label: 'الإصدار المنشور',
                status: 'published',
            },
            {
                id: 'version-2',
                curriculum_id: 'curriculum-1',
                version_number: 2,
                label: 'الإصدار المتقاعد',
                status: 'retired',
            },
            {
                id: 'version-draft',
                curriculum_id: 'curriculum-1',
                version_number: 3,
                label: 'مسودة خاصة',
                status: 'draft',
            },
        ]);
        apiMocks.progress.mockResolvedValue(progressResponse());
        apiMocks.attempts.mockResolvedValue({
            ...scope,
            items: [submittedAttempt, abandonedAttempt],
            pagination: {
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 2,
            },
        });
        apiMocks.result.mockResolvedValue({
            ...scope,
            attempt: submittedAttempt,
        });
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('lists active students with assignment filtering and scoped detail links', async () => {
        renderMonitoring('/teacher/monitoring');

        expect(await screen.findByText('الطالب التجريبي')).toBeInTheDocument();
        expect(screen.getAllByText('الرياضيات')).toHaveLength(2);
        expect(screen.getByText('حالة الالتحاق: نشط')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'فتح المتابعة' })).toHaveAttribute(
            'href',
            '/teacher/monitoring/enrollments/enrollment-1?assignment_id=assignment-1',
        );

        fireEvent.change(screen.getByLabelText('التكليف والمادة'), {
            target: { value: 'assignment-1' },
        });

        await waitFor(() => {
            expect(apiMocks.enrollments).toHaveBeenLastCalledWith(
                'active',
                1,
                expect.any(AbortSignal),
                'assignment-1',
            );
        });
    });

    it('maps every supported enrollment status and does not mask an unknown value as pending', async () => {
        apiMocks.enrollments.mockResolvedValue(enrollmentPage([
            enrollment,
            {
                ...enrollment,
                id: 'enrollment-pending',
                student: {
                    ...enrollment.student,
                    name: 'طالب قيد الانتظار',
                },
                status: 'pending' as 'active',
            },
            {
                ...enrollment,
                id: 'enrollment-unknown',
                student: {
                    ...enrollment.student,
                    name: 'طالب بحالة غير مدعومة',
                },
                status: 'unexpected' as 'active',
            },
        ]));

        renderMonitoring('/teacher/monitoring');

        expect(await screen.findByText('حالة الالتحاق: نشط')).toBeInTheDocument();
        expect(screen.getByText('حالة الالتحاق: قيد الانتظار')).toBeInTheDocument();
        expect(screen.getByText('حالة الالتحاق: حالة غير معروفة')).toBeInTheDocument();
        expect(screen.getAllByText('حالة الالتحاق: قيد الانتظار')).toHaveLength(1);
    });

    it('uses the same unknown-status fallback in the enrollment identity heading', async () => {
        apiMocks.enrollmentContext.mockResolvedValueOnce({
            ...enrollment,
            status: 'unexpected' as 'active',
        });

        renderMonitoring('/teacher/monitoring/enrollments/enrollment-1');

        expect(await screen.findByText('حالة غير معروفة')).toBeInTheDocument();
        expect(screen.queryByText('قيد الانتظار')).not.toBeInTheDocument();
    });

    it('shows an understandable empty overview and read-only retry on failure', async () => {
        apiMocks.enrollments.mockRejectedValueOnce(new Error('read failed'));
        renderMonitoring('/teacher/monitoring');

        expect(await screen.findByRole('alert')).toHaveTextContent('تعذر تحميل الطلاب');
        apiMocks.enrollments.mockResolvedValueOnce(enrollmentPage([]));
        fireEvent.click(screen.getByRole('button', { name: 'إعادة المحاولة' }));
        expect(await screen.findByText('لا يوجد طلاب نشطون ضمن هذا النطاق.')).toBeInTheDocument();
    });

    it('paginates the active-student overview without losing its assignment filter', async () => {
        apiMocks.enrollments.mockResolvedValue(
            enrollmentPage([enrollment], 1, 2),
        );
        renderMonitoring('/teacher/monitoring?assignment_id=assignment-1');

        fireEvent.click(await screen.findByRole('button', { name: 'التالي' }));

        await waitFor(() => {
            expect(apiMocks.enrollments).toHaveBeenLastCalledWith(
                'active',
                2,
                expect.any(AbortSignal),
                'assignment-1',
            );
        });
    });

    it('requires an explicit curriculum and authorized version selection before reading progress', async () => {
        const user = userEvent.setup();
        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?assignment_id=assignment-1',
        );

        expect(await screen.findByText('اختر المنهج ثم الإصدار لعرض بيانات المتابعة.')).toBeInTheDocument();
        expect(apiMocks.progress).not.toHaveBeenCalled();

        await screen.findByRole('option', {
            name: 'منهج الرياضيات',
        });
        await user.selectOptions(
            screen.getByLabelText('المنهج'),
            'curriculum-1',
        );
        await waitFor(() => {
            expect(apiMocks.versions).toHaveBeenCalledWith(
                'assignment-1',
                'curriculum-1',
                expect.any(AbortSignal),
            );
        });
        await screen.findByRole('option', {
            name: /الإصدار 1 — الإصدار المنشور/,
        });
        await user.selectOptions(
            screen.getByLabelText('إصدار المنهج'),
            'version-1',
        );

        expect(await screen.findByText('درس الأعداد')).toBeInTheDocument();
        expect(apiMocks.progress).toHaveBeenCalledWith(
            'enrollment-1',
            'version-1',
            expect.any(AbortSignal),
        );
    });

    it('discovers only published and retired versions and renders recorded facts', async () => {
        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?assignment_id=assignment-1&curriculum_id=curriculum-1&version_id=version-1',
        );

        expect(await screen.findByText('درس الأعداد')).toBeInTheDocument();
        expect(screen.getByText('مكتمل')).toBeInTheDocument();
        expect(screen.getByText('غير مسجل')).toBeInTheDocument();
        expect(screen.getByText('بدأ: 1')).toBeInTheDocument();
        expect(screen.getByText('أكمل: 1')).toBeInTheDocument();
        expect(screen.queryByText('مسودة خاصة')).not.toBeInTheDocument();
        expect(screen.queryByText('teacher@example.test')).not.toBeInTheDocument();
    });

    it('keeps a late old-version response from replacing the selected version', async () => {
        let resolveOld!: (value: ReturnType<typeof progressResponse>) => void;
        const oldResponse = new Promise<ReturnType<typeof progressResponse>>((resolve) => {
            resolveOld = resolve;
        });
        let oldSignal: AbortSignal | undefined;
        apiMocks.progress.mockImplementation((_: string, versionId: string, signal?: AbortSignal) => {
            if (versionId === 'version-1') {
                oldSignal = signal;
                return oldResponse;
            }

            return Promise.resolve(progressResponse('version-2', 'درس الإصدار المتقاعد'));
        });

        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?assignment_id=assignment-1&curriculum_id=curriculum-1&version_id=version-1',
        );

        await screen.findByText('جارٍ تحميل تقدم الدروس...');
        fireEvent.change(screen.getByLabelText('إصدار المنهج'), {
            target: { value: 'version-2' },
        });

        expect(await screen.findByText('درس الإصدار المتقاعد')).toBeInTheDocument();
        expect(oldSignal?.aborted).toBe(true);
        resolveOld(progressResponse('version-1', 'درس قديم متأخر'));
        await Promise.resolve();
        expect(screen.queryByText('درس قديم متأخر')).not.toBeInTheDocument();
    });

    it('renders finalized attempts and explicit abandoned semantics', async () => {
        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?assignment_id=assignment-1&curriculum_id=curriculum-1&version_id=version-1&tab=attempts',
        );

        expect(await screen.findByText('محاولة ممارسة')).toBeInTheDocument();
        expect(screen.getByText('محاولة اختبار')).toBeInTheDocument();
        expect(screen.getByText('لا تتوفر نتيجة للمحاولة المتروكة.')).toBeInTheDocument();
        expect(screen.getByText('صحيح')).toBeInTheDocument();
        expect(screen.queryByText(/raw|answer|email/i)).not.toBeInTheDocument();
    });

    it('uses the result endpoint only for submitted result summaries', async () => {
        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?assignment_id=assignment-1&curriculum_id=curriculum-1&version_id=version-1&tab=results',
        );

        fireEvent.click(await screen.findByRole('button', { name: 'عرض ملخص النتيجة' }));

        expect(await screen.findByText('ملخص النتيجة المعتمد')).toBeInTheDocument();
        expect(apiMocks.result).toHaveBeenCalledWith(
            'enrollment-1',
            'attempt-submitted',
            expect.any(AbortSignal),
        );
        expect(screen.getByText('لا تنطبق نتيجة على المحاولة المتروكة.')).toBeInTheDocument();
    });

    it('preserves attempt filters and paginates without mutating attempts', async () => {
        apiMocks.attempts.mockResolvedValue({
            ...scope,
            items: [submittedAttempt],
            pagination: {
                current_page: 1,
                last_page: 2,
                per_page: 20,
                total: 21,
            },
        });
        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?assignment_id=assignment-1&curriculum_id=curriculum-1&version_id=version-1&tab=attempts&kind=practice&status=submitted',
        );

        await screen.findByText('محاولة ممارسة');
        fireEvent.click(screen.getByRole('button', { name: 'التالي' }));

        await waitFor(() => {
            expect(apiMocks.attempts).toHaveBeenLastCalledWith(
                'enrollment-1',
                'version-1',
                'practice',
                'submitted',
                2,
                expect.any(AbortSignal),
            );
        });
    });

    it('resolves a standalone detail route through the authenticated Teacher enrollment scope', async () => {
        renderMonitoring('/teacher/monitoring/enrollments/enrollment-1');

        expect(await screen.findByText('الطالب التجريبي')).toBeInTheDocument();
        expect(screen.getByText('حالة الالتحاق')).toBeInTheDocument();
        expect(screen.getByText('نشط')).toBeInTheDocument();
        expect(apiMocks.enrollmentContext).toHaveBeenCalledWith(
            'enrollment-1',
            expect.any(AbortSignal),
        );
        expect(apiMocks.curricula).toHaveBeenCalledWith(
            'assignment-1',
            expect.any(AbortSignal),
        );
        expect(apiMocks.progress).not.toHaveBeenCalled();
        expect(apiMocks.attempts).not.toHaveBeenCalled();
    });

    it('denies a foreign or inactive standalone enrollment without trusting the assignment hint', async () => {
        apiMocks.enrollmentContext.mockResolvedValueOnce(null);

        renderMonitoring(
            '/teacher/monitoring/enrollments/foreign-enrollment?assignment_id=assignment-1',
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'هذا الالتحاق غير متاح للمعلم الحالي',
        );
        expect(apiMocks.curricula).not.toHaveBeenCalled();
        expect(apiMocks.progress).not.toHaveBeenCalled();
    });

    it('shows version discovery loading and retries only the failed read', async () => {
        const firstRead = deferred<Array<{
            id: string;
            curriculum_id: string;
            version_number: number;
            label: string;
            status: 'published';
        }>>();
        apiMocks.versions.mockReturnValueOnce(firstRead.promise);

        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?curriculum_id=curriculum-1',
        );

        expect(await screen.findByText('جارٍ تحميل إصدارات المنهج المخولة...')).toBeInTheDocument();
        firstRead.reject(new Error('version discovery failed'));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'تعذر تحميل إصدارات المنهج المخولة',
        );
        expect(screen.queryByRole('option', { name: /إصدار معتمد/ })).not.toBeInTheDocument();

        apiMocks.versions.mockResolvedValueOnce([
            {
                id: 'version-retry',
                curriculum_id: 'curriculum-1',
                version_number: 4,
                label: 'إصدار معتمد',
                status: 'published',
            },
        ]);
        fireEvent.click(screen.getByRole('button', { name: 'إعادة تحميل الإصدارات' }));

        expect(await screen.findByRole('option', { name: /إصدار معتمد/ })).toBeInTheDocument();
        expect(apiMocks.versions).toHaveBeenCalledTimes(2);
        expect(apiMocks.curricula).toHaveBeenCalledTimes(1);
        expect(apiMocks.progress).not.toHaveBeenCalled();
    });

    it('implements a complete direction-aware RTL tab keyboard contract', async () => {
        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?curriculum_id=curriculum-1&version_id=version-1',
        );

        const progressTab = await screen.findByRole('tab', { name: 'التقدم' });
        const attemptsTab = screen.getByRole('tab', { name: 'المحاولات' });
        const resultsTab = screen.getByRole('tab', { name: 'النتائج' });

        expect(progressTab).toHaveAttribute('aria-selected', 'true');
        expect(progressTab).toHaveAttribute(
            'aria-controls',
            'teacher-monitoring-enrollment-1-progress-panel',
        );
        expect(screen.getByRole('tabpanel')).toHaveAttribute(
            'aria-labelledby',
            'teacher-monitoring-enrollment-1-progress-tab',
        );
        const allPanels = screen.getAllByRole('tabpanel', { hidden: true });
        expect(allPanels).toHaveLength(3);
        [progressTab, attemptsTab, resultsTab].forEach((tabElement) => {
            const controlledId = tabElement.getAttribute('aria-controls');
            const panel = controlledId
                ? document.getElementById(controlledId)
                : null;

            expect(panel).not.toBeNull();
            expect(panel).toHaveAttribute('aria-labelledby', tabElement.id);
        });
        expect(document.getElementById(
            'teacher-monitoring-enrollment-1-progress-panel',
        )).not.toHaveAttribute('hidden');
        expect(document.getElementById(
            'teacher-monitoring-enrollment-1-attempts-panel',
        )).toHaveAttribute('hidden');
        expect(document.getElementById(
            'teacher-monitoring-enrollment-1-results-panel',
        )).toHaveAttribute('hidden');
        expect(screen.getAllByRole('tabpanel')).toHaveLength(1);
        expect(apiMocks.attempts).not.toHaveBeenCalled();
        expect(apiMocks.result).not.toHaveBeenCalled();

        fireEvent.keyDown(progressTab, { key: 'ArrowLeft' });
        await waitFor(() => expect(attemptsTab).toHaveAttribute('aria-selected', 'true'));
        expect(attemptsTab).toHaveFocus();
        expect(progressTab).toHaveAttribute('tabindex', '-1');
        expect(document.getElementById(
            'teacher-monitoring-enrollment-1-progress-panel',
        )).toHaveAttribute('hidden');
        expect(document.getElementById(
            'teacher-monitoring-enrollment-1-attempts-panel',
        )).not.toHaveAttribute('hidden');
        expect(screen.getAllByRole('tabpanel')).toHaveLength(1);
        expect(apiMocks.attempts).toHaveBeenCalledTimes(1);
        expect(apiMocks.result).not.toHaveBeenCalled();

        fireEvent.keyDown(attemptsTab, { key: 'End' });
        await waitFor(() => expect(resultsTab).toHaveAttribute('aria-selected', 'true'));
        expect(resultsTab).toHaveFocus();
        expect(document.getElementById(
            'teacher-monitoring-enrollment-1-results-panel',
        )).not.toHaveAttribute('hidden');

        fireEvent.keyDown(resultsTab, { key: 'Home' });
        await waitFor(() => expect(progressTab).toHaveAttribute('aria-selected', 'true'));
        expect(progressTab).toHaveFocus();

        fireEvent.keyDown(progressTab, { key: 'ArrowRight' });
        await waitFor(() => expect(resultsTab).toHaveAttribute('aria-selected', 'true'));
    });

    it('does not expose an enrollment response that resolves after navigating to another student', async () => {
        const oldEnrollment = deferred<typeof enrollment>();
        apiMocks.enrollmentContext.mockImplementation((enrollmentId: string) => (
            enrollmentId === 'enrollment-1'
                ? oldEnrollment.promise
                : Promise.resolve(secondEnrollment)
        ));

        renderMonitoring('/teacher/monitoring/enrollments/enrollment-1', true);
        expect(await screen.findByText('جارٍ التحقق من نطاق الطالب المخول...')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'انتقل إلى الطالب الثاني' }));

        expect(await screen.findByText('الطالب الثاني')).toBeInTheDocument();
        oldEnrollment.resolve(enrollment);
        await Promise.resolve();
        expect(screen.queryByText('الطالب التجريبي')).not.toBeInTheDocument();
    });

    it('isolates enrollment discovery when the authenticated Teacher identity changes', async () => {
        const oldPrincipalRead = deferred<typeof enrollment>();
        const teacherTwoEnrollment = {
            ...enrollment,
            student: {
                user_id: 'student-for-teacher-2',
                name: 'طالب المعلم الثاني',
            },
        };
        apiMocks.enrollmentContext.mockImplementation(() => (
            authState.userId === 'teacher-1'
                ? oldPrincipalRead.promise
                : Promise.resolve(teacherTwoEnrollment)
        ));

        renderMonitoring('/teacher/monitoring/enrollments/enrollment-1', true);
        await screen.findByText('جارٍ التحقق من نطاق الطالب المخول...');
        fireEvent.click(screen.getByRole('button', { name: 'بدّل هوية المعلم' }));

        expect(await screen.findByText('طالب المعلم الثاني')).toBeInTheDocument();
        oldPrincipalRead.resolve(enrollment);
        await Promise.resolve();
        expect(screen.queryByText('الطالب التجريبي')).not.toBeInTheDocument();
    });

    it('ignores a late version-discovery response after navigating to another enrollment', async () => {
        const oldVersions = deferred<Array<{
            id: string;
            curriculum_id: string;
            version_number: number;
            label: string;
            status: 'published';
        }>>();
        apiMocks.enrollmentContext.mockImplementation((enrollmentId: string) => Promise.resolve(
            enrollmentId === 'enrollment-1' ? enrollment : secondEnrollment,
        ));
        apiMocks.curricula.mockImplementation((assignmentId: string) => Promise.resolve([
            assignmentId === 'assignment-1'
                ? {
                    id: 'curriculum-1',
                    subject_id: 'subject-1',
                    education_stage_id: null,
                    teacher_subject_assignment_id: 'assignment-1',
                    name: 'منهج الرياضيات',
                }
                : {
                    id: 'curriculum-2',
                    subject_id: 'subject-2',
                    education_stage_id: null,
                    teacher_subject_assignment_id: 'assignment-2',
                    name: 'منهج العلوم',
                },
        ]));
        apiMocks.versions.mockImplementation((_: string, curriculumId: string) => (
            curriculumId === 'curriculum-1'
                ? oldVersions.promise
                : Promise.resolve([
                    {
                        id: 'science-version',
                        curriculum_id: 'curriculum-2',
                        version_number: 1,
                        label: 'إصدار العلوم',
                        status: 'published',
                    },
                ])
        ));

        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?curriculum_id=curriculum-1',
            true,
        );
        await screen.findByText('جارٍ تحميل إصدارات المنهج المخولة...');
        fireEvent.click(screen.getByRole('button', { name: 'انتقل إلى الطالب الثاني' }));

        expect(await screen.findByRole('option', { name: /إصدار العلوم/ })).toBeInTheDocument();
        oldVersions.resolve([
            {
                id: 'late-version',
                curriculum_id: 'curriculum-1',
                version_number: 9,
                label: 'إصدار قديم متأخر',
                status: 'published',
            },
        ]);
        await Promise.resolve();
        expect(screen.queryByRole('option', { name: /إصدار قديم متأخر/ })).not.toBeInTheDocument();
    });

    it('does not reveal a late attempt result after changing enrollment context', async () => {
        const oldResult = deferred<typeof scope & { attempt: typeof submittedAttempt }>();
        let oldSignal: AbortSignal | undefined;
        apiMocks.result.mockImplementation((_: string, __: string, signal?: AbortSignal) => {
            oldSignal = signal;
            return oldResult.promise;
        });
        apiMocks.enrollmentContext.mockImplementation((enrollmentId: string) => Promise.resolve(
            enrollmentId === 'enrollment-1' ? enrollment : secondEnrollment,
        ));

        renderMonitoring(
            '/teacher/monitoring/enrollments/enrollment-1?curriculum_id=curriculum-1&version_id=version-1&tab=results',
            true,
        );
        fireEvent.click(await screen.findByRole('button', { name: 'عرض ملخص النتيجة' }));
        expect(await screen.findByText('جارٍ تحميل ملخص النتيجة...')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'انتقل إلى الطالب الثاني' }));

        expect(await screen.findByText('الطالب الثاني')).toBeInTheDocument();
        expect(oldSignal?.aborted).toBe(true);
        oldResult.resolve({
            ...scope,
            attempt: submittedAttempt,
        });
        await Promise.resolve();
        expect(screen.queryByText('ملخص النتيجة المعتمد')).not.toBeInTheDocument();
    });
});

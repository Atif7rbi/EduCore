import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    AxiosHeaders,
    type AxiosAdapter,
    type AxiosResponse,
    type InternalAxiosRequestConfig,
} from 'axios';
import {
    RouterProvider,
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
    apiClient,
} from '../api/client';
import type {
    AuthUser,
} from '../auth/types';

import {
    AppProviders,
} from './providers';
import {
    router,
} from './router';

interface Deferred<T> {
    promise: Promise<T>;
    reject: (reason: unknown) => void;
    resolve: (value: T) => void;
}

interface PendingAdapterRequest {
    config: InternalAxiosRequestConfig;
    deferred: Deferred<AxiosResponse>;
}

interface RequestRecord {
    method: string;
    principalId: string;
    url: string;
}

type ContextSuffix = 'a' | 'b';
type MutationSettlement = 'success' | 403 | 404 | 409;

const contextA =
    '/teacher/workspace/assignment-a'
    + '/curricula/curriculum-a'
    + '/versions/version-a';
const contextB =
    '/teacher/workspace/assignment-b'
    + '/curricula/curriculum-b'
    + '/versions/version-b';

const teacherA: AuthUser = {
    email: 'teacher-a@example.com',
    id: 'teacher-a',
    learner_profile_id: null,
    name: 'المعلم أ',
    role: 'teacher',
    status: 'active',
};

const teacherB: AuthUser = {
    email: 'teacher-b@example.com',
    id: 'teacher-b',
    learner_profile_id: null,
    name: 'المعلم ب',
    role: 'teacher',
    status: 'active',
};

let currentUser: AuthUser;
let originalAdapter: typeof apiClient.defaults.adapter;
let pendingMutation: PendingAdapterRequest | null;
let pendingSessionFailure: PendingAdapterRequest | null;
let requestLog: RequestRecord[];

function deferred<T>(): Deferred<T> {
    let resolve: (value: T) => void = () => undefined;
    let reject: (reason: unknown) => void = () => undefined;
    const promise = new Promise<T>((fulfill, fail) => {
        resolve = fulfill;
        reject = fail;
    });

    return {
        promise,
        reject,
        resolve,
    };
}

function success(
    config: InternalAxiosRequestConfig,
    data: unknown,
): AxiosResponse {
    return {
        config,
        data: {
            data,
        },
        headers: new AxiosHeaders(),
        status: 200,
        statusText: 'OK',
    };
}

function failure(
    config: InternalAxiosRequestConfig,
    status: 401 | 403 | 404 | 409,
    requestId: string,
) {
    return {
        config,
        isAxiosError: true,
        message: 'Request failed.',
        name: 'AxiosError',
        response: {
            config,
            data: {
                error: {
                    code: status === 401
                        ? 'unauthenticated'
                        : status === 409
                            ? 'lifecycle_conflict'
                            : 'resource_unavailable',
                    message: 'Request failed.',
                },
            },
            headers: new AxiosHeaders({
                'x-request-id': requestId,
            }),
            status,
            statusText: 'Request failed.',
        },
        toJSON: () => ({}),
    };
}

function parseRequestData(data: unknown): Record<string, unknown> {
    if (typeof data === 'string') {
        return JSON.parse(data) as Record<string, unknown>;
    }

    return data as Record<string, unknown>;
}

function suffixFromUrl(url: string): ContextSuffix {
    return url.includes('assignment-b')
        || url.includes('curriculum-b')
        || url.includes('version-b')
        ? 'b'
        : 'a';
}

function prefix(suffix: ContextSuffix) {
    return '/api/teacher/subject-assignments/assignment-'
        + suffix
        + '/curricula/curriculum-'
        + suffix
        + '/versions/version-'
        + suffix;
}

function principalLabel() {
    return currentUser.id === teacherB.id ? 'ب' : 'أ';
}

function configureAdapter(): AxiosAdapter {
    return async (config) => {
        const method = (config.method ?? 'GET').toUpperCase();
        const url = config.url ?? '';

        requestLog.push({
            method,
            principalId: currentUser.id,
            url,
        });

        if (method === 'GET' && url === '/auth/me') {
            return success(config, {
                user: currentUser,
            });
        }

        if (method === 'POST' && url === '/auth/login') {
            const credentials = parseRequestData(config.data);

            currentUser = credentials.email === teacherB.email
                ? teacherB
                : teacherA;

            return success(config, {
                user: currentUser,
            });
        }

        if (
            method === 'GET'
            && url === '/api/teacher/subject-assignments'
        ) {
            return success(config, ['a', 'b'].map((suffix) => ({
                created_at: null,
                id: `assignment-${suffix}`,
                status: 'active',
                subject: {
                    code: `SUB-${suffix.toUpperCase()}`,
                    id: `subject-${suffix}`,
                    name: `المادة ${suffix.toUpperCase()}`,
                    status: 'active',
                },
                teacher_user_id: currentUser.id,
                updated_at: null,
            })));
        }

        if (method === 'GET' && url.endsWith('/curricula')) {
            const suffix = suffixFromUrl(url);

            return success(config, [{
                education_stage_id: `stage-${suffix}`,
                id: `curriculum-${suffix}`,
                name: `المنهج ${suffix.toUpperCase()}`,
                subject_id: `subject-${suffix}`,
                teacher_subject_assignment_id: `assignment-${suffix}`,
            }]);
        }

        if (method === 'GET' && url.endsWith('/versions')) {
            const suffix = suffixFromUrl(url);

            return success(config, [{
                curriculum_id: `curriculum-${suffix}`,
                id: `version-${suffix}`,
                label: `الإصدار ${suffix.toUpperCase()}`,
                status: suffix === 'b' ? 'published' : 'draft',
                version_number: 1,
            }]);
        }

        if (method === 'POST' && url.endsWith('/topics')) {
            pendingMutation = {
                config,
                deferred: deferred<AxiosResponse>(),
            };

            return pendingMutation.deferred.promise;
        }

        if (method === 'GET' && url.endsWith('/topics')) {
            const suffix = suffixFromUrl(url);

            return success(config, [{
                curriculum_version_id: `version-${suffix}`,
                display_order: 1,
                id: `${currentUser.id}-topic-${suffix}`,
                name: `موضوع ${principalLabel()}-${suffix.toUpperCase()}`,
            }]);
        }

        if (
            method === 'GET'
            && url.endsWith('/skill-placements')
        ) {
            return success(config, []);
        }

        if (method === 'GET' && url === '/api/teacher/skills') {
            return success(config, []);
        }

        if (method === 'GET' && url.endsWith('/lessons')) {
            const suffix = suffixFromUrl(url);

            return success(config, [{
                curriculum_version_id: `version-${suffix}`,
                description: null,
                display_order: 1,
                id: `${currentUser.id}-lesson-${suffix}`,
                published_revision_id: null,
                status: 'draft',
                title: `درس ${principalLabel()}-${suffix.toUpperCase()}`,
            }]);
        }

        if (
            method === 'GET'
            && url.endsWith('/assessment-items')
        ) {
            const suffix = suffixFromUrl(url);

            return success(config, [{
                curriculum_version_id: `version-${suffix}`,
                id: `${currentUser.id}-assessment-${suffix}`,
                internal_label: `تقييم ${principalLabel()}-${suffix.toUpperCase()}`,
                item_type: 'multiple_choice',
                published_revision_id: null,
                status: 'draft',
            }]);
        }

        if (
            method === 'GET'
            && url.endsWith('/practice-activities')
        ) {
            const suffix = suffixFromUrl(url);

            return success(config, [{
                curriculum_version_id: `version-${suffix}`,
                description: null,
                id: `${currentUser.id}-practice-${suffix}`,
                lesson_id: null,
                name: `تدريب ${principalLabel()}-${suffix.toUpperCase()}`,
                status: 'archived',
            }]);
        }

        if (method === 'GET' && url.endsWith('/exam-templates')) {
            const suffix = suffixFromUrl(url);

            return success(config, [{
                curriculum_version_id: `version-${suffix}`,
                description: null,
                id: `${currentUser.id}-exam-${suffix}`,
                name: `امتحان ${principalLabel()}-${suffix.toUpperCase()}`,
                published_version_id: null,
                status: 'active',
            }]);
        }

        if (method === 'GET' && url === '/api/session-probe') {
            pendingSessionFailure = {
                config,
                deferred: deferred<AxiosResponse>(),
            };

            return pendingSessionFailure.deferred.promise;
        }

        throw new Error(`Unexpected request: ${method} ${url}`);
    };
}

async function renderProduction(path: string) {
    await act(async () => {
        await router.navigate(path, {
            replace: true,
        });
    });

    return render(
        <AppProviders>
            <RouterProvider router={router} />
        </AppProviders>,
    );
}

async function expectWorkspace() {
    await screen.findByRole('heading', {
        level: 1,
        name: 'تأليف المحتوى',
    });
}

function exactLocation() {
    return router.state.location.pathname
        + router.state.location.search;
}

function resourceReads() {
    return requestLog.filter(({ method, url }) => (
        method === 'GET'
        && (
            url === '/api/teacher/skills'
            || /\/(topics|skill-placements|lessons|assessment-items|practice-activities|exam-templates)$/.test(url)
        )
    ));
}

function resourceReadUrls() {
    return resourceReads().map(({ url }) => url);
}

function versionCollectionUrl(suffix: ContextSuffix) {
    return '/api/teacher/subject-assignments/assignment-'
        + suffix
        + '/curricula/curriculum-'
        + suffix
        + '/versions';
}

function versionReadCount(suffix: ContextSuffix) {
    const url = versionCollectionUrl(suffix);

    return requestLog.filter((request) => (
        request.method === 'GET'
        && request.url === url
    )).length;
}

function expectActiveSection(name: string) {
    expect(screen.getByRole('button', {
        name,
    })).toHaveAttribute('aria-current', 'page');
}

async function openTopicDraft(name: string) {
    fireEvent.click(screen.getByRole('button', {
        name: 'إضافة موضوع',
    }));
    fireEvent.change(screen.getByLabelText('اسم الموضوع'), {
        target: {
            value: name,
        },
    });

    expect(screen.getByLabelText('اسم الموضوع')).toHaveValue(name);
}

async function startTopicMutation(name: string) {
    await openTopicDraft(name);
    fireEvent.click(screen.getByRole('button', {
        name: 'حفظ الموضوع',
    }));

    await waitFor(() => {
        expect(pendingMutation).not.toBeNull();
    });
}

async function settleMutation(
    settlement: MutationSettlement,
    name = 'نتيجة مؤجلة قديمة',
) {
    const pending = pendingMutation;

    expect(pending).not.toBeNull();

    await act(async () => {
        if (settlement === 'success') {
            pending!.deferred.resolve(
                success(pending!.config, {
                    curriculum_version_id: 'version-a',
                    display_order: 0,
                    id: 'late-topic-a',
                    name,
                }),
            );
        } else {
            pending!.deferred.reject(
                failure(
                    pending!.config,
                    settlement,
                    `late-${settlement}`,
                ),
            );
        }

        await pending!.deferred.promise.catch(() => undefined);
    });
}

async function expireSession(status: 401) {
    const outcome = apiClient.get('/api/session-probe')
        .catch((error: unknown) => error);

    await waitFor(() => {
        expect(pendingSessionFailure).not.toBeNull();
    });

    await act(async () => {
        pendingSessionFailure!.deferred.reject(
            failure(
                pendingSessionFailure!.config,
                status,
                's7-session-loss',
            ),
        );

        await outcome;
    });

    await screen.findByRole('heading', {
        level: 1,
        name: 'تسجيل الدخول',
    });
}

async function loginAsTeacherB() {
    fireEvent.change(screen.getByRole('textbox', {
        name: 'البريد الإلكتروني',
    }), {
        target: {
            value: teacherB.email,
        },
    });
    fireEvent.change(screen.getByLabelText('كلمة المرور'), {
        target: {
            value: 'secret',
        },
    });
    fireEvent.click(screen.getByRole('button', {
        name: 'تسجيل الدخول',
    }));
}

describe('H6-S7 Teacher Workspace cross-section integration acceptance', () => {
    beforeEach(() => {
        originalAdapter = apiClient.defaults.adapter;
        currentUser = teacherA;
        pendingMutation = null;
        pendingSessionFailure = null;
        requestLog = [];
        apiClient.defaults.adapter = configureAdapter();
        window.localStorage.clear();
    });

    afterEach(async () => {
        cleanup();
        apiClient.defaults.adapter = originalAdapter;
        vi.restoreAllMocks();

        await router.navigate('/login', {
            replace: true,
        });
    });

    it('loads all five production sections one at a time through the exact authorized context', async () => {
        await renderProduction(`${contextA}?section=topics`);
        await expectWorkspace();

        expect(await screen.findByText('موضوع أ-A')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextA}?section=topics`);
        expect(screen.getByRole('button', {
            name: 'الموضوعات والمهارات',
        })).toHaveAttribute('aria-current', 'page');

        const initialReads = resourceReadUrls();
        expect(initialReads).toContain(`${prefix('a')}/topics`);
        expect(initialReads).toContain(`${prefix('a')}/skill-placements`);
        expect(initialReads).toContain('/api/teacher/skills');
        expect(initialReads).not.toContain(`${prefix('a')}/lessons`);
        expect(initialReads).not.toContain(`${prefix('a')}/assessment-items`);
        expect(initialReads).not.toContain(`${prefix('a')}/practice-activities`);
        expect(initialReads).not.toContain(`${prefix('a')}/exam-templates`);

        fireEvent.click(screen.getByRole('button', {
            name: 'الدروس',
        }));
        expect(await screen.findByText('درس أ-A')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextA}?section=lessons`);
        expect(screen.queryByText('موضوع أ-A')).not.toBeInTheDocument();
        expectActiveSection('الدروس');

        const readsAfterLessons = resourceReadUrls();
        expect(readsAfterLessons).toContain(`${prefix('a')}/lessons`);
        expect(readsAfterLessons)
            .not.toContain(`${prefix('a')}/assessment-items`);
        expect(readsAfterLessons)
            .not.toContain(`${prefix('a')}/practice-activities`);
        expect(readsAfterLessons)
            .not.toContain(`${prefix('a')}/exam-templates`);

        fireEvent.click(screen.getByRole('button', {
            name: 'عناصر التقييم',
        }));
        expect(await screen.findByText('تقييم أ-A')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextA}?section=assessments`);
        expect(screen.queryByText('درس أ-A')).not.toBeInTheDocument();
        expectActiveSection('عناصر التقييم');

        const readsAfterAssessments = resourceReadUrls();
        expect(readsAfterAssessments)
            .toContain(`${prefix('a')}/assessment-items`);
        expect(readsAfterAssessments)
            .not.toContain(`${prefix('a')}/practice-activities`);
        expect(readsAfterAssessments)
            .not.toContain(`${prefix('a')}/exam-templates`);

        fireEvent.click(screen.getByRole('button', {
            name: 'أنشطة التدريب',
        }));
        expect(await screen.findByText('تدريب أ-A')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextA}?section=practice`);
        expect(screen.queryByText('تقييم أ-A')).not.toBeInTheDocument();
        expectActiveSection('أنشطة التدريب');

        const readsAfterPractice = resourceReadUrls();
        expect(readsAfterPractice)
            .toContain(`${prefix('a')}/practice-activities`);
        expect(readsAfterPractice)
            .not.toContain(`${prefix('a')}/exam-templates`);

        fireEvent.click(screen.getByRole('button', {
            name: 'نماذج الامتحان',
        }));
        expect(await screen.findByText('امتحان أ-A')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextA}?section=exams`);
        expect(screen.queryByText('تدريب أ-A')).not.toBeInTheDocument();
        expectActiveSection('نماذج الامتحان');

        const readsAfterExams = resourceReadUrls();
        expect(readsAfterExams)
            .toContain(`${prefix('a')}/exam-templates`);

        const reads = resourceReads();
        for (const resource of [
            'topics',
            'skill-placements',
            'lessons',
            'assessment-items',
            'practice-activities',
            'exam-templates',
        ]) {
            expect(reads).toContainEqual(expect.objectContaining({
                method: 'GET',
                principalId: teacherA.id,
                url: `${prefix('a')}/${resource}`,
            }));
        }

        expect(
            reads.filter(({ url }) => url.includes('/versions/version-b/')),
        ).toHaveLength(0);
    });

    it('resolves selector navigation immediately and keeps context isolated through history and dirty guards', async () => {
        const confirmation = vi.spyOn(window, 'confirm')
            .mockReturnValueOnce(false)
            .mockReturnValueOnce(true);

        await renderProduction('/teacher/workspace');
        await expectWorkspace();

        const contextSelector = await screen.findByRole('group', {
            name: 'سياق التأليف',
        });
        const assignmentSelect = screen.getByLabelText('تعيين المادة');
        const curriculumSelect = screen.getByLabelText('المنهج');
        const versionSelect = screen.getByLabelText('إصدار المنهج');

        expect(contextSelector).toHaveClass('teacher-context-selector');
        expect(contextSelector).toHaveAttribute('dir', 'rtl');
        expect(assignmentSelect).toBeEnabled();
        expect(assignmentSelect).toHaveAttribute(
            'aria-describedby',
            'teacher-assignment-hint',
        );
        expect(curriculumSelect).toBeDisabled();
        expect(versionSelect).toBeDisabled();
        expect(screen.getByText('اختر تعيين المادة أولًا.'))
            .toHaveAttribute('aria-live', 'polite');
        expect(screen.getByRole('heading', {
            name: 'اختر سياق التأليف',
        })).toBeInTheDocument();
        expect(screen.queryByRole('navigation', {
            name: 'أقسام مساحة المعلم',
        })).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('تعيين المادة'), {
            target: {
                value: 'assignment-a',
            },
        });
        await screen.findByRole('option', {
            name: 'المنهج A',
        });
        expect(curriculumSelect).toBeEnabled();
        expect(versionSelect).toBeDisabled();

        fireEvent.change(screen.getByLabelText('المنهج'), {
            target: {
                value: 'curriculum-a',
            },
        });
        await screen.findByRole('option', {
            name: 'الإصدار 1 — الإصدار A',
        });
        expect(versionSelect).toBeEnabled();

        fireEvent.change(screen.getByLabelText('إصدار المنهج'), {
            target: {
                value: 'version-a',
            },
        });

        expect(await screen.findByText('موضوع أ-A')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextA}?section=topics`);
        expect(screen.queryByRole('heading', {
            name: 'اختر سياق التأليف',
        })).not.toBeInTheDocument();

        await openTopicDraft('مسودة محفوظة محليًا');
        fireEvent.change(screen.getByLabelText('تعيين المادة'), {
            target: {
                value: 'assignment-b',
            },
        });

        expect(confirmation).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('تعيين المادة'))
            .toHaveValue('assignment-a');
        expect(screen.getByLabelText('اسم الموضوع'))
            .toHaveValue('مسودة محفوظة محليًا');
        expect(exactLocation()).toBe(`${contextA}?section=topics`);

        fireEvent.change(screen.getByLabelText('تعيين المادة'), {
            target: {
                value: 'assignment-b',
            },
        });

        expect(confirmation).toHaveBeenCalledTimes(2);
        expect(screen.queryByText('موضوع أ-A')).not.toBeInTheDocument();
        expect(screen.getByRole('heading', {
            name: 'اختر سياق التأليف',
        })).toBeInTheDocument();
        await screen.findByRole('option', {
            name: 'المنهج B',
        });

        fireEvent.change(screen.getByLabelText('المنهج'), {
            target: {
                value: 'curriculum-b',
            },
        });
        await screen.findByRole('option', {
            name: 'الإصدار 1 — الإصدار B',
        });

        fireEvent.change(screen.getByLabelText('إصدار المنهج'), {
            target: {
                value: 'version-b',
            },
        });

        expect(await screen.findByText('موضوع أ-B')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextB}?section=topics`);
        expect(screen.getAllByText('هذا الإصدار للقراءة فقط.').length)
            .toBeGreaterThan(0);
        expect(screen.queryByRole('button', {
            name: 'إضافة موضوع',
        })).not.toBeInTheDocument();

        await act(async () => {
            await router.navigate(-1);
        });

        expect(await screen.findByText('موضوع أ-A')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextA}?section=topics`);
        expect(screen.queryByText('موضوع أ-B')).not.toBeInTheDocument();

        await act(async () => {
            await router.navigate(1);
        });

        expect(await screen.findByText('موضوع أ-B')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextB}?section=topics`);
        expect(screen.queryByText('موضوع أ-A')).not.toBeInTheDocument();
    });

    it('replaces an authorized context without exposing resources from the previous context', async () => {
        await renderProduction(`${contextA}?section=topics`);
        expect(await screen.findByText('موضوع أ-A')).toBeInTheDocument();

        await act(async () => {
            await router.navigate(`${contextB}?section=exams`);
        });

        expect(await screen.findByText('امتحان أ-B')).toBeInTheDocument();
        expect(exactLocation()).toBe(`${contextB}?section=exams`);
        expect(screen.queryByText('موضوع أ-A')).not.toBeInTheDocument();
        expect(screen.queryByText('امتحان أ-A')).not.toBeInTheDocument();
        expect(requestLog).toContainEqual({
            method: 'GET',
            principalId: teacherA.id,
            url: `${prefix('b')}/exam-templates`,
        });
        expect(
            screen.getByRole('button', {
                name: 'نماذج الامتحان',
            }),
        ).toHaveAttribute('aria-current', 'page');
    });

    it('guards dirty section replacement and scopes each approval to one intended transition', async () => {
        const confirmation = vi.spyOn(window, 'confirm')
            .mockReturnValueOnce(false)
            .mockReturnValueOnce(true)
            .mockReturnValueOnce(false)
            .mockReturnValueOnce(true);

        await renderProduction(`${contextA}?section=topics`);
        await screen.findByText('موضوع أ-A');
        await openTopicDraft('مسودة الموضوع');

        fireEvent.click(screen.getByRole('button', {
            name: 'الدروس',
        }));
        await waitFor(() => {
            expect(exactLocation()).toBe(`${contextA}?section=topics`);
        });
        expect(screen.getByLabelText('اسم الموضوع'))
            .toHaveValue('مسودة الموضوع');

        fireEvent.click(screen.getByRole('button', {
            name: 'الدروس',
        }));
        await screen.findByRole('heading', {
            name: 'الدروس ونسخها',
        });
        expect(exactLocation()).toBe(`${contextA}?section=lessons`);

        fireEvent.click(screen.getByRole('button', {
            name: 'إضافة درس',
        }));
        fireEvent.change(screen.getByLabelText('عنوان الدرس'), {
            target: {
                value: 'مسودة الدرس',
            },
        });

        fireEvent.click(screen.getByRole('button', {
            name: 'عناصر التقييم',
        }));
        await waitFor(() => {
            expect(exactLocation()).toBe(`${contextA}?section=lessons`);
        });
        expect(screen.getByLabelText('عنوان الدرس'))
            .toHaveValue('مسودة الدرس');

        fireEvent.click(screen.getByRole('button', {
            name: 'عناصر التقييم',
        }));
        await screen.findByRole('heading', {
            name: 'عناصر التقييم ونسخها',
        });
        expect(exactLocation()).toBe(`${contextA}?section=assessments`);
        expect(confirmation).toHaveBeenCalledTimes(4);
    });

    it('forces an active authorization failure past a declined dirty-navigation confirmation', async () => {
        const confirmation = vi.spyOn(window, 'confirm')
            .mockReturnValue(false);

        await renderProduction(`${contextA}?section=topics`);
        await screen.findByText('موضوع أ-A');
        await startTopicMutation('مسودة مرفوضة');
        await settleMutation(403);

        await waitFor(() => {
            expect(exactLocation()).toBe('/teacher/workspace');
        });
        expect(screen.getByText(/سياق التأليف غير متاح/))
            .toBeInTheDocument();
        expect(screen.queryByLabelText('اسم الموضوع'))
            .not.toBeInTheDocument();
        expect(confirmation).not.toHaveBeenCalled();
    });

    it.each<MutationSettlement>([
        'success',
        403,
        404,
    ])('isolates a late %s mutation result after switching production sections', async (settlement) => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        await renderProduction(`${contextA}?section=topics`);
        await screen.findByText('موضوع أ-A');
        await startTopicMutation('مسودة قسم قديم');

        fireEvent.click(screen.getByRole('button', {
            name: 'الدروس',
        }));
        await screen.findByRole('heading', {
            name: 'الدروس ونسخها',
        });

        const transitionLocation = exactLocation();
        await settleMutation(settlement);

        expect(exactLocation()).toBe(transitionLocation);
        expect(exactLocation()).toBe(`${contextA}?section=lessons`);
        expect(await screen.findByText('درس أ-A')).toBeInTheDocument();
        expect(screen.queryByText('نتيجة مؤجلة قديمة'))
            .not.toBeInTheDocument();
        expect(screen.queryByText(/سياق التأليف غير متاح/))
            .not.toBeInTheDocument();
    });

    it('prevents an old-context lifecycle callback from refreshing or redirecting the replacement context', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        await renderProduction(`${contextA}?section=topics`);
        await screen.findByText('موضوع أ-A');
        await startTopicMutation('مسودة سياق قديم');

        await act(async () => {
            await router.navigate(`${contextB}?section=lessons`);
        });
        await screen.findByText('درس أ-B');

        const versionReadsBefore409 = {
            a: versionReadCount('a'),
            b: versionReadCount('b'),
        };

        await settleMutation(409);
        await act(async () => {
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(exactLocation()).toBe(`${contextB}?section=lessons`);
        expect(screen.getByText('درس أ-B')).toBeInTheDocument();
        expect(screen.queryByText('موضوع أ-A')).not.toBeInTheDocument();
        expect(versionReadCount('a')).toBe(versionReadsBefore409.a);
        expect(versionReadCount('b')).toBe(versionReadsBefore409.b);
    });

    it('keeps session loss fail-closed and gives a reauthenticated principal fresh scoped data despite a late callback', async () => {
        const confirmation = vi.spyOn(window, 'confirm')
            .mockReturnValue(false);
        const transitions: string[] = [];
        const unsubscribe = router.subscribe((state) => {
            transitions.push(
                state.location.pathname + state.location.search,
            );
        });

        try {
            await renderProduction(`${contextA}?section=topics`);
            expect(await screen.findByText('موضوع أ-A')).toBeInTheDocument();
            await startTopicMutation('مسودة المعلم أ');
            await expireSession(401);

            expect(exactLocation()).toBe('/login');
            expect(screen.queryByText('تأليف المحتوى'))
                .not.toBeInTheDocument();
            expect(confirmation).not.toHaveBeenCalled();

            await loginAsTeacherB();
            expect(await screen.findByText('موضوع ب-A')).toBeInTheDocument();
            expect(exactLocation()).toBe(`${contextA}?section=topics`);
            expect(screen.queryByText('موضوع أ-A')).not.toBeInTheDocument();
            expect(screen.queryByDisplayValue('مسودة المعلم أ'))
                .not.toBeInTheDocument();

            const transitionCount = transitions.length;
            await settleMutation(404);

            expect(transitions).toHaveLength(transitionCount);
            expect(exactLocation()).toBe(`${contextA}?section=topics`);
            expect(screen.getByText('موضوع ب-A')).toBeInTheDocument();
            expect(screen.queryByText(/سياق التأليف غير متاح/))
                .not.toBeInTheDocument();
            expect(requestLog).toContainEqual({
                method: 'GET',
                principalId: teacherB.id,
                url: `${prefix('a')}/topics`,
            });
        } finally {
            unsubscribe();
        }
    });
});

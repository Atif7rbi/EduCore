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
    PracticeActivitiesPanel,
} from './PracticeActivitiesPanel';

import type {
    CurriculumVersion,
} from './types';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../../api/client', () => ({
    apiRequest: (config: RequestConfig) => apiRequestMock(config),
}));

const draftVersion: CurriculumVersion = {
    id: 'version-1',
    curriculum_id: 'curriculum-1',
    version_number: 1,
    label: 'الإصدار الأول',
    status: 'draft',
};

const lesson = {
    id: 'lesson-1',
    curriculum_version_id: 'version-1',
    title: 'النسب',
    description: null,
    status: 'draft',
    display_order: 0,
    published_revision_id: null,
    created_at: null,
    updated_at: null,
};

const stoppedActivity = {
    id: 'activity-1',
    curriculum_version_id: 'version-1',
    lesson_id: 'lesson-1',
    name: 'تدريب النسب',
    description: 'مجموعة تدريبية',
    status: 'archived',
    items_count: 2,
    created_at: null,
    updated_at: null,
};

function renderPanel(version: CurriculumVersion = draftVersion) {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <PracticeActivitiesPanel version={version} />
        </QueryClientProvider>,
    );
}

function installList(status: 'archived' | 'active' = 'archived') {
    apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
        if (
            method === 'GET'
            && url === '/api/admin/curriculum-versions/version-1/practice-activities'
        ) {
            return Promise.resolve([{ ...stoppedActivity, status }]);
        }

        if (
            method === 'GET'
            && url === '/api/admin/curriculum-versions/version-1/lessons'
        ) {
            return Promise.resolve([lesson]);
        }

        throw new Error(`Unexpected request ${method} ${url}`);
    });
}

describe('PracticeActivitiesPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('lists training groups and lessons with user-facing status', async () => {
        installList('archived');
        renderPanel();

        expect(await screen.findByText('تدريب النسب')).toBeInTheDocument();
        expect(screen.getByText(/الحالة: متوقف/)).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'النسب' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'التدريبات' })).toBeInTheDocument();
    });

    it('creates a training group using the exact backend payload', async () => {
        let created = false;

        apiRequestMock.mockImplementation(({ method, url, data }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/practice-activities'
            ) {
                return Promise.resolve(created ? [stoppedActivity] : []);
            }
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/lessons'
            ) {
                return Promise.resolve([lesson]);
            }
            if (
                method === 'POST'
                && url === '/api/admin/curriculum-versions/version-1/practice-activities'
            ) {
                expect(data).toEqual({
                    lesson_id: 'lesson-1',
                    name: 'تدريب النسب',
                    description: 'مجموعة تدريبية',
                });
                created = true;
                return Promise.resolve(stoppedActivity);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لا توجد مجموعات تدريب لهذا المنهج حتى الآن.');

        fireEvent.change(screen.getByLabelText('اسم مجموعة التدريب'), {
            target: { value: 'تدريب النسب' },
        });
        fireEvent.change(screen.getByLabelText('درس مجموعة التدريب'), {
            target: { value: 'lesson-1' },
        });
        fireEvent.change(screen.getByLabelText('وصف مجموعة التدريب'), {
            target: { value: 'مجموعة تدريبية' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'إنشاء مجموعة تدريب' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/practice-activities',
                data: {
                    lesson_id: 'lesson-1',
                    name: 'تدريب النسب',
                    description: 'مجموعة تدريبية',
                },
            });
        });
    });

    it('updates metadata only while the training group is stopped', async () => {
        apiRequestMock.mockImplementation(({ method, url, data }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/practice-activities'
            ) return Promise.resolve([stoppedActivity]);
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/lessons'
            ) return Promise.resolve([lesson]);
            if (
                method === 'PUT'
                && url === '/api/admin/practice-activities/activity-1'
            ) {
                expect(data).toEqual({
                    lesson_id: 'lesson-1',
                    name: 'تدريب النسب المحدّث',
                    description: 'مجموعة تدريبية',
                });
                return Promise.resolve({
                    ...stoppedActivity,
                    name: 'تدريب النسب المحدّث',
                });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('تدريب النسب');
        fireEvent.click(screen.getByRole('button', { name: 'تعديل' }));
        fireEvent.change(screen.getByLabelText('تعديل اسم مجموعة التدريب'), {
            target: { value: 'تدريب النسب المحدّث' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ التعديل' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'PUT',
                url: '/api/admin/practice-activities/activity-1',
                data: {
                    lesson_id: 'lesson-1',
                    name: 'تدريب النسب المحدّث',
                    description: 'مجموعة تدريبية',
                },
            });
        });
    });

    it('does not expose metadata editing while the training group is available', async () => {
        installList('active');
        renderPanel();

        expect(await screen.findByText(/الحالة: متاح/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'تعديل' })).not.toBeInTheDocument();
    });

    it('keeps training authoring read only outside a draft curriculum version', async () => {
        installList('archived');
        renderPanel({ ...draftVersion, status: 'published' });

        expect(
            await screen.findByText(
                'هذا المنهج للقراءة فقط؛ لا يمكن إنشاء التدريبات أو تعديلها.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'إنشاء مجموعة تدريب' }),
        ).not.toBeInTheDocument();
    });

    it('makes a stopped training group available through the backend route', async () => {
        let active = false;

        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/practice-activities'
            ) {
                return Promise.resolve([
                    { ...stoppedActivity, status: active ? 'active' : 'archived' },
                ]);
            }
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/lessons'
            ) return Promise.resolve([lesson]);
            if (
                method === 'POST'
                && url === '/api/admin/practice-activities/activity-1/activate'
            ) {
                active = true;
                return Promise.resolve({ ...stoppedActivity, status: 'active' });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText(/الحالة: متوقف/);
        fireEvent.click(screen.getByRole('button', { name: 'إتاحة للطلاب' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/practice-activities/activity-1/activate',
            });
        });
        expect(await screen.findByText(/الحالة: متاح/)).toBeInTheDocument();
    });

    it('stops an available training group through the backend route', async () => {
        let active = true;

        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/practice-activities'
            ) {
                return Promise.resolve([
                    { ...stoppedActivity, status: active ? 'active' : 'archived' },
                ]);
            }
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/lessons'
            ) return Promise.resolve([lesson]);
            if (
                method === 'POST'
                && url === '/api/admin/practice-activities/activity-1/archive'
            ) {
                active = false;
                return Promise.resolve(stoppedActivity);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText(/الحالة: متاح/);
        fireEvent.click(screen.getByRole('button', { name: 'إيقاف الإتاحة' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/practice-activities/activity-1/archive',
            });
        });
        expect(await screen.findByText(/الحالة: متوقف/)).toBeInTheDocument();
    });

    it('does not expose lifecycle mutations outside a draft curriculum version', async () => {
        installList('archived');
        renderPanel({ ...draftVersion, status: 'published' });

        await screen.findByText(
            'هذا المنهج للقراءة فقط؛ لا يمكن إنشاء التدريبات أو تعديلها.',
        );
        expect(
            screen.queryByRole('button', { name: 'إتاحة للطلاب' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'إيقاف الإتاحة' }),
        ).not.toBeInTheDocument();
    });
});

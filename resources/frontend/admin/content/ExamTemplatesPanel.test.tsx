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
    ExamTemplatesPanel,
} from './ExamTemplatesPanel';

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

const availableExam = {
    id: 'template-1',
    curriculum_version_id: 'version-1',
    name: 'اختبار تجريبي',
    description: 'اختبار أساسي',
    status: 'active',
    published_version_id: null,
    versions_count: 2,
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
            <ExamTemplatesPanel version={version} />
        </QueryClientProvider>,
    );
}

describe('ExamTemplatesPanel', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('lists exams for the selected curriculum', async () => {
        apiRequestMock.mockResolvedValue([availableExam]);
        renderPanel();

        expect(await screen.findByText('اختبار تجريبي')).toBeInTheDocument();
        expect(screen.getByText(/الحالة: متاح/)).toBeInTheDocument();
        expect(screen.getByText(/الإعدادات المحفوظة: 2/)).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'الاختبارات' })).toBeInTheDocument();
    });

    it('creates an exam using the exact backend payload', async () => {
        let created = false;

        apiRequestMock.mockImplementation(({ method, url, data }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/exam-templates'
            ) {
                return Promise.resolve(created ? [availableExam] : []);
            }
            if (
                method === 'POST'
                && url === '/api/admin/curriculum-versions/version-1/exam-templates'
            ) {
                expect(data).toEqual({
                    name: 'اختبار تجريبي',
                    description: 'اختبار أساسي',
                });
                created = true;
                return Promise.resolve(availableExam);
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('لا توجد اختبارات لهذا المنهج حتى الآن.');

        fireEvent.change(screen.getByLabelText('اسم الاختبار'), {
            target: { value: 'اختبار تجريبي' },
        });
        fireEvent.change(screen.getByLabelText('وصف الاختبار'), {
            target: { value: 'اختبار أساسي' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'إنشاء اختبار' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'POST',
                url: '/api/admin/curriculum-versions/version-1/exam-templates',
                data: {
                    name: 'اختبار تجريبي',
                    description: 'اختبار أساسي',
                },
            });
        });
    });

    it('updates exam metadata while the exam is available', async () => {
        apiRequestMock.mockImplementation(({ method, url, data }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/exam-templates'
            ) return Promise.resolve([availableExam]);
            if (
                method === 'PUT'
                && url === '/api/admin/exam-templates/template-1'
            ) {
                expect(data).toEqual({
                    name: 'اختبار محدّث',
                    description: 'اختبار أساسي',
                });
                return Promise.resolve({
                    ...availableExam,
                    name: 'اختبار محدّث',
                });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText('اختبار تجريبي');
        fireEvent.click(screen.getByRole('button', { name: 'تعديل' }));
        fireEvent.change(screen.getByLabelText('تعديل اسم الاختبار'), {
            target: { value: 'اختبار محدّث' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ التعديل' }));

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledWith({
                method: 'PUT',
                url: '/api/admin/exam-templates/template-1',
                data: {
                    name: 'اختبار محدّث',
                    description: 'اختبار أساسي',
                },
            });
        });
    });

    it('stops and re-enables exams through backend lifecycle routes', async () => {
        let active = true;

        apiRequestMock.mockImplementation(({ method, url }: RequestConfig) => {
            if (
                method === 'GET'
                && url === '/api/admin/curriculum-versions/version-1/exam-templates'
            ) {
                return Promise.resolve([
                    { ...availableExam, status: active ? 'active' : 'archived' },
                ]);
            }
            if (
                method === 'POST'
                && url === '/api/admin/exam-templates/template-1/archive'
            ) {
                active = false;
                return Promise.resolve({ ...availableExam, status: 'archived' });
            }
            if (
                method === 'POST'
                && url === '/api/admin/exam-templates/template-1/activate'
            ) {
                active = true;
                return Promise.resolve({ ...availableExam, status: 'active' });
            }
            throw new Error(`Unexpected request ${method} ${url}`);
        });

        renderPanel();
        await screen.findByText(/الحالة: متاح/);
        fireEvent.click(screen.getByRole('button', { name: 'إيقاف' }));
        expect(await screen.findByText(/الحالة: متوقف/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'تعديل' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'إعادة الإتاحة' }));
        expect(await screen.findByText(/الحالة: متاح/)).toBeInTheDocument();
    });

    it('keeps exam authoring read only outside a draft curriculum version', async () => {
        apiRequestMock.mockResolvedValue([availableExam]);
        renderPanel({ ...draftVersion, status: 'published' });

        expect(
            await screen.findByText(
                'هذا المنهج للقراءة فقط؛ لا يمكن إنشاء الاختبارات أو تعديلها.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'إنشاء اختبار' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'تعديل' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'إيقاف' }),
        ).not.toBeInTheDocument();
    });
});

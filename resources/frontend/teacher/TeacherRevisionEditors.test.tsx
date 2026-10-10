import {
    fireEvent,
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
    TeacherAssessmentRevisionEditor,
} from './TeacherAssessmentRevisionEditor';
import {
    TeacherLessonRevisionEditor,
} from './TeacherLessonRevisionEditor';

interface RequestConfig {
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

function renderEditor(kind: 'lesson' | 'assessment') {
    const client = new QueryClient({
        defaultOptions: {
            mutations: { retry: false },
            queries: { retry: false },
        },
    });
    const props = {
        authenticatedUserId: 'teacher-a',
        context,
        onContextUnavailable: vi.fn(),
        onDirtyChange: vi.fn(),
        onLifecycleConflict: vi.fn().mockResolvedValue(undefined),
    };

    render(
        <QueryClientProvider client={client}>
            {kind === 'lesson' ? (
                <TeacherLessonRevisionEditor
                    {...props}
                    lesson={{
                        curriculum_version_id: 'version-a',
                        description: null,
                        display_order: 0,
                        id: 'lesson-a',
                        published_revision_id: null,
                        status: 'draft',
                        title: 'درس',
                    }}
                />
            ) : (
                <TeacherAssessmentRevisionEditor
                    {...props}
                    item={{
                        curriculum_version_id: 'version-a',
                        id: 'item-a',
                        internal_label: null,
                        item_type: 'multiple_choice',
                        published_revision_id: null,
                        status: 'draft',
                    }}
                />
            )}
        </QueryClientProvider>,
    );
}

describe('Teacher revision editors', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
        apiRequestMock.mockResolvedValue([]);
    });

    it('does not submit invalid lesson JSON to the backend', async () => {
        renderEditor('lesson');
        fireEvent.click(await screen.findByRole('button', {
            name: 'إضافة نسخة درس',
        }));
        fireEvent.change(screen.getByLabelText('محتوى نسخة الدرس JSON'), {
            target: { value: '[]' },
        });
        fireEvent.click(screen.getByRole('button', {
            name: 'حفظ النسخة',
        }));

        expect(screen.getByText('أدخل كائن JSON صالحًا.')).toBeInTheDocument();
        expect(apiRequestMock.mock.calls.filter(
            ([request]) => request.method === 'POST',
        )).toHaveLength(0);
    });

    it('does not submit invalid assessment JSON to the backend', async () => {
        renderEditor('assessment');
        fireEvent.click(await screen.findByRole('button', {
            name: 'إضافة نسخة',
        }));
        fireEvent.change(screen.getByLabelText('تصحيح نسخة التقييم'), {
            target: { value: 'not-json' },
        });
        fireEvent.click(screen.getByRole('button', {
            name: 'إنشاء النسخة',
        }));

        expect(screen.getByText('يجب أن تكون حقول JSON كائنات صالحة.')).toBeInTheDocument();
        expect(apiRequestMock.mock.calls.filter(
            ([request]) => request.method === 'POST',
        )).toHaveLength(0);
    });

    it('renders released assessment revisions as read-only history', async () => {
        apiRequestMock.mockImplementation((config: RequestConfig) => {
            if (config.url.endsWith('/revisions')) {
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

            return Promise.resolve([]);
        });

        renderEditor('assessment');

        expect(await screen.findByText(/مُصدرة/)).toBeInTheDocument();
        expect(screen.queryByRole('button', {
            name: 'إصدار',
        })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', {
            name: 'تعديل',
        })).not.toBeInTheDocument();
    });
});

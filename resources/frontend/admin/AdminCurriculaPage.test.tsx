import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
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
    AdminCurriculaPage,
} from './AdminCurriculaPage';

interface RequestConfig {
    method: string;
    url: string;
    data?: unknown;
}

interface InventoryOptions {
    subjects?: Array<Record<string, unknown>>;
    stages?: Array<Record<string, unknown>>;
    curriculaBySubject?: Record<
        string,
        Array<Record<string, unknown>>
    >;
    onRequest?: (
        config: RequestConfig,
    ) => unknown;
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (config: RequestConfig) =>
        apiRequestMock(config),
}));

function renderPage() {
    const client = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <QueryClientProvider client={client}>
            <AdminCurriculaPage />
        </QueryClientProvider>,
    );
}

function subject({
    id = 'subject-math',
    code = 'mathematics',
    name = 'الرياضيات',
    status = 'active',
    curriculaCount = 1,
}: {
    id?: string;
    code?: string;
    name?: string;
    status?: 'active' | 'inactive';
    curriculaCount?: number;
} = {}) {
    return {
        id,
        code,
        name,
        icon_key: `subjects/${code}/icon`,
        thumbnail_key:
            `subjects/${code}/thumbnail`,
        sort_order: 10,
        status,
        curricula_count: curriculaCount,
        created_at: null,
        updated_at: null,
    };
}

function stage({
    id = 'stage-primary',
    code = 'primary',
    name = 'المرحلة الابتدائية',
    status = 'active',
}: {
    id?: string;
    code?: string;
    name?: string;
    status?: 'active' | 'inactive';
} = {}) {
    return {
        id,
        code,
        name,
        sort_order: 10,
        status,
        curricula_count: 0,
    };
}

function curriculum(
    index: number,
    {
        subjectId = 'subject-math',
        stageId = 'stage-primary',
        name,
        teacherAssignmentId = null,
    }: {
        subjectId?: string;
        stageId?: string | null;
        name?: string;
        teacherAssignmentId?:
            string | null;
    } = {},
) {
    return {
        id: `curriculum-${index}`,
        subject_id: subjectId,
        education_stage_id: stageId,
        teacher_subject_assignment_id:
            teacherAssignmentId,

        ownership_kind:
            teacherAssignmentId
                ? 'teacher_owned'
                : 'legacy_ownerless',

        teacher_user_id:
            teacherAssignmentId
                ? 'teacher-1'
                : null,

        teacher_subject_assignment_status:
            teacherAssignmentId
                ? 'active'
                : null,

        teacher:
            teacherAssignmentId
                ? {
                    user_id: 'teacher-1',
                    name: 'معلم الرياضيات',
                    email:
                        'teacher@example.test',
                    status: 'active',
                }
                : null,

        subject: {
            id: subjectId,
            code: 'mathematics',
            name: 'الرياضيات',
            status: 'active',
        },

        name:
            name
            ?? `منهج ${String(index).padStart(2, '0')}`,
        created_at: null,
        updated_at: null,
    };
}

function defaultStages() {
    return [
        stage(),
        stage({
            id: 'stage-middle',
            code: 'middle',
            name: 'المرحلة المتوسطة',
        }),
        stage({
            id: 'stage-secondary',
            code: 'secondary',
            name: 'المرحلة الثانوية',
        }),
    ];
}

function installInventory({
    subjects = [
        subject(),
    ],
    stages = defaultStages(),
    curriculaBySubject = {
        'subject-math': [
            curriculum(1, {
                name: 'منهج الرياضيات الأساسي',
            }),
        ],
    },
    onRequest,
}: InventoryOptions = {}) {
    apiRequestMock.mockImplementation(
        (config: RequestConfig) => {
            const {
                method,
                url,
            } = config;

            if (
                method === 'GET'
                && url === '/api/admin/subjects'
            ) {
                return Promise.resolve(subjects);
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/education-stages'
            ) {
                return Promise.resolve(stages);
            }

            const curriculaMatch = url.match(
                /^\/api\/admin\/subjects\/([^/]+)\/curricula$/,
            );

            if (
                method === 'GET'
                && curriculaMatch
            ) {
                return Promise.resolve(
                    curriculaBySubject[
                        curriculaMatch[1]
                    ] ?? [],
                );
            }

            if (onRequest) {
                const result = onRequest(config);

                if (result !== undefined) {
                    return Promise.resolve(result);
                }
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe('AdminCurriculaPage', () => {
    beforeEach(() => {
        apiRequestMock.mockReset();
    });

    it('presents canonical subjects without free-form subject management', async () => {
        installInventory();
        renderPage();

        expect(
            await screen.findByRole('heading', {
                name: 'استعراض المناهج',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /استعرض المواد المعتمدة وهوية المناهج وملكية المحتوى/,
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                name: 'المواد',
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', {
                name: 'المناهج',
            }),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole('button', {
                name: '+ إضافة مادة',
            }),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByLabelText('اسم المادة'),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByLabelText(
                'تعديل اسم المادة',
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByText('Admin Studio'),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByText(/دورة حياة النشر/),
        ).not.toBeInTheDocument();
    });

    it('renders canonical subject identity and historical curriculum stage context', async () => {
        installInventory();
        renderPage();

        expect(
            await screen.findByText('الرياضيات'),
        ).toBeInTheDocument();

        expect(
            await screen.findByText(
                'المرحلة الابتدائية',
            ),
        ).toBeInTheDocument();

        const visual = document.querySelector(
            '[data-icon-key="subjects/mathematics/icon"]',
        );

        expect(visual).not.toBeNull();

        expect(visual).toHaveAttribute(
            'data-thumbnail-key',
            'subjects/mathematics/thumbnail',
        );

        expect(
            apiRequestMock,
        ).toHaveBeenCalledWith({
            method: 'GET',
            url: '/api/admin/education-stages',
        });
    });

    it('filters canonical subjects locally without another API request', async () => {
        installInventory({
            subjects: [
                subject(),
                subject({
                    id: 'subject-physics',
                    code: 'physics',
                    name: 'الفيزياء',
                    curriculaCount: 0,
                }),
            ],
        });

        renderPage();

        expect(
            await screen.findByText('الرياضيات'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('الفيزياء'),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(
                apiRequestMock,
            ).toHaveBeenCalledTimes(3);
        });

        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'بحث في المواد',
            }),
            {
                target: { value: 'فيزياء' },
            },
        );

        const list = document.querySelector(
            '[aria-label="قائمة المواد"]',
        );

        expect(list).not.toBeNull();

        expect(
            within(
                list as HTMLElement,
            ).queryByText('الرياضيات'),
        ).not.toBeInTheDocument();

        expect(
            within(
                list as HTMLElement,
            ).getByText('الفيزياء'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('المادة الحالية'),
        ).toBeInTheDocument();

        expect(apiRequestMock).toHaveBeenCalledTimes(3);
    });

    it('keeps inactive canonical subjects visible for history but blocks new curricula', async () => {
        installInventory({
            subjects: [
                subject(),
                subject({
                    id: 'subject-physics',
                    code: 'physics',
                    name: 'الفيزياء',
                    status: 'inactive',
                    curriculaCount: 1,
                }),
            ],
            curriculaBySubject: {
                'subject-math': [],
                'subject-physics': [
                    curriculum(2, {
                        subjectId: 'subject-physics',
                        stageId: 'stage-secondary',
                        name: 'منهج فيزياء محفوظ',
                    }),
                ],
            },
        });

        renderPage();

        await screen.findByText('الرياضيات');

        fireEvent.click(
            screen.getByRole('button', {
                name: /اختيار مادة الفيزياء/,
            }),
        );

        expect(
            await screen.findByText(
                'منهج فيزياء محفوظ',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                /هذه المادة غير نشطة/,
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole('button', {
                name: '+ إضافة منهج',
            }),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText('غير نشط'),
        ).toBeInTheDocument();
    });

    it('filters curricula locally without another API request', async () => {
        installInventory({
            curriculaBySubject: {
                'subject-math': [
                    curriculum(1, {
                        name: 'منهج الجبر',
                    }),
                    curriculum(2, {
                        name: 'منهج الهندسة',
                    }),
                ],
            },
        });

        renderPage();

        await screen.findByText('منهج الجبر');
        await screen.findByText('منهج الهندسة');

        await waitFor(() => {
            expect(
                apiRequestMock,
            ).toHaveBeenCalledTimes(3);
        });

        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'بحث في المناهج',
            }),
            {
                target: { value: 'هندسة' },
            },
        );

        expect(
            screen.queryByText('منهج الجبر'),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText('منهج الهندسة'),
        ).toBeInTheDocument();

        expect(apiRequestMock).toHaveBeenCalledTimes(3);
    });

    it('paginates curricula locally at twenty items per page', async () => {
        installInventory({
            curriculaBySubject: {
                'subject-math': Array.from(
                    { length: 25 },
                    (_, index) =>
                        curriculum(index + 1),
                ),
            },
        });

        renderPage();

        expect(
            await screen.findByText('منهج 01'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('منهج 20'),
        ).toBeInTheDocument();

        expect(
            screen.queryByText('منهج 21'),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText('صفحة 1 من 2'),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'التالي',
            }),
        );

        expect(
            screen.getByText('منهج 21'),
        ).toBeInTheDocument();

        expect(
            screen.getByText('منهج 25'),
        ).toBeInTheDocument();

        expect(
            screen.queryByText('منهج 01'),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText('صفحة 2 من 2'),
        ).toBeInTheDocument();
    });

    it('does not expose disabled Admin Curriculum root authoring controls', async () => {
        installInventory();
        renderPage();

        await screen.findByText(
            'منهج الرياضيات الأساسي',
        );

        expect(
            screen.queryByRole(
                'button',
                {
                    name:
                        '+ إضافة منهج',
                },
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByRole(
                'button',
                {
                    name: 'تعديل',
                },
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByLabelText(
                'اسم المنهج',
            ),
        ).not.toBeInTheDocument();

        expect(
            screen.getByText(
                /دون إنشاء أو تعديل المناهج/
            ),
        ).toBeInTheDocument();
    });

    it('labels teacher-owned curriculum ownership as read-only', async () => {
        installInventory({
            curriculaBySubject: {
                'subject-math': [
                    curriculum(
                        1,
                        {
                            name:
                                'منهج مملوك للمعلم',
                            teacherAssignmentId:
                                'assignment-owned-1',
                        },
                    ),
                ],
            },
        });

        renderPage();

        expect(
            await screen.findByText(
                'منهج مملوك للمعلم',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText(
                'مملوك للمعلم — استعراض فقط',
            ),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole(
                'button',
                {
                    name: 'تعديل',
                },
            ),
        ).not.toBeInTheDocument();
    });
});

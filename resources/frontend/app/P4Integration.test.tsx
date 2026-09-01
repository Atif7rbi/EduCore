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
    AdminContentPage,
} from '../admin/AdminContentPage';

interface TestRequest {
    method: string;
    url: string;
    data?: unknown;
}

interface VersionProbeProps {
    version: {
        id: string;
        status:
            | 'draft'
            | 'published'
            | 'retired';
    };
}

const apiRequestMock = vi.fn();

vi.mock('../api/client', () => ({
    apiRequest: (
        config: TestRequest,
    ) => apiRequestMock(config),
}));

vi.mock(
    '../admin/content/TopicsPanel',
    () => ({
        TopicsPanel: ({
            version,
        }: VersionProbeProps) => (
            <div data-testid="topics-panel">
                topics:{version.id}:{version.status}
            </div>
        ),
    }),
);

vi.mock(
    '../admin/content/SkillsPanel',
    () => ({
        SkillsPanel: () => (
            <div data-testid="skills-panel">
                skills:global
            </div>
        ),
    }),
);

vi.mock(
    '../admin/content/SkillPlacementsPanel',
    () => ({
        SkillPlacementsPanel: ({
            version,
        }: VersionProbeProps) => (
            <div data-testid="skill-placements-panel">
                placements:{version.id}:{version.status}
            </div>
        ),
    }),
);

vi.mock(
    '../admin/content/LessonsPanel',
    () => ({
        LessonsPanel: ({
            version,
        }: VersionProbeProps) => (
            <div data-testid="lessons-panel">
                lessons:{version.id}:{version.status}
            </div>
        ),
    }),
);

vi.mock(
    '../admin/content/AssessmentItemsPanel',
    () => ({
        AssessmentItemsPanel: ({
            version,
        }: VersionProbeProps) => (
            <div data-testid="assessment-items-panel">
                assessments:{version.id}:{version.status}
            </div>
        ),
    }),
);

vi.mock(
    '../admin/content/PracticeActivitiesPanel',
    () => ({
        PracticeActivitiesPanel: ({
            version,
        }: VersionProbeProps) => (
            <div data-testid="practice-activities-panel">
                practice:{version.id}:{version.status}
            </div>
        ),
    }),
);

vi.mock(
    '../admin/content/ExamTemplatesPanel',
    () => ({
        ExamTemplatesPanel: ({
            version,
        }: VersionProbeProps) => (
            <div data-testid="exam-templates-panel">
                exams:{version.id}:{version.status}
            </div>
        ),
    }),
);

function queryClient() {
    return new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
            mutations: {
                retry: false,
            },
        },
    });
}

function renderAdminContent() {
    render(
        <QueryClientProvider
            client={queryClient()}
        >
            <AdminContentPage />
        </QueryClientProvider>,
    );
}

function installContextApi() {
    apiRequestMock.mockImplementation(
        ({
            method,
            url,
        }: TestRequest) => {
            if (
                method === 'GET'
                && url
                    === '/api/admin/subjects'
            ) {
                return Promise.resolve([
                    {
                        id: 'subject-1',
                        name:
                            'القدرات الكمية',
                    },
                ]);
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/subjects/subject-1/curricula'
            ) {
                return Promise.resolve([
                    {
                        id:
                            'curriculum-1',
                        subject_id:
                            'subject-1',
                        name:
                            'المنهج الكمي',
                    },
                ]);
            }

            if (
                method === 'GET'
                && url
                    === '/api/admin/curricula/curriculum-1/versions'
            ) {
                return Promise.resolve([
                    {
                        id:
                            'version-draft',
                        curriculum_id:
                            'curriculum-1',
                        version_number: 2,
                        label:
                            'نسخة التأليف',
                        status:
                            'draft',
                    },
                    {
                        id:
                            'version-published',
                        curriculum_id:
                            'curriculum-1',
                        version_number: 1,
                        label:
                            'النسخة المنشورة',
                        status:
                            'published',
                    },
                ]);
            }

            throw new Error(
                `Unexpected request ${method} ${url}`,
            );
        },
    );
}

describe(
    'P4 admin product integration',
    () => {
        beforeEach(() => {
            apiRequestMock.mockReset();
        });

        it(
            'resolves one draft curriculum context across every P4 authoring surface',
            async () => {
                installContextApi();

                renderAdminContent();

                expect(
                    await screen.findByText(
                        'هذه النسخة مسودة ويمكن تعديل محتواها.',
                    ),
                ).toBeInTheDocument();

                expect(
                    await screen.findByTestId(
                        'topics-panel',
                    ),
                ).toHaveTextContent(
                    'topics:version-draft:draft',
                );

                expect(
                    screen.getByTestId(
                        'skills-panel',
                    ),
                ).toHaveTextContent(
                    'skills:global',
                );

                expect(
                    screen.getByTestId(
                        'skill-placements-panel',
                    ),
                ).toHaveTextContent(
                    'placements:version-draft:draft',
                );

                expect(
                    screen.getByTestId(
                        'lessons-panel',
                    ),
                ).toHaveTextContent(
                    'lessons:version-draft:draft',
                );

                expect(
                    screen.getByTestId(
                        'assessment-items-panel',
                    ),
                ).toHaveTextContent(
                    'assessments:version-draft:draft',
                );

                expect(
                    screen.getByTestId(
                        'practice-activities-panel',
                    ),
                ).toHaveTextContent(
                    'practice:version-draft:draft',
                );

                expect(
                    screen.getByTestId(
                        'exam-templates-panel',
                    ),
                ).toHaveTextContent(
                    'exams:version-draft:draft',
                );
            },
        );

        it(
            'switches the complete P4 workspace to the selected published read-only context',
            async () => {
                installContextApi();

                renderAdminContent();

                await screen.findByText(
                    'هذه النسخة مسودة ويمكن تعديل محتواها.',
                );

                const versionSelect =
                    screen.getByLabelText(
                        'إصدار المنهج',
                    );

                fireEvent.change(
                    versionSelect,
                    {
                        target: {
                            value:
                                'version-published',
                        },
                    },
                );

                expect(
                    await screen.findByText(
                        'هذه النسخة للقراءة فقط؛ عمليات التأليف مجمدة.',
                    ),
                ).toBeInTheDocument();

                expect(
                    screen.getByTestId(
                        'topics-panel',
                    ),
                ).toHaveTextContent(
                    'topics:version-published:published',
                );

                expect(
                    screen.getByTestId(
                        'skill-placements-panel',
                    ),
                ).toHaveTextContent(
                    'placements:version-published:published',
                );

                expect(
                    screen.getByTestId(
                        'lessons-panel',
                    ),
                ).toHaveTextContent(
                    'lessons:version-published:published',
                );

                expect(
                    screen.getByTestId(
                        'assessment-items-panel',
                    ),
                ).toHaveTextContent(
                    'assessments:version-published:published',
                );

                expect(
                    screen.getByTestId(
                        'practice-activities-panel',
                    ),
                ).toHaveTextContent(
                    'practice:version-published:published',
                );

                expect(
                    screen.getByTestId(
                        'exam-templates-panel',
                    ),
                ).toHaveTextContent(
                    'exams:version-published:published',
                );
            },
        );
    },
);

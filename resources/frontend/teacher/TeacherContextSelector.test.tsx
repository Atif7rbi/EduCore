import {
    QueryClient,
    QueryClientProvider,
} from '@tanstack/react-query';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import {
    fetchTeacherAssignments,
    fetchTeacherCurricula,
    fetchTeacherCurriculumVersions,
} from './api';
import {
    TeacherContextSelector,
} from './TeacherContextSelector';

vi.mock('./api', async () => {
    const actual =
        await vi.importActual<
            typeof import('./api')
        >('./api');

    return {
        ...actual,
        fetchTeacherAssignments: vi.fn(),
        fetchTeacherCurricula: vi.fn(),
        fetchTeacherCurriculumVersions: vi.fn(),
    };
});

const assignmentsMock =
    vi.mocked(fetchTeacherAssignments);
const curriculaMock =
    vi.mocked(fetchTeacherCurricula);
const versionsMock =
    vi.mocked(fetchTeacherCurriculumVersions);

function assignment(
    id: string,
    status: 'active' | 'inactive' = 'active',
    subjectStatus: 'active' | 'inactive' = 'active',
) {
    return {
        id,
        teacher_user_id: 'teacher-a',
        subject: {
            id: 'subject-' + id,
            code: id,
            name: 'Subject ' + id,
            status: subjectStatus,
        },
        status,
        created_at: null,
        updated_at: null,
    };
}

function curriculum(
    id: string,
    assignmentId = 'assignment-a',
) {
    return {
        id,
        subject_id: 'subject-' + assignmentId,
        education_stage_id: null,
        teacher_subject_assignment_id: assignmentId,
        name: 'Curriculum ' + id,
    };
}

function version(
    id: string,
    curriculumId = 'curriculum-a',
) {
    return {
        id,
        curriculum_id: curriculumId,
        version_number: 1,
        label: 'Version ' + id,
        status: 'draft' as const,
    };
}

function renderSelector(
    overrides: Partial<
        React.ComponentProps<
            typeof TeacherContextSelector
        >
    > = {},
) {
    const client = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            },
        },
    });
    const onContextResolved = vi.fn();
    const onContextUnavailable = vi.fn();

    const view = render(
        <QueryClientProvider client={client}>
            <TeacherContextSelector
                authenticatedUserId="teacher-a"
                assignmentId={null}
                curriculumId={null}
                curriculumVersionId={null}
                onContextResolved={
                    onContextResolved
                }
                onContextUnavailable={
                    onContextUnavailable
                }
                {...overrides}
            />
        </QueryClientProvider>,
    );

    return {
        client,
        ...view,
        onContextResolved,
        onContextUnavailable,
    };
}

describe('TeacherContextSelector', () => {
    beforeEach(() => {
        assignmentsMock.mockReset();
        curriculaMock.mockReset();
        versionsMock.mockReset();
    });

    afterEach(() => {
        cleanup();
    });

    it('uses server ordering, keeps inactive assignments disabled, and resolves a full context', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
            assignment('assignment-b'),
            assignment(
                'assignment-inactive',
                'inactive',
            ),
        ]);
        curriculaMock.mockResolvedValue([
            curriculum('curriculum-a'),
        ]);
        versionsMock.mockResolvedValue([
            version('version-a'),
        ]);

        const {
            onContextResolved,
        } = renderSelector();

        await waitFor(() => {
            expect(
                screen.getByRole('option', {
                    name: 'Subject assignment-a',
                }),
            ).toBeInTheDocument();
        });

        const options =
            screen.getAllByRole('option');

        expect(
            options.map((option) =>
                option.textContent,
            ),
        ).toEqual([
            'اختر تعيين المادة',
            'Subject assignment-a',
            'Subject assignment-b',
            'Subject assignment-inactive',
            'اختر المنهج',
            'اختر إصدار المنهج',
        ]);
        expect(
            screen.getByRole('option', {
                name: 'Subject assignment-inactive',
            }),
        ).toBeDisabled();

        fireEvent.change(
            screen.getByLabelText('تعيين المادة'),
            {
                target: {
                    value: 'assignment-a',
                },
            },
        );

        await waitFor(() => {
            expect(
                screen.getByRole('option', {
                    name: 'Curriculum curriculum-a',
                }),
            ).toBeInTheDocument();
        });

        fireEvent.change(
            screen.getByLabelText('المنهج'),
            {
                target: {
                    value: 'curriculum-a',
                },
            },
        );

        await waitFor(() => {
            expect(
                screen.getByRole('option', {
                    name: 'الإصدار 1 — Version version-a',
                }),
            ).toBeInTheDocument();
        });

        fireEvent.change(
            screen.getByLabelText('إصدار المنهج'),
            {
                target: {
                    value: 'version-a',
                },
            },
        );

        await waitFor(() => {
            expect(onContextResolved).toHaveBeenCalledWith({
                assignmentId: 'assignment-a',
                curriculumId: 'curriculum-a',
                curriculumVersionId: 'version-a',
            });
        });
    });

    it('restores a valid scoped URL only after every parent collection validates it', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
        ]);
        curriculaMock.mockResolvedValue([
            curriculum('curriculum-a'),
        ]);
        versionsMock.mockResolvedValue([
            version('version-a'),
        ]);

        const {
            onContextResolved,
        } = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
        });

        await waitFor(() => {
            expect(onContextResolved).toHaveBeenCalledWith({
                assignmentId: 'assignment-a',
                curriculumId: 'curriculum-a',
                curriculumVersionId: 'version-a',
            });
        });

        expect(curriculaMock).toHaveBeenCalledWith(
            'assignment-a',
            expect.any(AbortSignal),
        );
        expect(versionsMock).toHaveBeenCalledWith(
            'assignment-a',
            'curriculum-a',
            expect.any(AbortSignal),
        );
    });

    it('rejects cross-curriculum and invalid-version scoped routes without loading content APIs', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
        ]);
        curriculaMock.mockResolvedValue([
            curriculum('curriculum-a'),
        ]);
        versionsMock.mockResolvedValue([]);

        const {
            onContextUnavailable,
        } = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'missing-version',
        });

        await waitFor(() => {
            expect(onContextUnavailable).toHaveBeenCalled();
        });

        expect(versionsMock).toHaveBeenCalledWith(
            'assignment-a',
            'curriculum-a',
            expect.any(AbortSignal),
        );
    });


    it('rejects a cross-curriculum scoped route before it requests versions', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
        ]);
        curriculaMock.mockResolvedValue([
            curriculum('curriculum-a'),
        ]);

        const {
            onContextUnavailable,
        } = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-b',
            curriculumVersionId: 'version-b',
        });

        await waitFor(() => {
            expect(onContextUnavailable).toHaveBeenCalled();
        });
        expect(versionsMock).not.toHaveBeenCalled();
    });

    it('shows an empty authorized curriculum collection without requesting versions', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
        ]);
        curriculaMock.mockResolvedValue([]);

        renderSelector();

        await waitFor(() => {
            expect(
                screen.getByLabelText('تعيين المادة'),
            ).toBeEnabled();
        });

        fireEvent.change(
            screen.getByLabelText('تعيين المادة'),
            {
                target: {
                    value: 'assignment-a',
                },
            },
        );

        await waitFor(() => {
            expect(
                screen.getByText(
                    'لا توجد مناهج متاحة لهذا التعيين.',
                ),
            ).toBeInTheDocument();
        });
        expect(versionsMock).not.toHaveBeenCalled();
    });


    it('uses a newly requested scoped route while delayed parent responses settle', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
            assignment('assignment-b'),
        ]);
        let resolveCurricula:
            (items: ReturnType<typeof curriculum>[]) => void =
                () => undefined;
        let resolveVersions:
            (items: ReturnType<typeof version>[]) => void =
                () => undefined;

        curriculaMock.mockImplementation(
            (assignmentId) => {
                if (assignmentId === 'assignment-a') {
                    return Promise.resolve([
                        curriculum('curriculum-a'),
                    ]);
                }

                return new Promise((resolve) => {
                    resolveCurricula = resolve;
                });
            },
        );
        versionsMock.mockImplementation(
            (_assignmentId, curriculumId) => {
                if (curriculumId === 'curriculum-a') {
                    return Promise.resolve([
                        version('version-a'),
                    ]);
                }

                return new Promise((resolve) => {
                    resolveVersions = resolve;
                });
            },
        );

        const view = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
        });

        await waitFor(() => {
            expect(view.onContextResolved).toHaveBeenCalledWith({
                assignmentId: 'assignment-a',
                curriculumId: 'curriculum-a',
                curriculumVersionId: 'version-a',
            });
        });
        view.onContextResolved.mockClear();

        view.rerender(
            <QueryClientProvider client={view.client}>
                <TeacherContextSelector
                    authenticatedUserId="teacher-a"
                    assignmentId="assignment-b"
                    curriculumId="curriculum-b"
                    curriculumVersionId="version-b"
                    onContextResolved={
                        view.onContextResolved
                    }
                    onContextUnavailable={
                        view.onContextUnavailable
                    }
                />
            </QueryClientProvider>,
        );

        await waitFor(() => {
            expect(curriculaMock).toHaveBeenCalledWith(
                'assignment-b',
                expect.any(AbortSignal),
            );
        });
        expect(view.onContextResolved).not.toHaveBeenCalled();

        resolveCurricula([
            curriculum(
                'curriculum-b',
                'assignment-b',
            ),
        ]);

        await waitFor(() => {
            expect(versionsMock).toHaveBeenCalledWith(
                'assignment-b',
                'curriculum-b',
                expect.any(AbortSignal),
            );
        });
        expect(view.onContextResolved).not.toHaveBeenCalled();

        resolveVersions([
            version('version-b', 'curriculum-b'),
        ]);

        await waitFor(() => {
            expect(view.onContextResolved).toHaveBeenCalledTimes(1);
            expect(view.onContextResolved).toHaveBeenCalledWith({
                assignmentId: 'assignment-b',
                curriculumId: 'curriculum-b',
                curriculumVersionId: 'version-b',
            });
        });
    });

    it('rejects a curriculum that is returned under the wrong assignment', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
        ]);
        curriculaMock.mockResolvedValue([
            curriculum('curriculum-a', 'assignment-b'),
        ]);

        const {
            onContextUnavailable,
        } = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
        });

        await waitFor(() => {
            expect(onContextUnavailable).toHaveBeenCalled();
        });
        expect(versionsMock).not.toHaveBeenCalled();
    });

    it('rejects a version that is returned under the wrong curriculum', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
        ]);
        curriculaMock.mockResolvedValue([
            curriculum('curriculum-a'),
        ]);
        versionsMock.mockResolvedValue([
            version('version-a', 'curriculum-b'),
        ]);

        const {
            onContextUnavailable,
        } = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
        });

        await waitFor(() => {
            expect(onContextUnavailable).toHaveBeenCalled();
        });
    });

    it('rejects an active assignment whose subject is inactive', async () => {
        assignmentsMock.mockResolvedValue([
            assignment(
                'assignment-a',
                'active',
                'inactive',
            ),
        ]);

        const {
            onContextUnavailable,
        } = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
        });

        await waitFor(() => {
            expect(onContextUnavailable).toHaveBeenCalled();
        });
        expect(curriculaMock).not.toHaveBeenCalled();
        expect(versionsMock).not.toHaveBeenCalled();
    });

    it('treats an asynchronously revoked assignment as unavailable', async () => {
        let resolveAssignments:
            (items: ReturnType<typeof assignment>[]) => void =
                () => undefined;

        assignmentsMock.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolveAssignments = resolve;
                }),
        );

        const {
            onContextUnavailable,
        } = renderSelector({
            assignmentId: 'assignment-a',
            curriculumId: 'curriculum-a',
            curriculumVersionId: 'version-a',
        });

        resolveAssignments([
            assignment(
                'assignment-a',
                'inactive',
            ),
        ]);

        await waitFor(() => {
            expect(onContextUnavailable).toHaveBeenCalled();
        });
        expect(curriculaMock).not.toHaveBeenCalled();
        expect(versionsMock).not.toHaveBeenCalled();
    });

    it('cancels an obsolete curriculum request when assignment selection changes', async () => {
        assignmentsMock.mockResolvedValue([
            assignment('assignment-a'),
            assignment('assignment-b'),
        ]);
        let firstSignal: AbortSignal | undefined;

        curriculaMock.mockImplementation(
            (assignmentId, signal) => {
                if (assignmentId === 'assignment-a') {
                    firstSignal = signal;

                    return new Promise(() => undefined);
                }

                return Promise.resolve([]);
            },
        );

        renderSelector();

        await waitFor(() => {
            expect(
                screen.getByLabelText(
                    'تعيين المادة',
                ),
            ).toBeEnabled();
        });

        fireEvent.change(
            screen.getByLabelText('تعيين المادة'),
            {
                target: {
                    value: 'assignment-a',
                },
            },
        );

        await waitFor(() => {
            expect(firstSignal).toBeDefined();
        });

        fireEvent.change(
            screen.getByLabelText('تعيين المادة'),
            {
                target: {
                    value: 'assignment-b',
                },
            },
        );

        await waitFor(() => {
            expect(firstSignal?.aborted).toBe(true);
        });
    });

    it('uses a principal-scoped assignment key after an account change', async () => {
        assignmentsMock
            .mockResolvedValueOnce([
                assignment('assignment-a'),
            ])
            .mockResolvedValueOnce([
                assignment('assignment-b'),
            ]);

        const view = renderSelector();

        await waitFor(() => {
            expect(
                screen.getByRole('option', {
                    name: 'Subject assignment-a',
                }),
            ).toBeInTheDocument();
        });

        view.rerender(
            <QueryClientProvider
                client={new QueryClient({
                    defaultOptions: {
                        queries: {
                            retry: false,
                        },
                    },
                })}
            >
                <TeacherContextSelector
                    authenticatedUserId="teacher-b"
                    assignmentId={null}
                    curriculumId={null}
                    curriculumVersionId={null}
                    onContextResolved={
                        view.onContextResolved
                    }
                    onContextUnavailable={
                        view.onContextUnavailable
                    }
                />
            </QueryClientProvider>,
        );

        await waitFor(() => {
            expect(
                screen.getByRole('option', {
                    name: 'Subject assignment-b',
                }),
            ).toBeInTheDocument();
        });
        expect(
            screen.queryByRole('option', {
                name: 'Subject assignment-a',
            }),
        ).not.toBeInTheDocument();
    });
});

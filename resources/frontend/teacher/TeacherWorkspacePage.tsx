import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    useBeforeUnload,
    useBlocker,
    useLocation,
    useNavigate,
    useParams,
} from 'react-router-dom';

import {
    useQueryClient,
} from '@tanstack/react-query';

import {
    useAuth,
} from '../auth/AuthProvider';
import {
    Feedback,
    Surface,
} from '../ui';

import {
    TeacherContextSelector,
} from './TeacherContextSelector';
import {
    teacherCurriculumVersionsKey,
} from './api';
import {
    TeacherSkillPlacementsPanel,
} from './TeacherSkillPlacementsPanel';
import {
    TeacherExamTemplatesPanel,
} from './TeacherExamTemplatesPanel';
import {
    TeacherPracticeActivitiesPanel,
} from './TeacherPracticeActivitiesPanel';
import {
    TeacherLessonsPanel,
} from './TeacherLessonsPanel';
import {
    TeacherAssessmentItemsPanel,
} from './TeacherAssessmentItemsPanel';
import {
    TeacherTopicsPanel,
} from './TeacherTopicsPanel';

import type {
    TeacherContext,
} from './TeacherContextSelector';

interface WorkspaceLocationState {
    teacherContextUnavailable?: boolean;
}

const workspaceSections = [
    {
        id: 'topics',
        label: 'الموضوعات والمهارات',
    },
    {
        id: 'lessons',
        label: 'الدروس',
    },
    {
        id: 'assessments',
        label: 'عناصر التقييم',
    },
    {
        id: 'practice',
        label: 'أنشطة التدريب',
    },
    {
        id: 'exams',
        label: 'نماذج الامتحان',
    },
] as const;

type WorkspaceSection =
    typeof workspaceSections[number]['id'];

const defaultWorkspaceSection: WorkspaceSection = 'topics';

function workspaceSectionFromSearch(
    search: string,
): WorkspaceSection | null {
    const section = new URLSearchParams(search).get('section');

    return workspaceSections.some(
        (candidate) => candidate.id === section,
    )
        ? section as WorkspaceSection
        : null;
}

function workspaceSearch(
    section: WorkspaceSection,
): string {
    return `?section=${section}`;
}

function navigationTarget(location: {
    hash: string;
    pathname: string;
    search: string;
}): string {
    return location.pathname + location.search + location.hash;
}

interface UnsavedNavigationGuardProps {
    approvedTargetRef: {
        current: string | null;
    };
    forcedTargetRef: {
        current: string | null;
    };
    onDiscard: () => boolean;
}

function UnsavedNavigationGuard({
    approvedTargetRef,
    forcedTargetRef,
    onDiscard,
}: UnsavedNavigationGuardProps) {
    const blocker = useBlocker(
        ({ nextLocation }) => {
            const target = navigationTarget(nextLocation);

            return target !== approvedTargetRef.current
                && target !== forcedTargetRef.current;
        },
    );
    const deciding = useRef(false);

    useBeforeUnload((event) => {
        event.preventDefault();
        event.returnValue = '';
    });

    useEffect(() => {
        if (blocker.state !== 'blocked') {
            deciding.current = false;

            return;
        }

        if (deciding.current) {
            return;
        }

        deciding.current = true;

        if (onDiscard()) {
            approvedTargetRef.current = navigationTarget(
                blocker.location,
            );
            blocker.proceed();

            return;
        }

        approvedTargetRef.current = null;
        blocker.reset();
    }, [
        approvedTargetRef,
        blocker,
        onDiscard,
    ]);

    return null;
}

export function TeacherWorkspacePage() {
    const {
        assignmentId,
        curriculumId,
        versionId,
    } = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const {
        status,
        user,
    } = useAuth();
    const locationState =
        location.state as WorkspaceLocationState
        | null;
    const contextUnavailable =
        locationState?.teacherContextUnavailable
        === true;
    const [context, setContext] =
        useState<TeacherContext | null>(null);
    const [topicsDirty, setTopicsDirty] = useState(false);
    const [placementsDirty, setPlacementsDirty] =
        useState(false);
    const [lessonsDirty, setLessonsDirty] = useState(false);
    const [assessmentsDirty, setAssessmentsDirty] = useState(false);
    const [practicesDirty, setPracticesDirty] = useState(false);
    const [examsDirty, setExamsDirty] = useState(false);
    const approvedNavigationTarget = useRef<string | null>(null);
    const forcedNavigationTarget = useRef<string | null>(null);
    const routeKey = [
        assignmentId ?? '',
        curriculumId ?? '',
        versionId ?? '',
    ].join(':');
    const activeSection = workspaceSectionFromSearch(
        location.search,
    );
    const hasUnsavedChanges = topicsDirty
        || placementsDirty
        || lessonsDirty
        || assessmentsDirty
        || practicesDirty
        || examsDirty;
    const contextInstance = useMemo(
        () => Symbol('teacher-workspace-context'),
        [
            context?.assignmentId,
            context?.curriculumId,
            context?.curriculumVersionId,
            routeKey,
            user?.id,
        ],
    );
    const sectionInstance = useMemo(
        () => Symbol('teacher-workspace-section'),
        [
            activeSection,
            contextInstance,
        ],
    );
    const currentContextInstance = useRef<symbol | null>(null);
    const currentSectionInstance = useRef<symbol | null>(null);

    useLayoutEffect(() => {
        currentContextInstance.current = contextInstance;

        return () => {
            if (currentContextInstance.current === contextInstance) {
                currentContextInstance.current = null;
            }
        };
    }, [contextInstance]);

    useLayoutEffect(() => {
        currentSectionInstance.current = sectionInstance;

        return () => {
            if (currentSectionInstance.current === sectionInstance) {
                currentSectionInstance.current = null;
            }
        };
    }, [sectionInstance]);

    const clearDirtyState = useCallback(() => {
        setTopicsDirty(false);
        setPlacementsDirty(false);
        setLessonsDirty(false);
        setAssessmentsDirty(false);
        setPracticesDirty(false);
        setExamsDirty(false);
    }, []);

    useEffect(() => {
        approvedNavigationTarget.current = null;
        forcedNavigationTarget.current = null;
        setContext(null);
        clearDirtyState();
    }, [
        clearDirtyState,
        routeKey,
    ]);

    useEffect(() => {
        approvedNavigationTarget.current = null;
        forcedNavigationTarget.current = null;
    }, [location.key]);

    useEffect(() => {
        if (context === null || activeSection !== null) {
            return;
        }

        navigate(
            {
                hash: location.hash,
                pathname: location.pathname,
                search: workspaceSearch(defaultWorkspaceSection),
            },
            {
                replace: true,
            },
        );
    }, [
        activeSection,
        context,
        location.hash,
        location.pathname,
        navigate,
    ]);

    const discardUnsavedChanges = useCallback(() => {
        if (!hasUnsavedChanges) {
            return true;
        }

        const accepted = window.confirm(
            'لديك تغييرات غير محفوظة. هل تريد المتابعة وتجاهلها؟',
        );

        if (accepted) {
            clearDirtyState();
        }

        return accepted;
    }, [
        clearDirtyState,
        hasUnsavedChanges,
    ]);

    const handleContextResolved = useCallback(
        (nextContext: TeacherContext) => {
            const destinationPathname =
                '/teacher/workspace/'
                + nextContext.assignmentId
                + '/curricula/'
                + nextContext.curriculumId
                + '/versions/'
                + nextContext.curriculumVersionId;
            const preservedSection = activeSection
                ?? defaultWorkspaceSection;

            if (location.pathname !== destinationPathname) {
                navigate({
                    hash: location.hash,
                    pathname: destinationPathname,
                    search: workspaceSearch(preservedSection),
                });

                return;
            }

            setContext(nextContext);
        },
        [
            activeSection,
            location.hash,
            location.pathname,
            navigate,
        ],
    );

    const refreshLifecycleAuthority = useCallback(
        async () => {
            if (!user || !context) {
                return;
            }

            await queryClient.invalidateQueries({
                queryKey: teacherCurriculumVersionsKey(
                    user.id,
                    context.assignmentId,
                    context.curriculumId,
                ),
            });
        },
        [
            context,
            queryClient,
            user,
        ],
    );

    const handleContextUnavailable = useCallback(
        () => {
            forcedNavigationTarget.current = '/teacher/workspace';
            approvedNavigationTarget.current = null;
            setContext(null);
            clearDirtyState();
            navigate(
                '/teacher/workspace',
                {
                    replace: true,
                    state: {
                        teacherContextUnavailable: true,
                    },
                },
            );
        },
        [
            clearDirtyState,
            navigate,
        ],
    );
    const handleActiveSectionUnavailable = useCallback(
        () => {
            if (currentSectionInstance.current !== sectionInstance) {
                return;
            }

            handleContextUnavailable();
        },
        [
            handleContextUnavailable,
            sectionInstance,
        ],
    );
    const refreshCurrentContextLifecycle = useCallback(
        async () => {
            if (currentContextInstance.current !== contextInstance) {
                return;
            }

            await refreshLifecycleAuthority();
        },
        [
            contextInstance,
            refreshLifecycleAuthority,
        ],
    );

    const selectSection = useCallback(
        (section: WorkspaceSection) => {
            if (section === activeSection) {
                return;
            }

            navigate({
                hash: location.hash,
                pathname: location.pathname,
                search: workspaceSearch(section),
            });
        },
        [
            activeSection,
            location.hash,
            location.pathname,
            navigate,
        ],
    );

    return (
        <section
            className="foundation-page"
            aria-labelledby="teacher-workspace-title"
        >
            <div className="foundation-page__heading">
                <p className="foundation-page__eyebrow">
                    مساحة المعلم
                </p>

                <h1
                    className="foundation-page__title"
                    id="teacher-workspace-title"
                >
                    تأليف المحتوى
                </h1>

                <p className="foundation-page__description">
                    اختر تعيين المادة والمنهج والإصدار قبل بدء التأليف.
                </p>
            </div>

            <Surface className="foundation-card">
                {contextUnavailable ? (
                    <Feedback tone="warning">
                        سياق التأليف غير متاح أو لم تعد لديك صلاحية الوصول إليه.
                    </Feedback>
                ) : null}

                {status === 'authenticated'
                    && user ? (
                    <TeacherContextSelector
                        assignmentId={assignmentId ?? null}
                        authenticatedUserId={user.id}
                        curriculumId={curriculumId ?? null}
                        curriculumVersionId={versionId ?? null}
                        onBeforeContextChange={
                            discardUnsavedChanges
                        }
                        onContextResolved={
                            handleContextResolved
                        }
                        onContextUnavailable={
                            handleContextUnavailable
                        }
                    />
                ) : (
                    <Feedback>
                        جار التحقق من جلسة المعلم…
                    </Feedback>
                )}
            </Surface>

            {hasUnsavedChanges ? (
                <UnsavedNavigationGuard
                    approvedTargetRef={approvedNavigationTarget}
                    forcedTargetRef={forcedNavigationTarget}
                    onDiscard={discardUnsavedChanges}
                />
            ) : null}

            {context && user && activeSection ? (
                <div
                    className="foundation-stack teacher-workspace"
                    key={
                        user.id
                        + ':'
                        + context.assignmentId
                        + ':'
                        + context.curriculumId
                        + ':'
                        + context.curriculumVersionId
                    }
                >
                    <nav
                        aria-label="أقسام مساحة المعلم"
                        className="teacher-workspace-sections"
                    >
                        {workspaceSections.map((section) => (
                            <button
                                aria-current={
                                    section.id === activeSection
                                        ? 'page'
                                        : undefined
                                }
                                className={
                                    section.id === activeSection
                                        ? 'teacher-workspace-sections__item teacher-workspace-sections__item--active'
                                        : 'teacher-workspace-sections__item'
                                }
                                key={section.id}
                                onClick={() => selectSection(section.id)}
                                type="button"
                            >
                                {section.label}
                            </button>
                        ))}
                    </nav>

                    <section
                        aria-label={
                            workspaceSections.find(
                                (section) => section.id === activeSection,
                            )?.label
                        }
                        className="foundation-stack teacher-workspace-section"
                    >
                        {activeSection === 'topics' ? (
                            <>
                                <TeacherTopicsPanel
                                    authenticatedUserId={user.id}
                                    context={context}
                                    onContextUnavailable={
                                        handleActiveSectionUnavailable
                                    }
                                    onDirtyChange={setTopicsDirty}
                                    onLifecycleConflict={
                                        refreshCurrentContextLifecycle
                                    }
                                />

                                <TeacherSkillPlacementsPanel
                                    authenticatedUserId={user.id}
                                    context={context}
                                    onContextUnavailable={
                                        handleActiveSectionUnavailable
                                    }
                                    onDirtyChange={setPlacementsDirty}
                                    onLifecycleConflict={
                                        refreshCurrentContextLifecycle
                                    }
                                />
                            </>
                        ) : null}

                        {activeSection === 'lessons' ? (
                            <TeacherLessonsPanel
                                authenticatedUserId={user.id}
                                context={context}
                                onContextUnavailable={
                                    handleActiveSectionUnavailable
                                }
                                onDirtyChange={setLessonsDirty}
                                onLifecycleConflict={
                                    refreshCurrentContextLifecycle
                                }
                            />
                        ) : null}

                        {activeSection === 'assessments' ? (
                            <TeacherAssessmentItemsPanel
                                authenticatedUserId={user.id}
                                context={context}
                                onContextUnavailable={
                                    handleActiveSectionUnavailable
                                }
                                onDirtyChange={setAssessmentsDirty}
                                onLifecycleConflict={
                                    refreshCurrentContextLifecycle
                                }
                            />
                        ) : null}

                        {activeSection === 'practice' ? (
                            <TeacherPracticeActivitiesPanel
                                authenticatedUserId={user.id}
                                context={context}
                                onContextUnavailable={
                                    handleActiveSectionUnavailable
                                }
                                onDirtyChange={setPracticesDirty}
                                onLifecycleConflict={
                                    refreshCurrentContextLifecycle
                                }
                            />
                        ) : null}

                        {activeSection === 'exams' ? (
                            <TeacherExamTemplatesPanel
                                authenticatedUserId={user.id}
                                context={context}
                                onContextUnavailable={
                                    handleActiveSectionUnavailable
                                }
                                onDirtyChange={setExamsDirty}
                                onLifecycleConflict={
                                    refreshCurrentContextLifecycle
                                }
                            />
                        ) : null}
                    </section>
                </div>
            ) : null}
        </section>
    );
}

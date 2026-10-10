import {
    useCallback,
    useEffect,
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
    TeacherTopicsPanel,
} from './TeacherTopicsPanel';

import type {
    TeacherContext,
} from './TeacherContextSelector';

interface WorkspaceLocationState {
    teacherContextUnavailable?: boolean;
}

interface UnsavedNavigationGuardProps {
    bypassRef: {
        current: boolean;
    };
    onDiscard: () => boolean;
}

function UnsavedNavigationGuard({
    bypassRef,
    onDiscard,
}: UnsavedNavigationGuardProps) {
    const blocker = useBlocker(
        () => !bypassRef.current,
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
            blocker.proceed();

            return;
        }

        blocker.reset();
    }, [
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
    const discardConfirmed = useRef(false);
    const forcedNavigation = useRef(false);
    const routeKey = [
        assignmentId ?? '',
        curriculumId ?? '',
        versionId ?? '',
    ].join(':');
    const hasUnsavedChanges = topicsDirty || placementsDirty;

    useEffect(() => {
        discardConfirmed.current = false;
        forcedNavigation.current = false;
        setContext(null);
        setTopicsDirty(false);
        setPlacementsDirty(false);
    }, [routeKey]);

    useEffect(() => {
        if (hasUnsavedChanges) {
            discardConfirmed.current = false;
        }
    }, [hasUnsavedChanges]);

    const discardUnsavedChanges = useCallback(() => {
        if (
            !hasUnsavedChanges
            || discardConfirmed.current
        ) {
            return true;
        }

        const accepted = window.confirm(
            'لديك تغييرات غير محفوظة. هل تريد المتابعة وتجاهلها؟',
        );

        if (accepted) {
            discardConfirmed.current = true;
            setTopicsDirty(false);
            setPlacementsDirty(false);
        }

        return accepted;
    }, [hasUnsavedChanges]);

    const handleContextResolved = useCallback(
        (nextContext: TeacherContext) => {
            const destination =
                '/teacher/workspace/'
                + nextContext.assignmentId
                + '/curricula/'
                + nextContext.curriculumId
                + '/versions/'
                + nextContext.curriculumVersionId;

            if (location.pathname !== destination) {
                navigate(destination);

                return;
            }

            setContext(nextContext);
        },
        [
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
            forcedNavigation.current = true;
            discardConfirmed.current = false;
            setContext(null);
            setTopicsDirty(false);
            setPlacementsDirty(false);
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
        [navigate],
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
                    bypassRef={forcedNavigation}
                    onDiscard={discardUnsavedChanges}
                />
            ) : null}

            {context && user ? (
                <div
                    className="foundation-stack"
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
                    <TeacherTopicsPanel
                        authenticatedUserId={user.id}
                        context={context}
                        onContextUnavailable={
                            handleContextUnavailable
                        }
                        onDirtyChange={setTopicsDirty}
                        onLifecycleConflict={
                            refreshLifecycleAuthority
                        }
                    />

                    <TeacherSkillPlacementsPanel
                        authenticatedUserId={user.id}
                        context={context}
                        onContextUnavailable={
                            handleContextUnavailable
                        }
                        onDirtyChange={setPlacementsDirty}
                        onLifecycleConflict={
                            refreshLifecycleAuthority
                        }
                    />
                </div>
            ) : null}
        </section>
    );
}

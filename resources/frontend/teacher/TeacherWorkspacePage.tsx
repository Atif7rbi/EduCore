import {
    useCallback,
} from 'react';
import {
    useLocation,
    useNavigate,
    useParams,
} from 'react-router-dom';

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

import type {
    TeacherContext,
} from './TeacherContextSelector';

interface WorkspaceLocationState {
    teacherContextUnavailable?: boolean;
}

export function TeacherWorkspacePage() {
    const {
        assignmentId,
        curriculumId,
        versionId,
    } = useParams();
    const location = useLocation();
    const navigate = useNavigate();
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

    const handleContextResolved = useCallback(
        (context: TeacherContext) => {
            const destination =
                '/teacher/workspace/'
                + context.assignmentId
                + '/curricula/'
                + context.curriculumId
                + '/versions/'
                + context.curriculumVersionId;

            if (location.pathname === destination) {
                return;
            }

            navigate(destination);
        },
        [
            location.pathname,
            navigate,
        ],
    );

    const handleContextUnavailable = useCallback(
        () => {
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
                        authenticatedUserId={user.id}
                        assignmentId={assignmentId ?? null}
                        curriculumId={curriculumId ?? null}
                        curriculumVersionId={versionId ?? null}
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
        </section>
    );
}

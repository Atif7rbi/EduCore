import {
    apiRequest,
} from '../api/client';

import type {
    TeacherEnrollment,
    TeacherEnrollmentPage,
} from './enrollmentApi';

export type MonitoringVersionStatus =
    | 'published'
    | 'retired';

export interface TeacherMonitoringScopeSummary {
    enrollment: {
        id: string;
        status: 'active';
        student: {
            user_id: string;
            name: string;
        };
    };
    teacher_subject_assignment: {
        id: string;
        status: 'active';
        subject: {
            id: string;
            code: string;
            name: string;
        };
    };
    curriculum: {
        id: string;
        name: string;
    };
    curriculum_version: {
        id: string;
        version_number: number;
        label: string;
        status: MonitoringVersionStatus;
    };
}

export type LessonProgressStatus =
    | 'in_progress'
    | 'completed'
    | 'not_recorded';

export interface TeacherLessonProgressResponse
    extends TeacherMonitoringScopeSummary {
    items: Array<{
        lesson: {
            id: string;
            title: string;
            display_order: number;
        };
        lesson_revision: {
            id: string;
            revision_number: number;
            released_at: string | null;
        };
        progress: {
            status: LessonProgressStatus;
            started_at: string | null;
            completed_at: string | null;
        };
    }>;
    recorded_started_count: number;
    recorded_completed_count: number;
}

export type TeacherAttemptKind =
    | 'practice'
    | 'exam';

export type TeacherAttemptStatus =
    | 'submitted'
    | 'abandoned';

export interface EffectiveResultSummary {
    answered: number;
    correct: number;
    incorrect: number;
    unanswered: number;
    total: number;
}

export interface TeacherMonitoredAttempt {
    id: string;
    kind: TeacherAttemptKind;
    source: {
        practice_activity_id: string | null;
        exam_generation_id: string | null;
    };
    curriculum_version_id: string;
    status: TeacherAttemptStatus;
    started_at: string | null;
    finalized_at: string | null;
    result:
        | {
            availability: 'available';
            summary: EffectiveResultSummary;
        }
        | {
            availability: 'not_applicable';
            reason: 'attempt_abandoned';
            summary: null;
        };
}

export interface TeacherAttemptsResponse
    extends TeacherMonitoringScopeSummary {
    items: TeacherMonitoredAttempt[];
    pagination: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
}

export interface TeacherAttemptResultResponse
    extends TeacherMonitoringScopeSummary {
    attempt: TeacherMonitoredAttempt;
}

export function teacherMonitoringRootKey(
    authenticatedUserId: string,
) {
    return [
        'teacher',
        authenticatedUserId,
        'monitoring',
    ] as const;
}

export function teacherMonitoringEnrollmentContextKey(
    authenticatedUserId: string,
    enrollmentId: string,
) {
    return [
        ...teacherMonitoringRootKey(authenticatedUserId),
        'enrollment-context',
        enrollmentId,
    ] as const;
}

function monitoringScopeKey(
    authenticatedUserId: string,
    enrollmentId: string,
    assignmentId: string,
    curriculumVersionId: string,
) {
    return [
        ...teacherMonitoringRootKey(authenticatedUserId),
        'enrollment',
        enrollmentId,
        'assignment',
        assignmentId,
        'curriculum-version',
        curriculumVersionId,
    ] as const;
}

export function teacherLessonProgressKey(
    authenticatedUserId: string,
    enrollmentId: string,
    assignmentId: string,
    curriculumVersionId: string,
) {
    return [
        ...monitoringScopeKey(
            authenticatedUserId,
            enrollmentId,
            assignmentId,
            curriculumVersionId,
        ),
        'progress',
    ] as const;
}

export function teacherAttemptsKey(
    authenticatedUserId: string,
    enrollmentId: string,
    assignmentId: string,
    curriculumVersionId: string,
    kind: TeacherAttemptKind | null,
    status: TeacherAttemptStatus | null,
    page: number,
) {
    return [
        ...monitoringScopeKey(
            authenticatedUserId,
            enrollmentId,
            assignmentId,
            curriculumVersionId,
        ),
        'attempts',
        kind ?? 'all-kinds',
        status ?? 'all-statuses',
        page,
    ] as const;
}

export function teacherAttemptResultKey(
    authenticatedUserId: string,
    enrollmentId: string,
    assignmentId: string,
    curriculumVersionId: string,
    attemptId: string,
) {
    return [
        ...monitoringScopeKey(
            authenticatedUserId,
            enrollmentId,
            assignmentId,
            curriculumVersionId,
        ),
        'attempt-result',
        attemptId,
    ] as const;
}

export function fetchTeacherLessonProgress(
    enrollmentId: string,
    curriculumVersionId: string,
    signal?: AbortSignal,
): Promise<TeacherLessonProgressResponse> {
    return apiRequest({
        method: 'GET',
        url: '/api/teacher/monitoring/enrollments/'
            + enrollmentId
            + '/progress',
        params: {
            curriculum_version_id: curriculumVersionId,
        },
        signal,
    });
}

export function fetchTeacherAttempts(
    enrollmentId: string,
    curriculumVersionId: string,
    kind: TeacherAttemptKind | null,
    status: TeacherAttemptStatus | null,
    page: number,
    signal?: AbortSignal,
): Promise<TeacherAttemptsResponse> {
    return apiRequest({
        method: 'GET',
        url: '/api/teacher/monitoring/enrollments/'
            + enrollmentId
            + '/attempts',
        params: {
            curriculum_version_id: curriculumVersionId,
            kind: kind ?? undefined,
            status: status ?? undefined,
            page,
            per_page: 20,
        },
        signal,
    });
}

export function fetchTeacherAttemptResult(
    enrollmentId: string,
    attemptId: string,
    signal?: AbortSignal,
): Promise<TeacherAttemptResultResponse> {
    return apiRequest({
        method: 'GET',
        url: '/api/teacher/monitoring/enrollments/'
            + enrollmentId
            + '/attempts/'
            + attemptId
            + '/result',
        signal,
    });
}

/**
 * Resolve a detail route through the existing Teacher-scoped enrollment list.
 * The optional assignment_id URL parameter is deliberately not used as
 * authorization authority. Each page is constrained by the authenticated
 * principal on the server, and a missing enrollment remains inaccessible.
 */
export async function resolveTeacherMonitoringEnrollment(
    enrollmentId: string,
    signal?: AbortSignal,
): Promise<TeacherEnrollment | null> {
    let page = 1;
    let lastPage = 1;

    do {
        const response = await apiRequest<TeacherEnrollmentPage>({
            method: 'GET',
            url: '/api/teacher/enrollments',
            params: {
                status: 'active',
                page,
                per_page: 50,
            },
            signal,
        });
        const match = response.items.find((item) => item.id === enrollmentId);

        if (match) {
            return match;
        }

        lastPage = Math.max(1, response.pagination.last_page);
        page += 1;
    } while (page <= lastPage);

    return null;
}

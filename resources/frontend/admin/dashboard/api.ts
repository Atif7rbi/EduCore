import {
    apiRequest,
} from '../../api/client';

export interface AdminDashboardSummary {
    counts: {
        subjects: number;
        curricula: number;
        teacher_owned_curricula: number;
        legacy_ownerless_curricula: number;
        curriculum_versions: number;
        topics: number;
        lessons: number;
        skills: number;
        assessment_items: number;
        practice_activities: number;
        exam_templates: number;
        learners: number;
    };
    readiness: {
        published_curriculum_versions: number;
        published_teacher_owned_curriculum_versions:
            number;
        published_legacy_ownerless_curriculum_versions:
            number;
        published_lessons: number;
        active_practice_activities: number;
        active_exam_templates: number;
    };
    snapshot_at: string;
    operations: {
        teacher_accounts: {
            active: number;
            disabled: number;
            total: number;
        };
        student_accounts: {
            active: number;
            disabled: number;
            total: number;
        };
        teacher_subject_assignments: {
            active: number;
            inactive: number;
            total: number;
        };
        student_enrollments: {
            pending: number;
            active: number;
            inactive: number;
            total: number;
        };
    };
}

export function adminDashboardKey() {
    return [
        'admin',
        'dashboard',
    ] as const;
}

export function fetchAdminDashboard(
    signal?: AbortSignal,
): Promise<AdminDashboardSummary> {
    return apiRequest<AdminDashboardSummary>({
        method: 'GET',
        url: '/api/admin/dashboard',
        signal,
    });
}

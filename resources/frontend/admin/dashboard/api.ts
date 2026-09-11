import {
    apiRequest,
} from '../../api/client';

export interface AdminDashboardSummary {
    counts: {
        subjects: number;
        curricula: number;
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
        published_lessons: number;
        active_practice_activities: number;
        active_exam_templates: number;
    };
}

export function adminDashboardKey() {
    return [
        'admin',
        'dashboard',
    ] as const;
}

export function fetchAdminDashboard(): Promise<AdminDashboardSummary> {
    return apiRequest<AdminDashboardSummary>({
        method: 'GET',
        url: '/api/admin/dashboard',
    });
}

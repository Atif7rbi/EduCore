export type TeacherAssignmentStatus =
    | 'active'
    | 'inactive';

export type CurriculumVersionStatus =
    | 'draft'
    | 'published'
    | 'retired';

export interface TeacherSubjectAssignment {
    id: string;
    teacher_user_id: string;
    subject: {
        id: string;
        code: string;
        name: string;
        status: TeacherAssignmentStatus;
    };
    status: TeacherAssignmentStatus;
    created_at: string | null;
    updated_at: string | null;
}

export interface TeacherCurriculum {
    id: string;
    subject_id: string;
    education_stage_id: string | null;
    teacher_subject_assignment_id: string;
    name: string;
}

export interface TeacherCurriculumVersion {
    id: string;
    curriculum_id: string;
    version_number: number;
    label: string;
    status: CurriculumVersionStatus;
}

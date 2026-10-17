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

export interface TeacherTopic {
    id: string;
    curriculum_version_id: string;
    name: string;
    display_order: number;
}

export interface TeacherSkill {
    id: string;
    name: string;
    description: string | null;
}

export interface TeacherHomeTopic {
    id: string;
    placement_id: string;
    topic_id: string;
    curriculum_version_id: string;
    topic: {
        id: string;
        name: string;
    } | null;
}

export interface TeacherSkillPlacement {
    id: string;
    skill_id: string;
    curriculum_version_id: string;
    skill: {
        id: string;
        name: string;
    } | null;
    home_topics: TeacherHomeTopic[];
}

export type TeacherLessonStatus =
    | 'draft'
    | 'published'
    | 'unpublished';

export interface TeacherLesson {
    id: string;
    curriculum_version_id: string;
    title: string;
    description: string | null;
    status: TeacherLessonStatus;
    display_order: number;
    published_revision_id: string | null;
}

export interface TeacherLessonRevision {
    id: string;
    lesson_id: string;
    curriculum_version_id: string;
    revision_number: number;
    primary_topic_id: string;
    content_payload: Record<string, unknown>;
    content_schema_version: number;
    released_at: string | null;
}

export interface TeacherLessonRevisionSkill {
    id: string;
    lesson_revision_id: string;
    skill_version_placement_id: string;
    curriculum_version_id: string;
}

export type TeacherAssessmentStatus =
    | 'draft'
    | 'published'
    | 'retired';

export interface TeacherAssessmentItem {
    id: string;
    curriculum_version_id: string;
    item_type: string;
    internal_label: string | null;
    status: TeacherAssessmentStatus;
    published_revision_id: string | null;
}

export interface TeacherAssessmentItemRevision {
    id: string;
    assessment_item_id: string;
    curriculum_version_id: string;
    revision_number: number;
    primary_topic_id: string | null;
    difficulty: 'easy' | 'medium' | 'hard';
    content_payload: Record<string, unknown>;
    content_schema_version: number;
    scoring_payload: Record<string, unknown>;
    scoring_schema_version: number;
    released_at: string | null;
}

export interface TeacherAssessmentItemRevisionSkill {
    id: string;
    assessment_item_revision_id: string;
    skill_version_placement_id: string;
    curriculum_version_id: string;
    role: 'primary' | 'supporting';
}

export interface TeacherLessonPayload {
    title: string;
    description: string | null;
    display_order: number;
}

export interface TeacherLessonRevisionPayload {
    revision_number: number;
    primary_topic_id: string;
    content_payload: Record<string, unknown>;
    content_schema_version: number;
}

export interface TeacherAssessmentPayload {
    item_type: string;
    internal_label: string | null;
}

export interface TeacherAssessmentRevisionPayload {
    revision_number: number;
    primary_topic_id: string | null;
    difficulty: 'easy' | 'medium' | 'hard';
    content_payload: Record<string, unknown>;
    content_schema_version: number;
    scoring_payload: Record<string, unknown>;
    scoring_schema_version: number;
}

export type TeacherPracticeStatus =
    | 'archived'
    | 'active';

export interface TeacherPracticeActivity {
    id: string;
    curriculum_version_id: string;
    lesson_id: string | null;
    name: string;
    description: string | null;
    status: TeacherPracticeStatus;
}

export interface TeacherPracticeActivityItem {
    id: string;
    practice_activity_id: string;
    assessment_item_revision_id: string;
    assessment_item_id: string;
    curriculum_version_id: string;
    display_order: number;
}

export interface TeacherPracticePayload {
    lesson_id: string | null;
    name: string;
    description: string | null;
}

export interface TeacherPracticeMembershipPayload {
    assessment_item_revision_ids: string[];
    display_order: number;
}

export type TeacherExamTemplateStatus =
    | 'active'
    | 'archived';

export interface TeacherExamTemplate {
    id: string;
    curriculum_version_id: string;
    name: string;
    description: string | null;
    status: TeacherExamTemplateStatus;
    published_version_id: string | null;
}

export type TeacherExamTemplateVersionStatus =
    | 'draft'
    | 'published'
    | 'retired';

export type TeacherRulesPayload =
    | Record<string, unknown>
    | unknown[];

export interface TeacherExamTemplateVersion {
    id: string;
    exam_template_id: string;
    curriculum_version_id: string;
    version_number: number;
    label: string | null;
    status: TeacherExamTemplateVersionStatus;
    rules_payload: TeacherRulesPayload;
    rules_schema_version: number;
}

export interface TeacherExamTemplateVersionPayload {
    version_number?: number;
    label: string | null;
    rules_payload: TeacherRulesPayload;
    rules_schema_version: number;
}

export interface TeacherExamGenerationPayload {
    generator_version: string;
    seed: string;
    assessment_item_revision_ids: string[];
}

export interface TeacherExamGenerationItem {
    assessment_item_revision_id: string;
    assessment_item_id: string;
}

export interface TeacherExamGeneration {
    id: string;
    exam_template_version_id: string;
    curriculum_version_id: string;
    generated_at: string | null;
    items: TeacherExamGenerationItem[];
}

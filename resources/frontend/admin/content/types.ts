export interface Subject {
    id: string;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export interface Curriculum {
    id: string;
    subject_id: string;
    name: string;
    created_at: string | null;
    updated_at: string | null;
}

export type CurriculumVersionStatus =
    | 'draft'
    | 'published'
    | 'retired';

export interface CurriculumVersion {
    id: string;
    curriculum_id: string;
    version_number: number;
    label: string;
    status: CurriculumVersionStatus;
    created_at?: string | null;
    updated_at?: string | null;
}

export interface Topic {
    id: string;
    curriculum_version_id: string;
    name: string;
    display_order: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface Skill {
    id: string;
    name: string;
    description: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface SkillHomeTopic {
    id: string;
    placement_id: string;
    topic_id: string;
    curriculum_version_id: string;
    topic: {
        id: string;
        name: string;
    } | null;
    created_at: string | null;
}

export interface SkillPlacement {
    id: string;
    skill_id: string;
    curriculum_version_id: string;
    skill: {
        id: string;
        name: string;
    } | null;
    home_topics: SkillHomeTopic[];
    created_at: string | null;
}

export type LessonStatus =
    | 'draft'
    | 'published'
    | 'retired';

export interface Lesson {
    id: string;
    curriculum_version_id: string;
    title: string;
    description: string | null;
    status: LessonStatus;
    display_order: number;
    published_revision_id: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface LessonRevision {
    id: string;
    lesson_id: string;
    curriculum_version_id: string;
    revision_number: number;
    primary_topic_id: string;
    content_payload:
        | unknown[]
        | Record<string, unknown>;
    content_schema_version: number;
    released_at: string | null;
    created_at: string | null;
}

export interface LessonRevisionSkill {
    id: string;
    lesson_revision_id: string;
    skill_version_placement_id: string;
    curriculum_version_id: string;
    skill: {
        id: string;
        name: string;
    } | null;
    created_at: string | null;
}

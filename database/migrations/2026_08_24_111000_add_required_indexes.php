<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'EduCore index migration requires PostgreSQL.'
            );
        }

        DB::unprepared(<<<'SQL'
CREATE INDEX idx_curricula_subject_id
    ON curricula (subject_id);

CREATE INDEX idx_topics_curriculum_version_order
    ON topics (curriculum_version_id, display_order);

CREATE INDEX idx_skill_lineages_target_skill
    ON skill_lineages (target_skill_id);

CREATE INDEX idx_skill_version_placements_curriculum_version
    ON skill_version_placements (curriculum_version_id);

CREATE INDEX idx_skill_home_topics_topic
    ON skill_home_topics (topic_id);

CREATE INDEX idx_lessons_curriculum_version_order
    ON lessons (curriculum_version_id, display_order);

CREATE INDEX idx_lesson_revision_skills_placement
    ON lesson_revision_skills (skill_version_placement_id);

CREATE INDEX idx_assessment_items_curriculum_version
    ON assessment_items (curriculum_version_id);

CREATE INDEX idx_assessment_item_revision_skills_placement
    ON assessment_item_revision_skills (skill_version_placement_id);

CREATE INDEX idx_practice_activities_curriculum_version
    ON practice_activities (curriculum_version_id);

CREATE INDEX idx_practice_activities_lesson
    ON practice_activities (lesson_id);

CREATE INDEX idx_exam_templates_curriculum_version
    ON exam_templates (curriculum_version_id);

CREATE INDEX idx_exam_generations_template_version
    ON exam_generations (exam_template_version_id);

CREATE INDEX idx_attempts_practice_activity
    ON attempts (practice_activity_id);

CREATE INDEX idx_attempts_learner_started_at
    ON attempts (learner_profile_id, started_at);

CREATE INDEX idx_attempt_items_assessment_item
    ON attempt_items (assessment_item_id);

CREATE INDEX idx_attempt_item_classification_skills_skill
    ON attempt_item_classification_skills (skill_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX IF EXISTS idx_attempt_item_classification_skills_skill;
DROP INDEX IF EXISTS idx_attempt_items_assessment_item;
DROP INDEX IF EXISTS idx_attempts_learner_started_at;
DROP INDEX IF EXISTS idx_attempts_practice_activity;
DROP INDEX IF EXISTS idx_exam_generations_template_version;
DROP INDEX IF EXISTS idx_exam_templates_curriculum_version;
DROP INDEX IF EXISTS idx_practice_activities_lesson;
DROP INDEX IF EXISTS idx_practice_activities_curriculum_version;
DROP INDEX IF EXISTS idx_assessment_item_revision_skills_placement;
DROP INDEX IF EXISTS idx_assessment_items_curriculum_version;
DROP INDEX IF EXISTS idx_lesson_revision_skills_placement;
DROP INDEX IF EXISTS idx_lessons_curriculum_version_order;
DROP INDEX IF EXISTS idx_skill_home_topics_topic;
DROP INDEX IF EXISTS idx_skill_version_placements_curriculum_version;
DROP INDEX IF EXISTS idx_skill_lineages_target_skill;
DROP INDEX IF EXISTS idx_topics_curriculum_version_order;
DROP INDEX IF EXISTS idx_curricula_subject_id;
SQL);
    }
};

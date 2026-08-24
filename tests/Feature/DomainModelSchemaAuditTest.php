<?php

namespace Tests\Feature;

use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\AttemptItemClassificationSkill;
use App\Models\AttemptResponse;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EvidenceScope;
use App\Models\ExamGeneration;
use App\Models\ExamGenerationItem;
use App\Models\ExamTemplate;
use App\Models\ExamTemplateVersion;
use App\Models\LearnerProfile;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonRevision;
use App\Models\LessonRevisionSkill;
use App\Models\MaterializedSkillPerformance;
use App\Models\PracticeActivity;
use App\Models\PracticeActivityItem;
use App\Models\RegradeCorrection;
use App\Models\Skill;
use App\Models\SkillHomeTopic;
use App\Models\SkillLineage;
use App\Models\SkillVersionPlacement;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainModelSchemaAuditTest extends TestCase
{
    /**
     * @return array<class-string<Model>, string>
     */
    private function modelTables(): array
    {
        return [
            User::class => 'users',
            LearnerProfile::class => 'learner_profiles',

            Subject::class => 'subjects',
            Curriculum::class => 'curricula',
            CurriculumVersion::class => 'curriculum_versions',

            Topic::class => 'topics',
            Skill::class => 'skills',
            SkillLineage::class => 'skill_lineages',
            SkillVersionPlacement::class => 'skill_version_placements',
            SkillHomeTopic::class => 'skill_home_topics',

            Lesson::class => 'lessons',
            LessonRevision::class => 'lesson_revisions',
            LessonRevisionSkill::class => 'lesson_revision_skills',
            LessonProgress::class => 'lesson_progresses',

            AssessmentItem::class => 'assessment_items',
            AssessmentItemRevision::class => 'assessment_item_revisions',
            AssessmentItemRevisionSkill::class => 'assessment_item_revision_skills',

            PracticeActivity::class => 'practice_activities',
            PracticeActivityItem::class => 'practice_activity_items',

            ExamTemplate::class => 'exam_templates',
            ExamTemplateVersion::class => 'exam_template_versions',
            ExamGeneration::class => 'exam_generations',
            ExamGenerationItem::class => 'exam_generation_items',

            Attempt::class => 'attempts',
            AttemptItem::class => 'attempt_items',
            AttemptItemClassificationSkill::class => 'attempt_item_classification_skills',
            AttemptResponse::class => 'attempt_responses',
            RegradeCorrection::class => 'regrade_corrections',

            EvidenceScope::class => 'evidence_scopes',
            MaterializedSkillPerformance::class => 'materialized_skill_performances',
        ];
    }

    public function test_all_thirty_domain_tables_have_explicit_models(): void
    {
        $modelTables = $this->modelTables();

        $this->assertCount(30, $modelTables);
        $this->assertCount(30, array_unique(array_values($modelTables)));

        foreach ($modelTables as $modelClass => $table) {
            $model = new $modelClass();

            $this->assertSame(
                $table,
                $model->getTable(),
                "{$modelClass} maps to the wrong table."
            );

            $this->assertTrue(
                Schema::hasTable($table),
                "Missing table {$table}."
            );
        }
    }

    public function test_all_domain_models_use_uuid_ids(): void
    {
        foreach ($this->modelTables() as $modelClass => $table) {
            $model = new $modelClass();

            $this->assertSame(
                'id',
                $model->getKeyName(),
                "{$table} does not use id as its model primary key."
            );

            $this->assertFalse(
                $model->getIncrementing(),
                "{$table} incorrectly expects an incrementing key."
            );

            $this->assertSame(
                'string',
                $model->getKeyType(),
                "{$table} does not expose UUID keys as strings."
            );
        }
    }

    public function test_fillable_and_cast_columns_exist_in_physical_schema(): void
    {
        foreach ($this->modelTables() as $modelClass => $table) {
            $model = new $modelClass();
            $columns = Schema::getColumnListing($table);

            foreach ($model->getFillable() as $column) {
                $this->assertContains(
                    $column,
                    $columns,
                    "{$modelClass} fillable column {$column} does not exist."
                );
            }

            foreach (array_keys($model->getCasts()) as $column) {
                $this->assertContains(
                    $column,
                    $columns,
                    "{$modelClass} cast column {$column} does not exist."
                );
            }
        }
    }

    public function test_model_timestamp_expectations_match_physical_schema(): void
    {
        foreach ($this->modelTables() as $modelClass => $table) {
            $model = new $modelClass();
            $columns = Schema::getColumnListing($table);

            $this->assertTrue(
                $model->usesTimestamps(),
                "{$modelClass} should manage created_at."
            );

            $this->assertContains(
                'created_at',
                $columns,
                "{$table} is missing created_at."
            );

            $this->assertSame(
                'created_at',
                $model->getCreatedAtColumn(),
                "{$modelClass} has the wrong created-at mapping."
            );

            if (in_array('updated_at', $columns, true)) {
                $this->assertSame(
                    'updated_at',
                    $model->getUpdatedAtColumn(),
                    "{$modelClass} should manage updated_at."
                );
            } else {
                $this->assertNull(
                    $model->getUpdatedAtColumn(),
                    "{$modelClass} expects updated_at but {$table} has none."
                );
            }
        }
    }
}

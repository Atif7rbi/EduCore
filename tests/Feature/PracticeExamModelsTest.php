<?php

namespace Tests\Feature;

use App\Models\ExamGeneration;
use App\Models\ExamGenerationItem;
use App\Models\ExamTemplate;
use App\Models\ExamTemplateVersion;
use App\Models\PracticeActivity;
use App\Models\PracticeActivityItem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class PracticeExamModelsTest extends TestCase
{
    public function test_practice_and_exam_models_use_uuid_primary_keys(): void
    {
        $models = [
            new PracticeActivity(),
            new PracticeActivityItem(),
            new ExamTemplate(),
            new ExamTemplateVersion(),
            new ExamGeneration(),
            new ExamGenerationItem(),
        ];

        foreach ($models as $model) {
            $this->assertFalse($model->getIncrementing());
            $this->assertSame('string', $model->getKeyType());
        }
    }

    public function test_practice_relationship_mapping(): void
    {
        $version = (new PracticeActivity())->curriculumVersion();
        $this->assertInstanceOf(BelongsTo::class, $version);
        $this->assertSame(
            'curriculum_version_id',
            $version->getForeignKeyName()
        );

        $lesson = (new PracticeActivity())->lesson();
        $this->assertInstanceOf(BelongsTo::class, $lesson);
        $this->assertSame('lesson_id', $lesson->getForeignKeyName());

        $items = (new PracticeActivity())->items();
        $this->assertInstanceOf(HasMany::class, $items);
        $this->assertSame(
            'practice_activity_id',
            $items->getForeignKeyName()
        );

        $activity = (new PracticeActivityItem())->practiceActivity();
        $this->assertInstanceOf(BelongsTo::class, $activity);
        $this->assertSame(
            'practice_activity_id',
            $activity->getForeignKeyName()
        );

        $revision = (new PracticeActivityItem())
            ->assessmentItemRevision();

        $this->assertInstanceOf(BelongsTo::class, $revision);
        $this->assertSame(
            'assessment_item_revision_id',
            $revision->getForeignKeyName()
        );

        $item = (new PracticeActivityItem())->assessmentItem();
        $this->assertInstanceOf(BelongsTo::class, $item);
        $this->assertSame(
            'assessment_item_id',
            $item->getForeignKeyName()
        );
    }

    public function test_exam_template_relationship_mapping(): void
    {
        $version = (new ExamTemplate())->curriculumVersion();
        $this->assertInstanceOf(BelongsTo::class, $version);
        $this->assertSame(
            'curriculum_version_id',
            $version->getForeignKeyName()
        );

        $versions = (new ExamTemplate())->versions();
        $this->assertInstanceOf(HasMany::class, $versions);
        $this->assertSame(
            'exam_template_id',
            $versions->getForeignKeyName()
        );

        $published = (new ExamTemplate())->publishedVersion();
        $this->assertInstanceOf(BelongsTo::class, $published);
        $this->assertSame(
            'published_version_id',
            $published->getForeignKeyName()
        );

        $template = (new ExamTemplateVersion())->examTemplate();
        $this->assertInstanceOf(BelongsTo::class, $template);
        $this->assertSame(
            'exam_template_id',
            $template->getForeignKeyName()
        );

        $generations = (new ExamTemplateVersion())->generations();
        $this->assertInstanceOf(HasMany::class, $generations);
        $this->assertSame(
            'exam_template_version_id',
            $generations->getForeignKeyName()
        );
    }

    public function test_exam_generation_relationship_mapping(): void
    {
        $templateVersion = (new ExamGeneration())
            ->examTemplateVersion();

        $this->assertInstanceOf(BelongsTo::class, $templateVersion);
        $this->assertSame(
            'exam_template_version_id',
            $templateVersion->getForeignKeyName()
        );

        $items = (new ExamGeneration())->items();
        $this->assertInstanceOf(HasMany::class, $items);
        $this->assertSame(
            'exam_generation_id',
            $items->getForeignKeyName()
        );

        $generation = (new ExamGenerationItem())
            ->examGeneration();

        $this->assertInstanceOf(BelongsTo::class, $generation);
        $this->assertSame(
            'exam_generation_id',
            $generation->getForeignKeyName()
        );

        $revision = (new ExamGenerationItem())
            ->assessmentItemRevision();

        $this->assertInstanceOf(BelongsTo::class, $revision);
        $this->assertSame(
            'assessment_item_revision_id',
            $revision->getForeignKeyName()
        );

        $item = (new ExamGenerationItem())->assessmentItem();
        $this->assertInstanceOf(BelongsTo::class, $item);
        $this->assertSame(
            'assessment_item_id',
            $item->getForeignKeyName()
        );
    }

    public function test_exam_and_practice_casts_and_timestamp_shape(): void
    {
        $practiceItem = new PracticeActivityItem();

        $this->assertSame(
            'integer',
            $practiceItem->getCasts()['display_order']
        );
        $this->assertNull($practiceItem->getUpdatedAtColumn());

        $templateVersion = new ExamTemplateVersion();

        $this->assertSame(
            'integer',
            $templateVersion->getCasts()['version_number']
        );
        $this->assertSame(
            'array',
            $templateVersion->getCasts()['rules_payload']
        );
        $this->assertSame(
            'integer',
            $templateVersion->getCasts()['rules_schema_version']
        );

        $generation = new ExamGeneration();

        $this->assertSame(
            'array',
            $generation->getCasts()['rules_snapshot']
        );
        $this->assertSame(
            'integer',
            $generation->getCasts()['rules_schema_version']
        );
        $this->assertSame(
            'immutable_datetime',
            $generation->getCasts()['generated_at']
        );
        $this->assertNull($generation->getUpdatedAtColumn());

        $generationItem = new ExamGenerationItem();

        $this->assertSame(
            'integer',
            $generationItem->getCasts()['selection_position']
        );
        $this->assertNull($generationItem->getUpdatedAtColumn());
    }
}

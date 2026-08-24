<?php

namespace Tests\Feature;

use App\Models\AssessmentItem;
use App\Models\AssessmentItemRevision;
use App\Models\AssessmentItemRevisionSkill;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LessonRevision;
use App\Models\LessonRevisionSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class LearningAssessmentModelsTest extends TestCase
{
    public function test_learning_and_assessment_models_use_uuid_primary_keys(): void
    {
        $models = [
            new Lesson(),
            new LessonRevision(),
            new LessonRevisionSkill(),
            new LessonProgress(),
            new AssessmentItem(),
            new AssessmentItemRevision(),
            new AssessmentItemRevisionSkill(),
        ];

        foreach ($models as $model) {
            $this->assertFalse($model->getIncrementing());
            $this->assertSame('string', $model->getKeyType());
        }
    }

    public function test_lesson_relationship_mapping(): void
    {
        $version = (new Lesson())->curriculumVersion();
        $this->assertInstanceOf(BelongsTo::class, $version);
        $this->assertSame('curriculum_version_id', $version->getForeignKeyName());

        $revisions = (new Lesson())->revisions();
        $this->assertInstanceOf(HasMany::class, $revisions);
        $this->assertSame('lesson_id', $revisions->getForeignKeyName());

        $published = (new Lesson())->publishedRevision();
        $this->assertInstanceOf(BelongsTo::class, $published);
        $this->assertSame('published_revision_id', $published->getForeignKeyName());

        $lesson = (new LessonRevision())->lesson();
        $this->assertInstanceOf(BelongsTo::class, $lesson);
        $this->assertSame('lesson_id', $lesson->getForeignKeyName());

        $topic = (new LessonRevision())->primaryTopic();
        $this->assertInstanceOf(BelongsTo::class, $topic);
        $this->assertSame('primary_topic_id', $topic->getForeignKeyName());

        $skills = (new LessonRevision())->skills();
        $this->assertInstanceOf(HasMany::class, $skills);
        $this->assertSame('lesson_revision_id', $skills->getForeignKeyName());

        $progresses = (new LessonRevision())->progresses();
        $this->assertInstanceOf(HasMany::class, $progresses);
        $this->assertSame('lesson_revision_id', $progresses->getForeignKeyName());
    }

    public function test_lesson_revision_skill_and_progress_mapping(): void
    {
        $revision = (new LessonRevisionSkill())->lessonRevision();
        $this->assertInstanceOf(BelongsTo::class, $revision);
        $this->assertSame('lesson_revision_id', $revision->getForeignKeyName());

        $placement = (new LessonRevisionSkill())->skillVersionPlacement();
        $this->assertInstanceOf(BelongsTo::class, $placement);
        $this->assertSame(
            'skill_version_placement_id',
            $placement->getForeignKeyName()
        );

        $learner = (new LessonProgress())->learnerProfile();
        $this->assertInstanceOf(BelongsTo::class, $learner);
        $this->assertSame('learner_profile_id', $learner->getForeignKeyName());

        $progressRevision = (new LessonProgress())->lessonRevision();
        $this->assertInstanceOf(BelongsTo::class, $progressRevision);
        $this->assertSame(
            'lesson_revision_id',
            $progressRevision->getForeignKeyName()
        );
    }

    public function test_assessment_relationship_mapping(): void
    {
        $version = (new AssessmentItem())->curriculumVersion();
        $this->assertInstanceOf(BelongsTo::class, $version);
        $this->assertSame('curriculum_version_id', $version->getForeignKeyName());

        $revisions = (new AssessmentItem())->revisions();
        $this->assertInstanceOf(HasMany::class, $revisions);
        $this->assertSame('assessment_item_id', $revisions->getForeignKeyName());

        $published = (new AssessmentItem())->publishedRevision();
        $this->assertInstanceOf(BelongsTo::class, $published);
        $this->assertSame('published_revision_id', $published->getForeignKeyName());

        $item = (new AssessmentItemRevision())->assessmentItem();
        $this->assertInstanceOf(BelongsTo::class, $item);
        $this->assertSame('assessment_item_id', $item->getForeignKeyName());

        $topic = (new AssessmentItemRevision())->primaryTopic();
        $this->assertInstanceOf(BelongsTo::class, $topic);
        $this->assertSame('primary_topic_id', $topic->getForeignKeyName());

        $skills = (new AssessmentItemRevision())->skills();
        $this->assertInstanceOf(HasMany::class, $skills);
        $this->assertSame(
            'assessment_item_revision_id',
            $skills->getForeignKeyName()
        );
    }

    public function test_assessment_revision_skill_mapping(): void
    {
        $revision = (new AssessmentItemRevisionSkill())
            ->assessmentItemRevision();

        $this->assertInstanceOf(BelongsTo::class, $revision);
        $this->assertSame(
            'assessment_item_revision_id',
            $revision->getForeignKeyName()
        );

        $placement = (new AssessmentItemRevisionSkill())
            ->skillVersionPlacement();

        $this->assertInstanceOf(BelongsTo::class, $placement);
        $this->assertSame(
            'skill_version_placement_id',
            $placement->getForeignKeyName()
        );

        $version = (new AssessmentItemRevisionSkill())
            ->curriculumVersion();

        $this->assertInstanceOf(BelongsTo::class, $version);
        $this->assertSame(
            'curriculum_version_id',
            $version->getForeignKeyName()
        );
    }

    public function test_revision_models_have_correct_casts_and_timestamp_shape(): void
    {
        $lessonRevision = new LessonRevision();

        $this->assertSame('array', $lessonRevision->getCasts()['content_payload']);
        $this->assertSame(
            'immutable_datetime',
            $lessonRevision->getCasts()['released_at']
        );
        $this->assertNull($lessonRevision->getUpdatedAtColumn());

        $assessmentRevision = new AssessmentItemRevision();

        $this->assertSame(
            'array',
            $assessmentRevision->getCasts()['content_payload']
        );
        $this->assertSame(
            'array',
            $assessmentRevision->getCasts()['scoring_payload']
        );
        $this->assertSame(
            'immutable_datetime',
            $assessmentRevision->getCasts()['released_at']
        );
        $this->assertNull($assessmentRevision->getUpdatedAtColumn());

        $this->assertNull(
            (new LessonRevisionSkill())->getUpdatedAtColumn()
        );

        $this->assertNull(
            (new AssessmentItemRevisionSkill())->getUpdatedAtColumn()
        );

        $progressCasts = (new LessonProgress())->getCasts();

        $this->assertSame(
            'immutable_datetime',
            $progressCasts['started_at']
        );

        $this->assertSame(
            'immutable_datetime',
            $progressCasts['completed_at']
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\AttemptItemClassificationSkill;
use App\Models\AttemptResponse;
use App\Models\EvidenceScope;
use App\Models\MaterializedSkillPerformance;
use App\Models\RegradeCorrection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\TestCase;

class AttemptAnalyticsModelsTest extends TestCase
{
    public function test_attempt_and_analytics_models_use_uuid_primary_keys(): void
    {
        $models = [
            new Attempt(),
            new AttemptItem(),
            new AttemptItemClassificationSkill(),
            new AttemptResponse(),
            new RegradeCorrection(),
            new EvidenceScope(),
            new MaterializedSkillPerformance(),
        ];

        foreach ($models as $model) {
            $this->assertFalse($model->getIncrementing());
            $this->assertSame('string', $model->getKeyType());
        }
    }

    public function test_attempt_relationship_mapping(): void
    {
        $learner = (new Attempt())->learnerProfile();
        $this->assertInstanceOf(BelongsTo::class, $learner);
        $this->assertSame(
            'learner_profile_id',
            $learner->getForeignKeyName()
        );

        $generation = (new Attempt())->examGeneration();
        $this->assertInstanceOf(BelongsTo::class, $generation);
        $this->assertSame(
            'exam_generation_id',
            $generation->getForeignKeyName()
        );

        $practice = (new Attempt())->practiceActivity();
        $this->assertInstanceOf(BelongsTo::class, $practice);
        $this->assertSame(
            'practice_activity_id',
            $practice->getForeignKeyName()
        );

        $version = (new Attempt())->curriculumVersion();
        $this->assertInstanceOf(BelongsTo::class, $version);
        $this->assertSame(
            'curriculum_version_id',
            $version->getForeignKeyName()
        );

        $items = (new Attempt())->items();
        $this->assertInstanceOf(HasMany::class, $items);
        $this->assertSame(
            'attempt_id',
            $items->getForeignKeyName()
        );
    }

    public function test_attempt_item_relationship_mapping(): void
    {
        $attempt = (new AttemptItem())->attempt();
        $this->assertInstanceOf(BelongsTo::class, $attempt);
        $this->assertSame('attempt_id', $attempt->getForeignKeyName());

        $revision = (new AttemptItem())->assessmentItemRevision();
        $this->assertInstanceOf(BelongsTo::class, $revision);
        $this->assertSame(
            'assessment_item_revision_id',
            $revision->getForeignKeyName()
        );

        $item = (new AttemptItem())->assessmentItem();
        $this->assertInstanceOf(BelongsTo::class, $item);
        $this->assertSame(
            'assessment_item_id',
            $item->getForeignKeyName()
        );

        $generation = (new AttemptItem())->examGeneration();
        $this->assertInstanceOf(BelongsTo::class, $generation);
        $this->assertSame(
            'exam_generation_id',
            $generation->getForeignKeyName()
        );

        $generationItem = (new AttemptItem())->examGenerationItem();
        $this->assertInstanceOf(BelongsTo::class, $generationItem);
        $this->assertSame(
            'exam_generation_item_id',
            $generationItem->getForeignKeyName()
        );

        $topic = (new AttemptItem())->primaryTopic();
        $this->assertInstanceOf(BelongsTo::class, $topic);
        $this->assertSame(
            'primary_topic_id',
            $topic->getForeignKeyName()
        );

        $skills = (new AttemptItem())->classificationSkills();
        $this->assertInstanceOf(HasMany::class, $skills);
        $this->assertSame(
            'attempt_item_id',
            $skills->getForeignKeyName()
        );

        $response = (new AttemptItem())->response();
        $this->assertInstanceOf(HasOne::class, $response);
        $this->assertSame(
            'attempt_item_id',
            $response->getForeignKeyName()
        );
    }

    public function test_response_regrade_and_classification_mapping(): void
    {
        $classificationItem = (new AttemptItemClassificationSkill())
            ->attemptItem();

        $this->assertInstanceOf(
            BelongsTo::class,
            $classificationItem
        );

        $this->assertSame(
            'attempt_item_id',
            $classificationItem->getForeignKeyName()
        );

        $skill = (new AttemptItemClassificationSkill())->skill();
        $this->assertInstanceOf(BelongsTo::class, $skill);
        $this->assertSame('skill_id', $skill->getForeignKeyName());

        $responseItem = (new AttemptResponse())->attemptItem();
        $this->assertInstanceOf(BelongsTo::class, $responseItem);
        $this->assertSame(
            'attempt_item_id',
            $responseItem->getForeignKeyName()
        );

        $corrections = (new AttemptResponse())->regradeCorrections();
        $this->assertInstanceOf(HasMany::class, $corrections);
        $this->assertSame(
            'attempt_response_id',
            $corrections->getForeignKeyName()
        );

        $response = (new RegradeCorrection())->attemptResponse();
        $this->assertInstanceOf(BelongsTo::class, $response);
        $this->assertSame(
            'attempt_response_id',
            $response->getForeignKeyName()
        );
    }

    public function test_analytics_relationship_mapping(): void
    {
        $performances = (new EvidenceScope())
            ->materializedSkillPerformances();

        $this->assertInstanceOf(HasMany::class, $performances);
        $this->assertSame(
            'evidence_scope_id',
            $performances->getForeignKeyName()
        );

        $learner = (new MaterializedSkillPerformance())
            ->learnerProfile();

        $this->assertInstanceOf(BelongsTo::class, $learner);
        $this->assertSame(
            'learner_profile_id',
            $learner->getForeignKeyName()
        );

        $skill = (new MaterializedSkillPerformance())->skill();
        $this->assertInstanceOf(BelongsTo::class, $skill);
        $this->assertSame(
            'skill_id',
            $skill->getForeignKeyName()
        );

        $scope = (new MaterializedSkillPerformance())
            ->evidenceScope();

        $this->assertInstanceOf(BelongsTo::class, $scope);
        $this->assertSame(
            'evidence_scope_id',
            $scope->getForeignKeyName()
        );
    }

    public function test_attempt_snapshot_casts_and_timestamp_shape(): void
    {
        $attempt = new Attempt();

        $this->assertSame(
            'immutable_datetime',
            $attempt->getCasts()['started_at']
        );

        $this->assertSame(
            'immutable_datetime',
            $attempt->getCasts()['finalized_at']
        );

        $item = new AttemptItem();
        $casts = $item->getCasts();

        $this->assertSame('integer', $casts['presentation_position']);
        $this->assertSame('array', $casts['presented_payload']);
        $this->assertSame('integer', $casts['presented_schema_version']);
        $this->assertSame('array', $casts['scoring_snapshot']);
        $this->assertSame('integer', $casts['scoring_schema_version']);
        $this->assertNull($item->getUpdatedAtColumn());

        $response = new AttemptResponse();
        $responseCasts = $response->getCasts();

        $this->assertSame('array', $responseCasts['response_payload']);
        $this->assertSame('integer', $responseCasts['answer_change_count']);
        $this->assertSame('integer', $responseCasts['time_spent_ms']);
        $this->assertSame('boolean', $responseCasts['original_is_correct']);

        $correction = new RegradeCorrection();

        $this->assertSame(
            'integer',
            $correction->getCasts()['correction_number']
        );

        $this->assertSame(
            'boolean',
            $correction->getCasts()['corrected_is_correct']
        );

        $this->assertSame(
            'immutable_datetime',
            $correction->getCasts()['corrected_at']
        );

        $this->assertNull($correction->getUpdatedAtColumn());
        $this->assertNull(
            (new AttemptItemClassificationSkill())->getUpdatedAtColumn()
        );
    }

    public function test_analytics_casts_and_timestamp_shape(): void
    {
        $scope = new EvidenceScope();

        $this->assertSame(
            'array',
            $scope->getCasts()['definition_payload']
        );

        $this->assertSame(
            'integer',
            $scope->getCasts()['definition_schema_version']
        );

        $performance = new MaterializedSkillPerformance();
        $casts = $performance->getCasts();

        $this->assertSame(
            'integer',
            $casts['single_primary_correct_count']
        );

        $this->assertSame(
            'integer',
            $casts['single_primary_answered_count']
        );

        $this->assertSame(
            'integer',
            $casts['supporting_positive_count']
        );

        $this->assertSame(
            'integer',
            $casts['supporting_exposure_count']
        );

        $this->assertSame(
            'immutable_datetime',
            $casts['last_rebuilt_at']
        );

        $this->assertNull($performance->getUpdatedAtColumn());
    }
}

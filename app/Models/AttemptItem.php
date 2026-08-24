<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttemptItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'attempt_id',
        'assessment_item_revision_id',
        'assessment_item_id',
        'curriculum_version_id',
        'exam_generation_id',
        'exam_generation_item_id',
        'presentation_position',
        'presented_payload',
        'presented_schema_version',
        'scoring_snapshot',
        'scoring_schema_version',
        'primary_topic_id',
    ];

    protected function casts(): array
    {
        return [
            'presentation_position' => 'integer',
            'presented_payload' => 'array',
            'presented_schema_version' => 'integer',
            'scoring_snapshot' => 'array',
            'scoring_schema_version' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function assessmentItemRevision(): BelongsTo
    {
        return $this->belongsTo(AssessmentItemRevision::class);
    }

    public function assessmentItem(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function examGeneration(): BelongsTo
    {
        return $this->belongsTo(ExamGeneration::class);
    }

    public function examGenerationItem(): BelongsTo
    {
        return $this->belongsTo(ExamGenerationItem::class);
    }

    public function primaryTopic(): BelongsTo
    {
        return $this->belongsTo(
            Topic::class,
            'primary_topic_id'
        );
    }

    public function classificationSkills(): HasMany
    {
        return $this->hasMany(
            AttemptItemClassificationSkill::class
        );
    }

    public function response(): HasOne
    {
        return $this->hasOne(AttemptResponse::class);
    }
}

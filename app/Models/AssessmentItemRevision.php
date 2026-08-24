<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentItemRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'assessment_item_id',
        'curriculum_version_id',
        'revision_number',
        'primary_topic_id',
        'difficulty',
        'content_payload',
        'content_schema_version',
        'scoring_payload',
        'scoring_schema_version',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'content_payload' => 'array',
            'content_schema_version' => 'integer',
            'scoring_payload' => 'array',
            'scoring_schema_version' => 'integer',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function assessmentItem(): BelongsTo
    {
        return $this->belongsTo(
            AssessmentItem::class
        );
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(
            CurriculumVersion::class
        );
    }

    public function primaryTopic(): BelongsTo
    {
        return $this->belongsTo(
            Topic::class,
            'primary_topic_id'
        );
    }

    public function skills(): HasMany
    {
        return $this->hasMany(
            AssessmentItemRevisionSkill::class
        );
    }
}

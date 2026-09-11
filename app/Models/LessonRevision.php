<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LessonRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'lesson_id',
        'curriculum_version_id',
        'revision_number',
        'primary_topic_id',
        'content_payload',
        'content_schema_version',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'content_payload' => 'array',
            'content_schema_version' => 'integer',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
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
            LessonRevisionSkill::class
        );
    }

    public function progresses(): HasMany
    {
        return $this->hasMany(
            LessonProgress::class
        );
    }
}

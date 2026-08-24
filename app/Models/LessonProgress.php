<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    use HasUuids;

    protected $fillable = [
        'learner_profile_id',
        'lesson_revision_id',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(
            LearnerProfile::class
        );
    }

    public function lessonRevision(): BelongsTo
    {
        return $this->belongsTo(
            LessonRevision::class
        );
    }
}

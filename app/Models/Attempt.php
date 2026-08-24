<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'learner_profile_id',
        'exam_generation_id',
        'practice_activity_id',
        'curriculum_version_id',
        'status',
        'started_at',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function examGeneration(): BelongsTo
    {
        return $this->belongsTo(ExamGeneration::class);
    }

    public function practiceActivity(): BelongsTo
    {
        return $this->belongsTo(PracticeActivity::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AttemptItem::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PracticeActivity extends Model
{
    use HasUuids;

    protected $fillable = [
        'curriculum_version_id',
        'lesson_id',
        'name',
        'description',
        'status',
    ];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PracticeActivityItem::class);
    }
}

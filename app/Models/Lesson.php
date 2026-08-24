<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasUuids;

    protected $fillable = [
        'curriculum_version_id',
        'title',
        'description',
        'status',
        'display_order',
        'published_revision_id',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(LessonRevision::class);
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(
            LessonRevision::class,
            'published_revision_id'
        );
    }

    public function practiceActivities(): HasMany
    {
        return $this->hasMany(PracticeActivity::class);
    }
}

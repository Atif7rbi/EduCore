<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'curriculum_version_id',
        'name',
        'description',
        'status',
        'published_version_id',
    ];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ExamTemplateVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(
            ExamTemplateVersion::class,
            'published_version_id'
        );
    }
}

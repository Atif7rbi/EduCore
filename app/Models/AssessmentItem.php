<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssessmentItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'curriculum_version_id',
        'item_type',
        'internal_label',
        'status',
        'published_revision_id',
    ];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(
            CurriculumVersion::class
        );
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(
            AssessmentItemRevision::class
        );
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(
            AssessmentItemRevision::class,
            'published_revision_id'
        );
    }
}

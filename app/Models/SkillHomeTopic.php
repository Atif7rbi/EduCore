<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillHomeTopic extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'placement_id',
        'topic_id',
        'curriculum_version_id',
    ];

    public function placement(): BelongsTo
    {
        return $this->belongsTo(
            SkillVersionPlacement::class,
            'placement_id'
        );
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkillVersionPlacement extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'skill_id',
        'curriculum_version_id',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function homeTopics(): HasMany
    {
        return $this->hasMany(
            SkillHomeTopic::class,
            'placement_id'
        );
    }
}

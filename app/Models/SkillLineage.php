<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillLineage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'source_skill_id',
        'target_skill_id',
        'lineage_type',
    ];

    public function sourceSkill(): BelongsTo
    {
        return $this->belongsTo(
            Skill::class,
            'source_skill_id'
        );
    }

    public function targetSkill(): BelongsTo
    {
        return $this->belongsTo(
            Skill::class,
            'target_skill_id'
        );
    }
}

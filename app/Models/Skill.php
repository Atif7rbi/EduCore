<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'description',
    ];

    public function placements(): HasMany
    {
        return $this->hasMany(SkillVersionPlacement::class);
    }

    public function outgoingLineages(): HasMany
    {
        return $this->hasMany(
            SkillLineage::class,
            'source_skill_id'
        );
    }

    public function incomingLineages(): HasMany
    {
        return $this->hasMany(
            SkillLineage::class,
            'target_skill_id'
        );
    }
}

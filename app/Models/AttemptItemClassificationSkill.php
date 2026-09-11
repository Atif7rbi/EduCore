<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptItemClassificationSkill extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'attempt_item_id',
        'skill_id',
        'role',
    ];

    public function attemptItem(): BelongsTo
    {
        return $this->belongsTo(AttemptItem::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}

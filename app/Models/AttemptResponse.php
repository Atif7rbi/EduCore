<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttemptResponse extends Model
{
    use HasUuids;

    protected $fillable = [
        'attempt_item_id',
        'response_payload',
        'answer_change_count',
        'time_spent_ms',
        'original_is_correct',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'answer_change_count' => 'integer',
            'time_spent_ms' => 'integer',
            'original_is_correct' => 'boolean',
        ];
    }

    public function attemptItem(): BelongsTo
    {
        return $this->belongsTo(AttemptItem::class);
    }

    public function regradeCorrections(): HasMany
    {
        return $this->hasMany(RegradeCorrection::class);
    }
}

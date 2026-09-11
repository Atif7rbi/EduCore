<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegradeCorrection extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'attempt_response_id',
        'correction_number',
        'corrected_is_correct',
        'reason',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'correction_number' => 'integer',
            'corrected_is_correct' => 'boolean',
            'corrected_at' => 'immutable_datetime',
        ];
    }

    public function attemptResponse(): BelongsTo
    {
        return $this->belongsTo(AttemptResponse::class);
    }
}

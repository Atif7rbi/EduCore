<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentEnrollmentTransition extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'enrollment_id',
        'from_status',
        'to_status',
        'actor_user_id',
        'operation_id',
        'outcome',
        'reason',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(
            StudentEnrollment::class,
            'enrollment_id',
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id',
        );
    }
}

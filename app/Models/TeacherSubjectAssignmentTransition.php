<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherSubjectAssignmentTransition extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'assignment_id',
        'from_status',
        'to_status',
        'actor_user_id',
        'operation_id',
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

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(
            TeacherSubjectAssignment::class,
            'assignment_id',
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

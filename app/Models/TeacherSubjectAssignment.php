<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherSubjectAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'teacher_id',
        'subject_id',
        'status',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'teacher_id',
        );
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(
            Curriculum::class,
            'teacher_subject_assignment_id',
        );
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(
            TeacherSubjectAssignmentTransition::class,
            'assignment_id',
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

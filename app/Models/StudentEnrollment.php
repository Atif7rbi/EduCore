<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentEnrollment extends Model
{
    use HasUuids;

    protected $fillable = [
        'learner_profile_id',
        'teacher_subject_assignment_id',
        'status',
    ];

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function teacherSubjectAssignment(): BelongsTo
    {
        return $this->belongsTo(
            TeacherSubjectAssignment::class,
        );
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(
            StudentEnrollmentTransition::class,
            'enrollment_id',
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }
}

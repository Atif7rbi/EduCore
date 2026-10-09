<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Curriculum extends Model
{
    use HasUuids;

    protected $fillable = [
        'subject_id',
        'education_stage_id',
        'teacher_subject_assignment_id',
        'name',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function educationStage(): BelongsTo
    {
        return $this->belongsTo(EducationStage::class);
    }

    public function teacherSubjectAssignment(): BelongsTo
    {
        return $this->belongsTo(
            TeacherSubjectAssignment::class,
            'teacher_subject_assignment_id',
        );
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CurriculumVersion::class);
    }
}

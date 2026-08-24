<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonRevisionSkill extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'lesson_revision_id',
        'skill_version_placement_id',
        'curriculum_version_id',
    ];

    public function lessonRevision(): BelongsTo
    {
        return $this->belongsTo(
            LessonRevision::class
        );
    }

    public function skillVersionPlacement(): BelongsTo
    {
        return $this->belongsTo(
            SkillVersionPlacement::class
        );
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(
            CurriculumVersion::class
        );
    }
}

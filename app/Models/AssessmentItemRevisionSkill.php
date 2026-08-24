<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentItemRevisionSkill extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'assessment_item_revision_id',
        'skill_version_placement_id',
        'curriculum_version_id',
        'role',
    ];

    public function assessmentItemRevision(): BelongsTo
    {
        return $this->belongsTo(
            AssessmentItemRevision::class
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeActivityItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'practice_activity_id',
        'assessment_item_revision_id',
        'assessment_item_id',
        'curriculum_version_id',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
        ];
    }

    public function practiceActivity(): BelongsTo
    {
        return $this->belongsTo(PracticeActivity::class);
    }

    public function assessmentItemRevision(): BelongsTo
    {
        return $this->belongsTo(AssessmentItemRevision::class);
    }

    public function assessmentItem(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }
}

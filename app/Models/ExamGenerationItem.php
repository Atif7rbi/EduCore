<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamGenerationItem extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'exam_generation_id',
        'assessment_item_revision_id',
        'assessment_item_id',
        'curriculum_version_id',
        'selection_position',
    ];

    protected function casts(): array
    {
        return [
            'selection_position' => 'integer',
        ];
    }

    public function examGeneration(): BelongsTo
    {
        return $this->belongsTo(ExamGeneration::class);
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

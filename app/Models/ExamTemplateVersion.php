<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamTemplateVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'exam_template_id',
        'curriculum_version_id',
        'version_number',
        'label',
        'status',
        'rules_payload',
        'rules_schema_version',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'rules_payload' => 'array',
            'rules_schema_version' => 'integer',
        ];
    }

    public function examTemplate(): BelongsTo
    {
        return $this->belongsTo(ExamTemplate::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(
            ExamGeneration::class,
            'exam_template_version_id'
        );
    }
}

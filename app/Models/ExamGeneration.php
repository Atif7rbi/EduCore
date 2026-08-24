<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamGeneration extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'exam_template_version_id',
        'curriculum_version_id',
        'rules_snapshot',
        'rules_schema_version',
        'generator_version',
        'seed',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'rules_snapshot' => 'array',
            'rules_schema_version' => 'integer',
            'generated_at' => 'immutable_datetime',
        ];
    }

    public function examTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(ExamTemplateVersion::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExamGenerationItem::class);
    }
}

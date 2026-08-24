<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterializedSkillPerformance extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'learner_profile_id',
        'skill_id',
        'evidence_scope_id',
        'single_primary_correct_count',
        'single_primary_answered_count',
        'supporting_positive_count',
        'supporting_exposure_count',
        'last_rebuilt_at',
    ];

    protected function casts(): array
    {
        return [
            'single_primary_correct_count' => 'integer',
            'single_primary_answered_count' => 'integer',
            'supporting_positive_count' => 'integer',
            'supporting_exposure_count' => 'integer',
            'last_rebuilt_at' => 'immutable_datetime',
        ];
    }

    public function learnerProfile(): BelongsTo
    {
        return $this->belongsTo(LearnerProfile::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function evidenceScope(): BelongsTo
    {
        return $this->belongsTo(EvidenceScope::class);
    }
}

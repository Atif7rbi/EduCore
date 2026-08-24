<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceScope extends Model
{
    use HasUuids;

    protected $fillable = [
        'label',
        'description',
        'definition_payload',
        'definition_schema_version',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'definition_payload' => 'array',
            'definition_schema_version' => 'integer',
        ];
    }

    public function materializedSkillPerformances(): HasMany
    {
        return $this->hasMany(MaterializedSkillPerformance::class);
    }
}

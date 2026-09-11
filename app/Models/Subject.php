<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }

    public function isCanonical(): bool
    {
        return $this->code !== null;
    }

    public function isAvailableForNewContent(): bool
    {
        return $this->isCanonical()
            && $this->status === 'active';
    }
}

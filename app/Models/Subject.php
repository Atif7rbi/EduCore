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

    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }
}

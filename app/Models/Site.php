<?php

namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'name',
        'url',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
        ];
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class);
    }

    public function workAssignments(): HasMany
    {
        return $this->hasMany(WorkAssignment::class);
    }
}

<?php

namespace App\Models;

use App\Enums\ComputerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Computer extends Model
{
    protected $fillable = [
        'unique_number',
        'label',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ComputerStatus::class,
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ComputerAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(ComputerAssignment::class)->whereNull('unassigned_at');
    }
}

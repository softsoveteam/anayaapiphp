<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSnapshot extends Model
{
    protected $fillable = [
        'employee_id',
        'month',
        'payload',
        'frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'frozen_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}

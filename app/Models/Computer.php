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

    public static function nextUniqueNumber(): string
    {
        $values = static::query()->pluck('unique_number')->merge(
            static::query()->pluck('label')
        );

        $max = 0;
        foreach ($values as $value) {
            if (preg_match('/PC-(\d+)/i', (string) $value, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'PC-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}

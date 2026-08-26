<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSession extends Model
{
    protected $fillable = [
        'employee_id',
        'duration_seconds',
        'started_at',
        'ends_at',
        'finished_at',
        'status',
        'site_count',
        'clicks_awarded',
        'sites',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
            'finished_at' => 'datetime',
            'sites' => 'array',
            'duration_seconds' => 'integer',
            'site_count' => 'integer',
            'clicks_awarded' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function remainingSeconds(): int
    {
        if ($this->status !== 'running' || ! $this->ends_at) {
            return 0;
        }

        return max(0, $this->ends_at->getTimestamp() - now()->getTimestamp());
    }

    public function isDue(): bool
    {
        return $this->status === 'running' && now()->greaterThanOrEqualTo($this->ends_at);
    }
}

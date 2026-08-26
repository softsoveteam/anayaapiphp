<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkAssignment extends Model
{
    protected $fillable = [
        'employee_id',
        'site_id',
        'keyword_id',
        'work_date',
        'target_clicks',
        'scheduled_by',
        'is_auto_copied',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'is_auto_copied' => 'boolean',
            'target_clicks' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function report(): HasOne
    {
        return $this->hasOne(WorkReport::class);
    }
}

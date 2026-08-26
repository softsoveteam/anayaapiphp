<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    protected $fillable = [
        'unique_id',
        'name',
        'email',
        'phone',
        'address',
        'emergency_contact',
        'status',
        'interview_date',
        'joining_date',
        'notes',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => EmployeeStatus::class,
            'interview_date' => 'date',
            'joining_date' => 'date',
        ];
    }

    public function computerAssignments(): HasMany
    {
        return $this->hasMany(ComputerAssignment::class, 'employee_id');
    }

    public function activeComputerAssignments(): HasMany
    {
        return $this->computerAssignments()->whereNull('unassigned_at');
    }

    public function workAssignments(): HasMany
    {
        return $this->hasMany(WorkAssignment::class, 'employee_id');
    }

    public function workReports(): HasMany
    {
        return $this->hasMany(WorkReport::class, 'employee_id');
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class, 'employee_id');
    }

    public function canLogin(): bool
    {
        if ($this->hasAnyRole(['admin', 'manager'])) {
            return true;
        }

        return $this->status instanceof EmployeeStatus && $this->status->canLogin();
    }

    public function primaryRole(): ?string
    {
        return $this->getRoleNames()->first();
    }

    public static function nextUniqueId(): string
    {
        $ids = static::query()->where('unique_id', 'like', 'ANAYA-%')->pluck('unique_id');
        $max = 0;
        foreach ($ids as $id) {
            if (preg_match('/ANAYA-(\d+)$/', (string) $id, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'ANAYA-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}

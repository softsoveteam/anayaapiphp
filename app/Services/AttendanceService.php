<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;

class AttendanceService
{
    public const LATE_AFTER = '09:15';

    public const IDLE_AFTER_MINUTES = 20;

    public const MORNING_END = '13:30';

    public function __construct(private PayrollService $payroll) {}

    public function forUser(User $user, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->timezone(PayrollService::TIMEZONE);
        $today = $now->toDateString();
        $holiday = Holiday::query()->whereDate('date', $today)->first();

        $sessions = WorkSession::query()
            ->where('employee_id', $user->id)
            ->where(function ($q) use ($today) {
                $q->whereDate('started_at', $today)
                    ->orWhere(function ($inner) use ($today) {
                        $inner->where('status', 'running')->whereDate('started_at', $today);
                    });
            })
            ->orderBy('started_at')
            ->get();

        $first = $sessions->first();
        $running = $sessions->firstWhere('status', 'running');
        $last = $sessions->sortByDesc(fn (WorkSession $s) => ($s->finished_at ?? $s->ends_at ?? $s->started_at)?->timestamp)->first();

        $inAt = $first?->started_at?->copy()->timezone(PayrollService::TIMEZONE);
        $lastAt = $running
            ? $now
            : ($last?->finished_at ?? $last?->ends_at)?->copy()->timezone(PayrollService::TIMEZONE);

        $lateCutoff = $now->copy()->setTimeFromTimeString(self::LATE_AFTER);
        $workStart = $now->copy()->setTimeFromTimeString(PayrollService::WORK_START);
        $workEnd = $now->copy()->setTimeFromTimeString(PayrollService::WORK_END);
        $withinHours = $now->gte($workStart) && $now->lt($workEnd);

        $late = false;
        if ($inAt) {
            $late = $inAt->gt($lateCutoff);
        } elseif ($now->gt($lateCutoff) && $this->payroll->isWorkingDay($now, $holiday ? [$today] : [])) {
            $late = true;
        }

        $leave = $this->activeLeave($user->id, $now);
        $status = 'not_started';
        $label = 'Not started';

        if ($now->isSunday()) {
            $status = 'sunday';
            $label = 'Sunday off';
        } elseif ($holiday) {
            $status = 'holiday';
            $label = 'Holiday · '.$holiday->name;
        } elseif ($leave) {
            $status = 'on_leave';
            $label = $leave['label'];
        } elseif ($running) {
            $status = 'on_timer';
            $label = 'On timer';
        } elseif ($sessions->isNotEmpty() && $withinHours && $lastAt && $lastAt->lte($now->copy()->subMinutes(self::IDLE_AFTER_MINUTES))) {
            $status = 'idle';
            $label = 'Idle · no session for '.self::IDLE_AFTER_MINUTES.' min';
        } elseif (! $inAt && $late && $this->payroll->isWorkingDay($now, [])) {
            $status = 'late';
            $label = 'Late · not started';
        } elseif ($inAt && $withinHours) {
            $status = 'working';
            $label = $late ? 'Working · came late' : 'Working';
        } elseif ($inAt) {
            $status = 'done';
            $label = 'Day complete';
        }

        return [
            'employee_id' => $user->id,
            'name' => $user->name,
            'unique_id' => $user->unique_id,
            'status' => $status,
            'label' => $label,
            'late' => $late,
            'in_at' => $inAt?->toIso8601String(),
            'last_at' => $lastAt?->toIso8601String(),
            'remaining_seconds' => $running?->remainingSeconds() ?? 0,
        ];
    }

    public function floor(): array
    {
        $employees = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->orderBy('name')
            ->get();

        $rows = $employees->map(fn (User $user) => $this->forUser($user));

        $counts = [
            'on_timer' => $rows->where('status', 'on_timer')->count(),
            'idle' => $rows->where('status', 'idle')->count(),
            'not_started' => $rows->where('status', 'not_started')->count(),
            'late' => $rows->where('status', 'late')->count(),
            'on_leave' => $rows->where('status', 'on_leave')->count(),
            'working' => $rows->whereIn('status', ['working', 'done'])->count(),
            'holiday' => $rows->whereIn('status', ['holiday', 'sunday'])->count(),
        ];

        return [
            'now' => now()->timezone(PayrollService::TIMEZONE)->toIso8601String(),
            'counts' => $counts,
            'data' => $rows->values(),
        ];
    }

    /**
     * @return array{label: string, half: ?string}|null
     */
    public function activeLeave(int $employeeId, Carbon $now): ?array
    {
        $today = $now->toDateString();
        $leaves = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->get();

        if ($leaves->isEmpty()) {
            return null;
        }

        $morningEnd = $now->copy()->setTimeFromTimeString(self::MORNING_END);
        $hasFull = $leaves->contains(fn (LeaveRequest $l) => ($l->portion ?? 1) >= 1 || ! $l->half);
        $hasMorning = $leaves->contains(fn (LeaveRequest $l) => $l->half === 'morning');
        $hasAfternoon = $leaves->contains(fn (LeaveRequest $l) => $l->half === 'afternoon');

        if ($hasFull || ($hasMorning && $hasAfternoon)) {
            return ['label' => 'On leave (full day)', 'half' => null];
        }
        if ($hasMorning && $now->lt($morningEnd)) {
            return ['label' => 'On leave (morning)', 'half' => 'morning'];
        }
        if ($hasAfternoon && $now->gte($morningEnd)) {
            return ['label' => 'On leave (afternoon)', 'half' => 'afternoon'];
        }

        return null;
    }
}

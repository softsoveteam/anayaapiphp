<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\AppSetting;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkAssignment;
use App\Models\WorkReport;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;

class PaceService
{
    public function __construct(
        private AttendanceService $attendance,
        private PayrollService $payroll,
    ) {}

    public function forUser(User $user, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->timezone(PayrollService::TIMEZONE);
        $minutes = AppSetting::sessionMinutes();
        $computers = $user->activeComputerAssignments()->count();
        $assignments = WorkAssignment::query()
            ->where('employee_id', $user->id)
            ->whereDate('work_date', $now->toDateString())
            ->get();
        $multiple = AppSetting::multipleKeywords();
        $tabs = AppSetting::sessionWorkItems($assignments)->count();
        $sites = $assignments->unique('site_id')->count();
        $keywords = $assignments->count();
        $perSession = $computers * $tabs;

        $done = (int) WorkReport::query()
            ->where('employee_id', $user->id)
            ->whereDate('work_date', $now->toDateString())
            ->sum('click_count');

        [$windowStart, $windowEnd, $off] = $this->window($user, $now);

        $remainingFrom = $now->greaterThan($windowStart) ? $now : $windowStart;
        $sessionsLeft = $this->sessionsBetween($remainingFrom, $windowEnd, $minutes);

        $inAt = WorkSession::query()
            ->where('employee_id', $user->id)
            ->whereDate('started_at', $now->toDateString())
            ->orderBy('started_at')
            ->value('started_at');

        $dayFrom = $inAt
            ? Carbon::parse($inAt)->timezone(PayrollService::TIMEZONE)
            : $remainingFrom;
        if ($dayFrom->lt($windowStart)) {
            $dayFrom = $windowStart->copy();
        }
        $sessionsToday = $this->sessionsBetween($dayFrom, $windowEnd, $minutes);

        [$lunchStart, $lunchEnd] = PayrollService::lunchBounds($now);

        return [
            'employee_id' => $user->id,
            'name' => $user->name,
            'unique_id' => $user->unique_id,
            'computers' => $computers,
            'sites' => $sites,
            'keywords' => $keywords,
            'tabs' => $tabs,
            'multiple_keywords' => $multiple,
            'clicks_per_session' => $perSession,
            'session_minutes' => $minutes,
            'done' => $done,
            'sessions_left' => $sessionsLeft,
            'expected_remaining' => $sessionsLeft * $perSession,
            'expected_today' => $sessionsToday * $perSession,
            'window_start_at' => $windowStart->toIso8601String(),
            'work_end_at' => $windowEnd->toIso8601String(),
            'lunch_start_at' => $lunchStart->toIso8601String(),
            'lunch_end_at' => $lunchEnd->toIso8601String(),
            'off' => $off,
        ];
    }

    public function forTeam(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->timezone(PayrollService::TIMEZONE);
        $employees = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->orderBy('name')
            ->get();

        $rows = $employees->map(fn (User $user) => $this->forUser($user, $now));

        return [
            'now' => $now->toIso8601String(),
            'remaining' => (int) $rows->sum('expected_remaining'),
            'done' => (int) $rows->sum('done'),
            'expected_today' => (int) $rows->sum('expected_today'),
            'data' => $rows->values(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: ?string}
     */
    public function window(User $user, Carbon $now): array
    {
        $workStart = $now->copy()->setTimeFromTimeString(PayrollService::WORK_START);
        $workEnd = $now->copy()->setTimeFromTimeString(PayrollService::WORK_END);
        $today = $now->toDateString();
        $holiday = Holiday::query()->whereDate('date', $today)->first();

        if ($now->isSunday()) {
            return [$workStart, $workStart, 'sunday'];
        }
        if ($holiday) {
            return [$workStart, $workStart, 'holiday'];
        }

        $start = $workStart->copy();
        $end = $workEnd->copy();
        $leave = $this->attendance->activeLeave($user->id, $now);
        $morningEnd = $now->copy()->setTimeFromTimeString(AttendanceService::MORNING_END);

        if ($leave) {
            if ($leave['half'] === null) {
                return [$start, $start, 'leave'];
            }
            if ($leave['half'] === 'morning') {
                $start = $morningEnd;
            }
            if ($leave['half'] === 'afternoon') {
                $end = $morningEnd;
            }
        }

        if ($end->lte($start) || $now->gte($end)) {
            return [$start, $start, $leave ? 'leave' : 'after_hours'];
        }

        return [$start, $end, null];
    }

    public function sessionsBetween(Carbon $from, Carbon $to, int $minutes): int
    {
        if ($to->lte($from) || $minutes < 1) {
            return 0;
        }

        $seconds = $to->getTimestamp() - $from->getTimestamp();
        $seconds -= PayrollService::lunchOverlapSeconds($from, $to);

        if ($seconds <= 0) {
            return 0;
        }

        return intdiv($seconds, $minutes * 60);
    }
}

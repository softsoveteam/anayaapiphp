<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;

class PayrollService
{
    public const TIMEZONE = 'Asia/Kolkata';

    public const WORK_START = '09:00';

    public const WORK_END = '18:00';

    public const HOURS_PER_DAY = 9;

    public const PAID_LEAVE_PER_MONTH = 1;

    public const OT_MULTIPLIER = 2;

    public function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month, self::TIMEZONE)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start, $end];
    }

    public function holidayDates(Carbon $from, Carbon $to): array
    {
        return Holiday::datesInRange($from, $to);
    }

    public function isSunday(Carbon $date): bool
    {
        return $date->copy()->timezone(self::TIMEZONE)->isSunday();
    }

    public function isWorkingDay(Carbon $date, array $holidayDates): bool
    {
        $local = $date->copy()->timezone(self::TIMEZONE)->startOfDay();

        if ($local->isSunday()) {
            return false;
        }

        return ! in_array($local->toDateString(), $holidayDates, true);
    }

    /**
     * @return list<string> Y-m-d working days inclusive
     */
    public function workingDatesInRange(Carbon $from, Carbon $to, array $holidayDates): array
    {
        $dates = [];
        $cursor = $from->copy()->timezone(self::TIMEZONE)->startOfDay();
        $last = $to->copy()->timezone(self::TIMEZONE)->startOfDay();

        while ($cursor->lte($last)) {
            if ($this->isWorkingDay($cursor, $holidayDates)) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    public function forMonth(string $month): array
    {
        [$start, $end] = $this->monthBounds($month);
        $holidays = $this->holidayDates($start, $end);

        $employees = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->orderBy('name')
            ->get();

        $rows = $employees->map(fn (User $user) => $this->forEmployee($user, $month, $start, $end, $holidays, false));

        return [
            'month' => $month,
            'calendar_days' => $start->daysInMonth,
            'work_start' => self::WORK_START,
            'work_end' => self::WORK_END,
            'paid_leave_quota' => self::PAID_LEAVE_PER_MONTH,
            'totals' => [
                'employees' => $rows->count(),
                'base' => round($rows->sum('base'), 2),
                'leave_deduction' => round($rows->sum('leave_deduction'), 2),
                'overtime_pay' => round($rows->sum('overtime_pay'), 2),
                'net' => round($rows->sum('net'), 2),
            ],
            'data' => $rows->values(),
        ];
    }

    public function forEmployee(
        User $user,
        string $month,
        ?Carbon $start = null,
        ?Carbon $end = null,
        ?array $holidayDates = null,
        bool $withOtLog = true,
    ): array {
        if (! $start || ! $end) {
            [$start, $end] = $this->monthBounds($month);
        }
        $holidayDates ??= $this->holidayDates($start, $end);

        $calendarDays = $start->daysInMonth;
        $base = (float) ($user->monthly_salary ?? 0);
        $dayRate = $calendarDays > 0 ? round($base / $calendarDays, 2) : 0.0;
        $hourlyRate = round($dayRate / self::HOURS_PER_DAY, 2);
        $otHourly = round($hourlyRate * self::OT_MULTIPLIER, 2);

        $leaveDays = $this->approvedLeaveDates($user->id, $start, $end, $holidayDates);
        $leaveDayCount = count($leaveDays);
        $paidLeaveUsed = min(self::PAID_LEAVE_PER_MONTH, $leaveDayCount);
        $unpaidDays = max(0, $leaveDayCount - self::PAID_LEAVE_PER_MONTH);
        $leaveDeduction = round($unpaidDays * $dayRate, 2);

        $ot = $this->overtimeForUser($user->id, $start, $end, $holidayDates);
        $otHours = round($ot['seconds'] / 3600, 2);
        $otPay = round($otHours * $otHourly, 2);
        $net = round($base - $leaveDeduction + $otPay, 2);

        $row = [
            'employee_id' => $user->id,
            'name' => $user->name,
            'unique_id' => $user->unique_id,
            'month' => $month,
            'calendar_days' => $calendarDays,
            'base' => $base,
            'day_rate' => $dayRate,
            'hourly_rate' => $hourlyRate,
            'overtime_hourly_rate' => $otHourly,
            'paid_leave_quota' => self::PAID_LEAVE_PER_MONTH,
            'paid_leave_used' => $paidLeaveUsed,
            'leave_days' => $leaveDayCount,
            'unpaid_leave_days' => $unpaidDays,
            'leave_dates' => $leaveDays,
            'leave_deduction' => $leaveDeduction,
            'overtime_seconds' => $ot['seconds'],
            'overtime_hours' => $otHours,
            'overtime_pay' => $otPay,
            'net' => $net,
        ];

        if ($withOtLog) {
            $row['overtime_sessions'] = $ot['sessions'];
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public function approvedLeaveDates(int $employeeId, Carbon $start, Carbon $end, array $holidayDates): array
    {
        $requests = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->get();

        $dates = [];
        foreach ($requests as $request) {
            $from = $request->start_date->greaterThan($start) ? $request->start_date : $start;
            $to = $request->end_date->lessThan($end) ? $request->end_date : $end;
            foreach ($this->workingDatesInRange($from, $to, $holidayDates) as $date) {
                $dates[$date] = true;
            }
        }

        $list = array_keys($dates);
        sort($list);

        return $list;
    }

    public function overtimeForUser(int $employeeId, Carbon $start, Carbon $end, array $holidayDates): array
    {
        $sessions = WorkSession::query()
            ->where('employee_id', $employeeId)
            ->where('started_at', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->where('finished_at', '>=', $start)
                    ->orWhere(function ($inner) use ($start) {
                        $inner->whereNull('finished_at')->where('ends_at', '>=', $start);
                    });
            })
            ->orderBy('started_at')
            ->get();

        $total = 0;
        $log = [];

        foreach ($sessions as $session) {
            $seconds = $this->overtimeSecondsInSession($session, $start, $end, $holidayDates);
            if ($seconds <= 0) {
                continue;
            }
            $total += $seconds;
            $log[] = [
                'id' => $session->id,
                'started_at' => $session->started_at?->timezone(self::TIMEZONE)->toIso8601String(),
                'finished_at' => ($session->finished_at ?? now())->timezone(self::TIMEZONE)->toIso8601String(),
                'overtime_seconds' => $seconds,
                'overtime_hours' => round($seconds / 3600, 2),
            ];
        }

        return ['seconds' => $total, 'sessions' => $log];
    }

    public function overtimeSecondsInSession(WorkSession $session, Carbon $monthStart, Carbon $monthEnd, array $holidayDates): int
    {
        $sessionStart = $session->started_at->copy()->timezone(self::TIMEZONE);
        $sessionEnd = ($session->finished_at ?? now())->copy()->timezone(self::TIMEZONE);

        $clipStart = $sessionStart->greaterThan($monthStart) ? $sessionStart : $monthStart->copy();
        $clipEnd = $sessionEnd->lessThan($monthEnd) ? $sessionEnd : $monthEnd->copy();

        if ($clipEnd->lte($clipStart)) {
            return 0;
        }

        $ot = 0;
        $cursor = $clipStart->copy()->startOfDay();
        $lastDay = $clipEnd->copy()->startOfDay();

        while ($cursor->lte($lastDay)) {
            $dayStart = $cursor->copy();
            $dayEnd = $cursor->copy()->endOfDay();
            $segStart = $clipStart->greaterThan($dayStart) ? $clipStart : $dayStart;
            $segEnd = $clipEnd->lessThan($dayEnd) ? $clipEnd : $dayEnd;

            if ($segEnd->gt($segStart)) {
                $date = $cursor->toDateString();
                $allOt = $cursor->isSunday() || in_array($date, $holidayDates, true);

                if ($allOt) {
                    $ot += $segEnd->getTimestamp() - $segStart->getTimestamp();
                } else {
                    $windowStart = $cursor->copy()->setTime(18, 0, 0);
                    $windowEnd = $cursor->copy()->endOfDay();
                    $overlapStart = $segStart->greaterThan($windowStart) ? $segStart : $windowStart;
                    $overlapEnd = $segEnd->lessThan($windowEnd) ? $segEnd : $windowEnd;
                    if ($overlapEnd->gt($overlapStart)) {
                        $ot += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
                    }
                }
            }

            $cursor->addDay();
        }

        return (int) $ot;
    }

    public function serializeLeave(LeaveRequest $leave): array
    {
        return [
            'id' => $leave->id,
            'employee_id' => $leave->employee_id,
            'employee_name' => $leave->employee?->name,
            'unique_id' => $leave->employee?->unique_id,
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'days' => $leave->days,
            'reason' => $leave->reason,
            'status' => $leave->status,
            'reviewed_by' => $leave->reviewer?->name,
            'reviewed_at' => $leave->reviewed_at?->timezone(self::TIMEZONE)->toIso8601String(),
            'created_at' => $leave->created_at?->timezone(self::TIMEZONE)->toIso8601String(),
        ];
    }

    public function serializeHoliday(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date?->toDateString(),
            'name' => $holiday->name,
            'notes' => $holiday->notes,
        ];
    }

    public function overlappingLeave(int $employeeId, string $start, string $end, ?int $ignoreId = null): ?LeaveRequest
    {
        $query = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start);

        if ($ignoreId) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->first();
    }
}

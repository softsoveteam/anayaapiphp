<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\PayrollSnapshot;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public const TIMEZONE = 'Asia/Kolkata';

    public const WORK_START = '09:00';

    public const WORK_END = '18:00';

    public const LUNCH_START = '13:00';

    public const LUNCH_END = '13:45';

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
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function lunchBounds(Carbon $day): array
    {
        $local = $day->copy()->timezone(self::TIMEZONE);

        return [
            $local->copy()->setTimeFromTimeString(self::LUNCH_START),
            $local->copy()->setTimeFromTimeString(self::LUNCH_END),
        ];
    }

    public static function isLunch(Carbon $now): bool
    {
        $local = $now->copy()->timezone(self::TIMEZONE);
        [$start, $end] = self::lunchBounds($local);

        return $local->gte($start) && $local->lt($end);
    }

    public static function lunchOverlapSeconds(Carbon $from, Carbon $to): int
    {
        $from = $from->copy()->timezone(self::TIMEZONE);
        $to = $to->copy()->timezone(self::TIMEZONE);

        if ($to->lte($from)) {
            return 0;
        }

        [$lunchStart, $lunchEnd] = self::lunchBounds($from);
        $overlapStart = $from->greaterThan($lunchStart) ? $from : $lunchStart;
        $overlapEnd = $to->lessThan($lunchEnd) ? $to : $lunchEnd;

        if ($overlapEnd->lte($overlapStart)) {
            return 0;
        }

        return $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
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
        [$start] = $this->monthBounds($month);

        $employees = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->orderBy('name')
            ->get();

        $rows = $employees->map(fn (User $user) => $this->resolveForEmployee($user, $month, false));
        $frozenCount = $rows->where('frozen', true)->count();
        $live = $this->isCurrentMonth($month);

        return [
            'month' => $month,
            'calendar_days' => $start->daysInMonth,
            'work_start' => self::WORK_START,
            'work_end' => self::WORK_END,
            'paid_leave_quota' => self::PAID_LEAVE_PER_MONTH,
            'live' => $live,
            'frozen' => ! $live && $frozenCount === $rows->count() && $rows->isNotEmpty(),
            'frozen_count' => $frozenCount,
            'previous_month' => $this->previousMonth(),
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

    public function currentMonth(): string
    {
        return now(self::TIMEZONE)->format('Y-m');
    }

    public function previousMonth(?string $from = null): string
    {
        $from ??= $this->currentMonth();

        return Carbon::createFromFormat('Y-m', $from, self::TIMEZONE)->subMonthNoOverflow()->format('Y-m');
    }

    public function isCurrentMonth(string $month): bool
    {
        return $month === $this->currentMonth();
    }

    public function resolveForEmployee(User $user, string $month, bool $withOtLog = true): array
    {
        if (! $this->isCurrentMonth($month)) {
            $snapshot = PayrollSnapshot::query()
                ->where('employee_id', $user->id)
                ->where('month', $month)
                ->first();

            if ($snapshot) {
                $payload = $snapshot->payload ?? [];
                $payload['frozen'] = true;
                $payload['frozen_at'] = $snapshot->frozen_at?->timezone(self::TIMEZONE)->toIso8601String();
                if (! $withOtLog) {
                    unset($payload['overtime_sessions']);
                }

                return $payload;
            }
        }

        $row = $this->forEmployee($user, $month, withOtLog: $withOtLog);
        $row['frozen'] = false;
        $row['frozen_at'] = null;

        return $row;
    }

    public function freezeMonth(string $month, bool $onlyMissing = true): int
    {
        if ($this->isCurrentMonth($month)) {
            throw ValidationException::withMessages([
                'month' => ['The current month stays live until it ends.'],
            ]);
        }

        $employees = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->orderBy('name')
            ->get();

        $count = 0;
        foreach ($employees as $user) {
            $existing = PayrollSnapshot::query()
                ->where('employee_id', $user->id)
                ->where('month', $month)
                ->exists();

            if ($existing && $onlyMissing) {
                continue;
            }

            $payload = $this->forEmployee($user, $month);
            $payload['frozen'] = true;
            $payload['frozen_at'] = now(self::TIMEZONE)->toIso8601String();

            PayrollSnapshot::query()->updateOrCreate(
                ['employee_id' => $user->id, 'month' => $month],
                [
                    'payload' => $payload,
                    'frozen_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
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

        $leaveDays = $this->approvedLeaveUnits($user->id, $start, $end, $holidayDates);
        $leaveDayCount = round(array_sum(array_column($leaveDays, 'portion')), 2);
        $paidLeaveUsed = min(self::PAID_LEAVE_PER_MONTH, $leaveDayCount);
        $unpaidDays = round(max(0, $leaveDayCount - self::PAID_LEAVE_PER_MONTH), 2);
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
            'leave_dates' => array_column($leaveDays, 'date'),
            'leave_items' => $leaveDays,
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
     * @return list<array{date: string, portion: float, kind: string}>
     */
    public function approvedLeaveUnits(int $employeeId, Carbon $start, Carbon $end, array $holidayDates): array
    {
        $requests = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->get();

        $byDate = [];
        foreach ($requests as $request) {
            $from = $request->start_date->greaterThan($start) ? $request->start_date : $start;
            $to = $request->end_date->lessThan($end) ? $request->end_date : $end;
            $portion = (float) ($request->portion ?? 1);
            foreach ($this->workingDatesInRange($from, $to, $holidayDates) as $date) {
                $byDate[$date] = min(1.0, ($byDate[$date] ?? 0) + $portion);
            }
        }

        ksort($byDate);
        $paidLeft = self::PAID_LEAVE_PER_MONTH;
        $items = [];
        foreach ($byDate as $date => $portion) {
            $paid = min($portion, $paidLeft);
            $paidLeft = round($paidLeft - $paid, 2);
            $items[] = [
                'date' => $date,
                'portion' => $portion,
                'kind' => $paid >= $portion ? 'paid' : ($paid > 0 ? 'mixed' : 'unpaid'),
            ];
        }

        return $items;
    }

    public function approvedLeaveDates(int $employeeId, Carbon $start, Carbon $end, array $holidayDates): array
    {
        return array_column($this->approvedLeaveUnits($employeeId, $start, $end, $holidayDates), 'date');
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
            'portion' => (float) ($leave->portion ?? 1),
            'half' => $leave->half,
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

    public function overlappingLeave(int $employeeId, string $start, string $end, ?int $ignoreId = null, ?string $half = null): ?LeaveRequest
    {
        $query = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start);

        if ($ignoreId) {
            $query->whereKeyNot($ignoreId);
        }

        foreach ($query->get() as $existing) {
            $existingHalf = $existing->half;
            if (! $existingHalf || ! $half) {
                return $existing;
            }
            if ($existingHalf === $half) {
                return $existing;
            }
        }

        return null;
    }
}

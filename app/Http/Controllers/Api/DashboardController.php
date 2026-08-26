<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComputerStatus;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Computer;
use App\Models\User;
use App\Models\WorkAssignment;
use App\Models\WorkReport;
use App\Services\AttendanceService;
use App\Services\PaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private PaceService $pace,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        if ($user->hasRole('employee') && ! $user->hasAnyRole(['admin', 'manager'])) {
            return $this->employeeDashboard($user, $today, $yesterday);
        }

        $todayClicks = (int) WorkReport::query()->whereDate('work_date', $today)->sum('click_count');
        $yesterdayClicks = (int) WorkReport::query()->whereDate('work_date', $yesterday)->sum('click_count');

        $joinedEmployees = User::query()->role('employee')->where('status', EmployeeStatus::Joined);

        $submittedToday = WorkReport::query()
            ->whereDate('work_date', $today)
            ->distinct()
            ->pluck('employee_id');

        $assignedToday = WorkAssignment::query()
            ->whereDate('work_date', $today)
            ->distinct()
            ->pluck('employee_id');

        $pending = User::query()
            ->whereIn('id', $assignedToday->diff($submittedToday))
            ->orderBy('name')
            ->get(['id', 'unique_id', 'name']);

        $unscheduled = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->whereNotIn('id', $assignedToday)
            ->orderBy('name')
            ->get(['id', 'unique_id', 'name']);

        $trend = WorkReport::query()
            ->where('work_date', '>=', now()->subDays(13)->toDateString())
            ->get()
            ->groupBy(fn ($r) => $r->work_date->toDateString())
            ->map(fn ($rows, $day) => ['date' => $day, 'clicks' => (int) $rows->sum('click_count')])
            ->sortKeys()
            ->values();

        $top = WorkReport::query()
            ->with('employee')
            ->selectRaw('employee_id, SUM(click_count) as clicks')
            ->whereDate('work_date', $today)
            ->groupBy('employee_id')
            ->orderByDesc('clicks')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'employee_id' => $row->employee_id,
                'name' => $row->employee?->name,
                'unique_id' => $row->employee?->unique_id,
                'clicks' => (int) $row->clicks,
            ]);

        $pipeline = User::query()
            ->role('employee')
            ->get()
            ->groupBy(fn ($u) => $u->status?->value)
            ->map(fn ($rows) => $rows->count());

        $expected = $this->pace->forTeam();

        return response()->json([
            'today' => $today,
            'metrics' => [
                'today_clicks' => $todayClicks,
                'yesterday_clicks' => $yesterdayClicks,
                'clicks_change' => $this->pctChange($todayClicks, $yesterdayClicks),
                'active_employees' => $joinedEmployees->count(),
                'pending_eod' => $pending->count(),
                'computers_assigned' => Computer::query()->where('status', ComputerStatus::Assigned)->count(),
                'computers_available' => Computer::query()->where('status', ComputerStatus::Available)->count(),
                'expected_remaining' => $expected['remaining'],
                'expected_today' => $expected['expected_today'],
            ],
            'pending_eod' => $pending,
            'unscheduled' => $unscheduled,
            'trend' => $trend,
            'top_performers' => $top,
            'pipeline' => $pipeline,
            'expected' => $expected,
        ]);
    }

    private function employeeDashboard(User $user, string $today, string $yesterday): JsonResponse
    {
        $todayClicks = (int) WorkReport::query()
            ->where('employee_id', $user->id)
            ->whereDate('work_date', $today)
            ->sum('click_count');

        $yesterdayClicks = (int) WorkReport::query()
            ->where('employee_id', $user->id)
            ->whereDate('work_date', $yesterday)
            ->sum('click_count');

        $assignments = WorkAssignment::query()
            ->with(['site', 'keyword', 'report'])
            ->where('employee_id', $user->id)
            ->whereDate('work_date', $today)
            ->get();

        $trend = WorkReport::query()
            ->where('employee_id', $user->id)
            ->where('work_date', '>=', now()->subDays(13)->toDateString())
            ->get()
            ->groupBy(fn ($r) => $r->work_date->toDateString())
            ->map(fn ($rows, $day) => ['date' => $day, 'clicks' => (int) $rows->sum('click_count')])
            ->sortKeys()
            ->values();

        $mapped = $assignments->map(function (WorkAssignment $a) {
            return [
                'id' => $a->id,
                'site_name' => $a->site?->name,
                'site_url' => $a->site?->url,
                'site_domain' => $a->site?->resolvedDomain(),
                'site_favicon' => $a->site?->resolvedFavicon(),
                'keyword' => $a->keyword?->keyword,
                'click_count' => (int) ($a->report?->click_count ?? 0),
            ];
        });

        $expected = $this->pace->forUser($user);

        return response()->json([
            'today' => $today,
            'metrics' => [
                'today_clicks' => $todayClicks,
                'yesterday_clicks' => $yesterdayClicks,
                'clicks_change' => $this->pctChange($todayClicks, $yesterdayClicks),
                'assignments' => $assignments->count(),
                'submitted' => $assignments->filter(fn ($a) => $a->report)->count(),
                'computers_assigned' => $expected['computers'],
                'expected_remaining' => $expected['expected_remaining'],
                'expected_today' => $expected['expected_today'],
            ],
            'assignments' => $mapped->values(),
            'attendance' => $this->attendance->forUser($user),
            'expected' => $expected,
            'trend' => $trend,
        ]);
    }

    private function pctChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}

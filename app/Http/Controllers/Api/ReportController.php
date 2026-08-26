<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkAssignment;
use App\Models\WorkReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();
        $user = $request->user();

        $assignments = WorkAssignment::query()
            ->with(['site', 'keyword', 'report'])
            ->where('employee_id', $user->id)
            ->whereDate('work_date', $date)
            ->get();

        $total = $assignments->sum(fn ($a) => $a->report?->click_count ?? 0);

        return response()->json([
            'date' => $date,
            'total_clicks' => $total,
            'submitted' => $assignments->isNotEmpty() && $assignments->every(fn ($a) => $a->report !== null),
            'data' => $assignments->map(function (WorkAssignment $a) {
                return [
                    'assignment_id' => $a->id,
                    'site_id' => $a->site_id,
                    'site_name' => $a->site?->name,
                    'site_url' => $a->site?->url,
                    'keyword_id' => $a->keyword_id,
                    'keyword' => $a->keyword?->keyword,
                    'click_count' => (int) ($a->report?->click_count ?? 0),
                    'notes' => $a->report?->notes,
                    'submitted_at' => $a->report?->submitted_at?->toIso8601String(),
                ];
            }),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.assignment_id' => ['required', 'exists:work_assignments,id'],
            'items.*.click_count' => ['required', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $date = $data['date'] ?? now()->toDateString();
        $user = $request->user();
        $isManager = $user->hasAnyRole(['admin', 'manager']);

        if (! $isManager) {
            throw ValidationException::withMessages([
                'items' => ['Clicks are counted automatically when a work session finishes. Manual entry is not allowed.'],
            ]);
        }

        $saved = [];

        foreach ($data['items'] as $item) {
            $assignment = WorkAssignment::query()->with(['site', 'keyword'])->findOrFail($item['assignment_id']);

            if (! $isManager && $assignment->employee_id !== $user->id) {
                throw ValidationException::withMessages([
                    'items' => ['You can only report on your own assignments.'],
                ]);
            }

            if ($assignment->work_date->toDateString() !== $date) {
                throw ValidationException::withMessages([
                    'items' => ['Assignment date does not match the report date.'],
                ]);
            }

            $report = WorkReport::query()->updateOrCreate(
                ['work_assignment_id' => $assignment->id],
                [
                    'employee_id' => $assignment->employee_id,
                    'site_id' => $assignment->site_id,
                    'keyword_id' => $assignment->keyword_id,
                    'work_date' => $assignment->work_date,
                    'click_count' => $item['click_count'],
                    'notes' => $item['notes'] ?? null,
                    'submitted_at' => now(),
                ]
            );

            $saved[] = [
                'id' => $report->id,
                'assignment_id' => $assignment->id,
                'site_name' => $assignment->site?->name,
                'keyword' => $assignment->keyword?->keyword,
                'click_count' => $report->click_count,
            ];
        }

        return response()->json([
            'message' => 'Report saved.',
            'data' => $saved,
            'total_clicks' => collect($saved)->sum('click_count'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $from = $request->string('from')->toString() ?: now()->toDateString();
        $to = $request->string('to')->toString() ?: $from;

        $query = WorkReport::query()
            ->with(['employee', 'site', 'keyword'])
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to);

        if ($employeeId = $request->integer('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($siteId = $request->integer('site_id')) {
            $query->where('site_id', $siteId);
        }

        $reports = $query->orderByDesc('work_date')->orderBy('employee_id')->get();

        $byEmployee = $reports->groupBy('employee_id')->map(function ($rows) {
            $first = $rows->first();

            return [
                'employee_id' => $first->employee_id,
                'name' => $first->employee?->name,
                'unique_id' => $first->employee?->unique_id,
                'clicks' => $rows->sum('click_count'),
                'reports' => $rows->count(),
            ];
        })->values();

        $bySite = $reports->groupBy('site_id')->map(function ($rows) {
            $first = $rows->first();

            return [
                'site_id' => $first->site_id,
                'name' => $first->site?->name,
                'clicks' => $rows->sum('click_count'),
            ];
        })->values();

        $byDay = $reports->groupBy(fn ($r) => $r->work_date->toDateString())->map(function ($rows, $day) {
            return [
                'date' => $day,
                'clicks' => $rows->sum('click_count'),
            ];
        })->values();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'totals' => [
                'clicks' => $reports->sum('click_count'),
                'reports' => $reports->count(),
                'employees' => $reports->pluck('employee_id')->unique()->count(),
            ],
            'by_employee' => $byEmployee,
            'by_site' => $bySite,
            'by_day' => $byDay,
            'data' => $reports->map(fn (WorkReport $r) => [
                'id' => $r->id,
                'employee_id' => $r->employee_id,
                'employee_name' => $r->employee?->name,
                'unique_id' => $r->employee?->unique_id,
                'site_id' => $r->site_id,
                'site_name' => $r->site?->name,
                'keyword' => $r->keyword?->keyword,
                'work_date' => $r->work_date?->toDateString(),
                'click_count' => $r->click_count,
                'notes' => $r->notes,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
            ]),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $request->merge(['employee_id' => $request->user()->id]);

        return $this->index($request);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Keyword;
use App\Models\User;
use App\Models\WorkAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();

        $query = WorkAssignment::query()
            ->with(['employee', 'site', 'keyword', 'scheduledBy', 'report'])
            ->whereDate('work_date', $date)
            ->orderBy('employee_id');

        if ($employeeId = $request->integer('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($siteId = $request->integer('site_id')) {
            $query->where('site_id', $siteId);
        }

        $assignments = $query->get();

        $joined = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->pluck('id');

        $scheduledIds = $assignments->pluck('employee_id')->unique();
        $unscheduled = User::query()
            ->whereIn('id', $joined->diff($scheduledIds))
            ->orderBy('name')
            ->get(['id', 'unique_id', 'name']);

        return response()->json([
            'date' => $date,
            'data' => $assignments->map(fn (WorkAssignment $a) => $this->serialize($a)),
            'unscheduled' => $unscheduled,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.site_id' => ['required', 'exists:sites,id'],
            'items.*.keyword_id' => ['required', 'exists:keywords,id'],
            'items.*.target_clicks' => ['nullable', 'integer', 'min:0'],
            'work_date' => ['nullable', 'date'],
        ]);

        $date = $data['work_date'] ?? now()->toDateString();
        $created = [];

        foreach ($data['items'] as $item) {
            $keyword = Keyword::findOrFail($item['keyword_id']);
            if ((int) $keyword->site_id !== (int) $item['site_id']) {
                throw ValidationException::withMessages([
                    'items' => ['Keyword does not belong to the selected site.'],
                ]);
            }

            $assignment = WorkAssignment::query()->updateOrCreate(
                [
                    'employee_id' => $data['employee_id'],
                    'site_id' => $item['site_id'],
                    'keyword_id' => $item['keyword_id'],
                    'work_date' => $date,
                ],
                [
                    'target_clicks' => $item['target_clicks'] ?? null,
                    'scheduled_by' => $request->user()->id,
                    'is_auto_copied' => false,
                ]
            );

            $assignment->load(['employee', 'site', 'keyword', 'scheduledBy', 'report']);
            $created[] = $this->serialize($assignment);
        }

        return response()->json(['data' => $created], 201);
    }

    public function destroy(WorkAssignment $assignment): JsonResponse
    {
        if ($assignment->report) {
            return response()->json([
                'message' => 'Cannot delete an assignment that already has a click report.',
            ], 422);
        }

        $assignment->delete();

        return response()->json(['message' => 'Assignment removed.']);
    }

    public function copyYesterday(Request $request): JsonResponse
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();
        $copied = $this->copyForDate(Carbon::parse($date), $request->user()->id);

        return response()->json([
            'message' => "Copied {$copied} assignment(s) from the previous work day.",
            'copied' => $copied,
        ]);
    }

    public function copyForDate(Carbon $date, ?int $scheduledBy = null): int
    {
        $yesterday = $date->copy()->subDay()->toDateString();
        $today = $date->toDateString();
        $copied = 0;

        $employees = User::query()
            ->role('employee')
            ->where('status', EmployeeStatus::Joined)
            ->get();

        foreach ($employees as $employee) {
            $hasToday = WorkAssignment::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $today)
                ->exists();

            if ($hasToday) {
                continue;
            }

            $previous = WorkAssignment::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $yesterday)
                ->get();

            foreach ($previous as $row) {
                WorkAssignment::query()->firstOrCreate(
                    [
                        'employee_id' => $row->employee_id,
                        'site_id' => $row->site_id,
                        'keyword_id' => $row->keyword_id,
                        'work_date' => $today,
                    ],
                    [
                        'target_clicks' => $row->target_clicks,
                        'scheduled_by' => $scheduledBy ?? $row->scheduled_by,
                        'is_auto_copied' => true,
                    ]
                );
                $copied++;
            }
        }

        return $copied;
    }

    public function serialize(WorkAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'employee' => [
                'id' => $assignment->employee?->id,
                'name' => $assignment->employee?->name,
                'unique_id' => $assignment->employee?->unique_id,
            ],
            'site_id' => $assignment->site_id,
            'site' => [
                'id' => $assignment->site?->id,
                'name' => $assignment->site?->name,
                'url' => $assignment->site?->url,
            ],
            'keyword_id' => $assignment->keyword_id,
            'keyword' => $assignment->keyword?->keyword,
            'work_date' => $assignment->work_date?->toDateString(),
            'target_clicks' => $assignment->target_clicks,
            'is_auto_copied' => $assignment->is_auto_copied,
            'scheduled_by' => $assignment->scheduledBy?->name,
            'report' => $assignment->report ? [
                'id' => $assignment->report->id,
                'click_count' => $assignment->report->click_count,
                'submitted_at' => $assignment->report->submitted_at?->toIso8601String(),
            ] : null,
        ];
    }
}

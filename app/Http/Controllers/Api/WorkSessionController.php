<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\WorkAssignment;
use App\Models\WorkReport;
use App\Models\WorkSession;
use App\Services\WorkSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkSessionController extends Controller
{
    public function __construct(private WorkSessionService $sessions) {}

    public function show(Request $request): JsonResponse
    {
        $this->sessions->completeDueForUser($request->user());

        return $this->payload($request->user());
    }

    public function start(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $running = WorkSession::query()
                ->where('employee_id', $user->id)
                ->where('status', 'running')
                ->lockForUpdate()
                ->first();

            if ($running) {
                if ($running->isDue()) {
                    $this->sessions->awardAndFinish($running);
                } else {
                    throw ValidationException::withMessages([
                        'session' => ['A work session is already running.'],
                    ]);
                }
            }

            $assignments = WorkAssignment::query()
                ->with('site')
                ->where('employee_id', $user->id)
                ->whereDate('work_date', now()->toDateString())
                ->orderBy('id')
                ->get();

            if ($assignments->isEmpty()) {
                throw ValidationException::withMessages([
                    'session' => ['No sites assigned today. Ask your manager to schedule work first.'],
                ]);
            }

            $sites = $assignments
                ->unique('site_id')
                ->values()
                ->map(fn (WorkAssignment $assignment) => [
                    'site_id' => $assignment->site_id,
                    'site_name' => $assignment->site?->name,
                    'assignment_id' => $assignment->id,
                ]);

            $minutes = AppSetting::sessionMinutes();
            $started = now();

            WorkSession::query()->create([
                'employee_id' => $user->id,
                'duration_seconds' => $minutes * 60,
                'started_at' => $started,
                'ends_at' => $started->copy()->addMinutes($minutes),
                'status' => 'running',
                'site_count' => $sites->count(),
                'clicks_awarded' => 0,
                'sites' => $sites->all(),
            ]);
        });

        return $this->payload($user);
    }

    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = $this->sessions->completeDueForUser($user);

        if ($session && $session->status === 'running') {
            throw ValidationException::withMessages([
                'session' => ['The timer is still running.'],
            ]);
        }

        return $this->payload($user);
    }

    private function payload(User $user): JsonResponse
    {
        $current = WorkSession::query()
            ->where('employee_id', $user->id)
            ->where('status', 'running')
            ->latest('id')
            ->first();

        $logs = WorkSession::query()
            ->where('employee_id', $user->id)
            ->orderByDesc('started_at')
            ->limit(60)
            ->get();

        $todayClicks = (int) WorkReport::query()
            ->where('employee_id', $user->id)
            ->whereDate('work_date', now()->toDateString())
            ->sum('click_count');

        $todaySessions = $logs
            ->filter(fn (WorkSession $session) => $session->started_at?->isToday())
            ->count();

        return response()->json([
            'session_minutes' => AppSetting::sessionMinutes(),
            'today_clicks' => $todayClicks,
            'today_sessions' => $todaySessions,
            'current' => $current ? $this->serialize($current) : null,
            'logs' => $logs->map(fn (WorkSession $session) => $this->serialize($session))->values(),
        ]);
    }

    private function serialize(WorkSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'duration_seconds' => $session->duration_seconds,
            'remaining_seconds' => $session->remainingSeconds(),
            'started_at' => $session->started_at?->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'finished_at' => $session->finished_at?->toIso8601String(),
            'site_count' => $session->site_count,
            'clicks_awarded' => $session->clicks_awarded,
            'sites' => $session->sites ?? [],
        ];
    }
}
